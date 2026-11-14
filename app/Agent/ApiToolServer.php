<?php

namespace App\Agent;

use App\Models\AgentEvent;
use App\Models\AgentTurn;
use App\Models\ApiConnection;
use App\Models\DbConnection;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * The tools the agent calls APIs with, as an MCP server on stdin and stdout (one JSON-RPC
 * message per line). Claude Code starts it for a turn (`php artisan agent:tools <turn>`);
 * it runs in the tool's own process, so the agent asks for a call and never holds a
 * secret. Every call shows in the ticket's log like any other step.
 */
class ApiToolServer
{
    /** Enough to check a few claims; a loop that keeps calling is stopped. */
    public const MAX_CALLS = 40;

    private int $calls = 0;

    /**
     * @param  Collection<int, ApiConnection>  $apis
     * @param  (\Closure(string): void)|null  $log  where each call is written down
     */
    /** Check runs in one build: each takes minutes, so a few are enough. */
    public const MAX_CHECK_RUNS = 6;

    private int $checkRuns = 0;

    /**
     * @param  (\Closure(): array)|null  $runChecks  a build's checks, when it has any
     * @param  list<string>  $checkNames
     */
    public function __construct(
        private readonly Collection $apis,
        private readonly ?\Closure $log = null,
        private readonly ?Collection $dbs = null,
        private readonly ?\Closure $runChecks = null,
        private readonly array $checkNames = [],
        private readonly ?AgentTurn $turn = null,
    ) {}

    /**
     * The tool names a turn on these systems should be allowed, as Claude Code calls them;
     * none when the systems have neither APIs nor databases.
     *
     * @return list<string>
     */
    public static function toolNamesFor(iterable $systems): array
    {
        $ids = collect($systems)->pluck('id');
        $names = [];
        if (ApiConnection::whereIn('system_id', $ids)->exists()) {
            $names = ['mcp__ticket-worker__list_apis', 'mcp__ticket-worker__call_api'];
        }
        if (DbConnection::whereIn('system_id', $ids)->exists()) {
            $names = [...$names, 'mcp__ticket-worker__list_databases', 'mcp__ticket-worker__run_query'];
        }

        return $names;
    }

    public static function forTurn(AgentTurn $turn, iterable $systems): self
    {
        $log = fn (string $line) => AgentEvent::create(['agent_turn_id' => $turn->id, 'type' => 'tool', 'summary' => $line]);

        // A build gets its system's checks, and nothing else: it writes code, it does not look up data.
        if ($turn->kind === 'build') {
            $system = \App\Models\System::with('checks')->find($turn->patch_meta['system_id'] ?? null);
            $checks = $system?->runnableChecks() ?? collect();
            $copy = $system ? ScratchCopy::buildPath($turn->session->ticket, $system) : null;

            return new self(collect(), $log, collect(), $checks->isEmpty() ? null : fn () => app(Checks::class)->run($system, $copy), $checks->pluck('name')->all());
        }

        return new self(self::apisOf($systems), $log, self::dbsOf($systems), turn: $turn);
    }

    /** The tool names a build on this system is allowed. */
    public static function buildToolNames(?\App\Models\System $system): array
    {
        return $system !== null && $system->runnableChecks()->isNotEmpty() ? ['mcp__ticket-worker__run_checks'] : [];
    }

    /** For a quick scan: the calls go into the ticket's scan trail. */
    public static function forQuickScan(Ticket $ticket, iterable $systems): self
    {
        return new self(self::apisOf($systems), fn (string $line) => Ticket::addQuickStep($ticket->id, $line), self::dbsOf($systems));
    }

    private static function dbsOf(iterable $systems): Collection
    {
        return DbConnection::query()->with(['system', 'server'])->whereIn('system_id', collect($systems)->pluck('id'))->orderBy('name')->get();
    }

    private static function apisOf(iterable $systems): Collection
    {
        return ApiConnection::query()->with('system')->whereIn('system_id', collect($systems)->pluck('id'))->orderBy('name')->get();
    }

    /** Answer one message; null for a notification, which gets no answer. */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? '';
        if ($id === null) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => $message['params']['protocolVersion'] ?? '2025-06-18',
                    'capabilities' => ['tools' => (object) []],
                    'serverInfo' => ['name' => 'ticket-worker', 'version' => '1'],
                ],
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->tools()],
                'tools/call' => $this->call((string) ($message['params']['name'] ?? ''), (array) ($message['params']['arguments'] ?? [])),
                default => throw new \BadMethodCallException("Unknown method {$method}"),
            };
        } catch (\BadMethodCallException $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => $e->getMessage()]];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function tools(): array
    {
        if ($this->runChecks !== null) {
            return [[
                'name' => 'run_checks',
                'description' => 'Run this system\'s checks ('.implode(', ', $this->checkNames).') on your copy as it is now, '
                    .'edits included. Each passes or fails, with the end of its output. They take minutes: run them when you think '
                    .'you are done, fix what fails, and run them again. At most '.self::MAX_CHECK_RUNS.' times.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ]];
        }

        $names = $this->apis->map(fn (ApiConnection $api) => $this->key($api))->values()->all();
        $writable = $this->apis->contains(fn (ApiConnection $api) => $api->access === 'write');
        $dbNames = ($this->dbs ?? collect())->map(fn (DbConnection $db) => $db->system->name.'/'.$db->name)->values()->all();
        $databaseTools = $dbNames === [] ? [] : [
            [
                'name' => 'list_databases',
                'description' => 'The databases of this ticket\'s systems you may query, read-only, with notes on what they hold.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'run_query',
                'description' => 'Run one read-only query (SELECT, WITH, SHOW, DESCRIBE or EXPLAIN) on one of those databases. '
                    .'The tool signs in for you. At most '.DbConnection::MAX_ROWS.' rows come back, one JSON object per line: '
                    .'filter with WHERE and ask for the columns you need.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['database', 'sql'],
                    'properties' => [
                        'database' => ['type' => 'string', 'enum' => $dbNames, 'description' => 'system/name, from list_databases.'],
                        'sql' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        return [...$databaseTools, ...[
            [
                'name' => 'list_apis',
                'description' => 'The APIs of this ticket\'s systems that you may call, with their base address and notes on what they offer.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'call_api',
                'description' => 'Call one of those APIs. The tool signs in for you; you never see or need a token. '
                    .'GET reads; POST is for queries where an API allows it. '
                    .($writable ? 'On an API that allows changes (list_apis says so), a POST, PUT, PATCH or DELETE does not run: it becomes a proposal '
                        .'the engineer runs or refuses, and you hear which on your next turn. Give the reason. Propose a change only when the engineer '
                        .'asked for it or it is the fix, one at a time, and read the record first. ' : '')
                    .'The answer is the status and the body.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['api', 'path'],
                    'properties' => [
                        'api' => ['type' => 'string', 'enum' => $names ?: ['none'], 'description' => 'system/name, from list_apis.'],
                        'method' => ['type' => 'string', 'enum' => $writable ? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] : ['GET', 'POST'], 'default' => 'GET'],
                        'reason' => ['type' => 'string', 'description' => 'For a change: what it does and why, in one or two sentences, for the engineer who approves it.'],
                        'path' => ['type' => 'string', 'description' => 'Under the base address, like /finance/view/service/337800.'],
                        'query' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'boolean']]],
                        'body' => ['type' => 'object', 'description' => 'JSON body, for POST.'],
                    ],
                ],
            ],
        ]];
    }

    private function call(string $tool, array $arguments): array
    {
        if ($tool === 'run_checks' && $this->runChecks !== null) {
            if (++$this->checkRuns > self::MAX_CHECK_RUNS) {
                return $this->text('That is '.self::MAX_CHECK_RUNS.' runs: enough. Hand in what you have and say what still fails.', true);
            }
            try {
                $results = ($this->runChecks)();
            } catch (Throwable $e) {
                $this->log('checks: could not run');

                return $this->text('The checks could not run: '.$e->getMessage(), true);
            }
            $this->log('checks: '.Checks::line($results));

            return $this->text(collect($results)->map(fn ($r) => "## {$r['name']}: ".($r['ok'] ? 'passed' : "FAILED (exit {$r['exit']})")." in {$r['seconds']}s"
                .($r['ok'] ? '' : "\n\n{$r['output']}"))->implode("\n\n"));
        }

        if ($tool === 'list_databases' || $tool === 'run_query') {
            return $this->database($tool, $arguments);
        }

        if ($tool === 'list_apis') {
            $list = $this->apis->map(fn (ApiConnection $api) => [
                'api' => $this->key($api),
                'base' => $api->base_url,
                'methods' => $api->methods(),
                'changes' => $api->access === 'write' ? 'POST, PUT, PATCH and DELETE are proposals: they wait for the engineer to run them' : 'none',
                'notes' => $api->notes,
            ])->values();

            return $this->text($list->isEmpty() ? 'No APIs are set up for these systems.' : json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($tool !== 'call_api') {
            return $this->text("No tool {$tool}.", true);
        }

        $api = $this->apis->first(fn (ApiConnection $api) => $this->key($api) === ($arguments['api'] ?? null));
        if ($api === null) {
            return $this->text('No such API. list_apis shows the ones you may call.', true);
        }
        if (++$this->calls > self::MAX_CALLS) {
            return $this->text('That is '.self::MAX_CALLS.' calls in this turn: enough. Work with what you have.', true);
        }

        $method = strtoupper((string) ($arguments['method'] ?? 'GET'));
        $path = (string) ($arguments['path'] ?? '');

        // A change: held for a person, never sent from here.
        if ($api->needsApproval($method)) {
            if ($this->turn === null) {
                return $this->text('Changes can only be proposed from a chat on a ticket.', true);
            }
            try {
                $api->urlFor($path);
            } catch (InvalidArgumentException $e) {
                return $this->text($e->getMessage(), true);
            }
            $proposal = \App\Models\ApiCall::create([
                'ticket_id' => $this->turn->session->ticket_id, 'agent_turn_id' => $this->turn->id, 'api_connection_id' => $api->id,
                'method' => $method, 'path' => $path,
                'query' => (array) ($arguments['query'] ?? []) ?: null, 'body' => isset($arguments['body']) ? (array) $arguments['body'] : null,
                'reason' => isset($arguments['reason']) ? \Illuminate\Support\Str::limit((string) $arguments['reason'], 1000) : null,
            ]);
            $this->log("change proposed: {$method} {$path} on {$this->key($api)} (#{$proposal->id}), waiting for approval");

            return $this->text("Not sent: {$proposal->label()} waits for the engineer, who runs or refuses it. You hear which on your next turn. "
                .'Say in your answer what it does and why, and do not build on it as if it had run.');
        }

        try {
            $answer = $api->call($method, $path, (array) ($arguments['query'] ?? []), isset($arguments['body']) ? (array) $arguments['body'] : null);
        } catch (InvalidArgumentException $e) {
            $this->log("api {$this->key($api)} {$method} {$path}: refused");

            return $this->text($e->getMessage(), true);
        } catch (Throwable $e) {
            $this->log("api {$this->key($api)} {$method} {$path}: failed");

            return $this->text($api->redact('The call failed: '.$e->getMessage()), true);
        }

        $this->log("api {$this->key($api)} {$method} {$path} → {$answer['status']}");

        return $this->text("HTTP {$answer['status']}".($answer['type'] ? " ({$answer['type']})" : '')."\n\n{$answer['body']}"
            .($answer['truncated'] ? "\n\n[cut off: ask for less, with a filter or a page size]" : ''), $answer['status'] === 0);
    }

    private function database(string $tool, array $arguments): array
    {
        $dbs = $this->dbs ?? collect();
        if ($tool === 'list_databases') {
            return $this->text($dbs->isEmpty() ? 'No databases are set up for these systems.' : json_encode($dbs->map(fn (DbConnection $db) => [
                'database' => $db->system->name.'/'.$db->name, 'kind' => $db->kind(), 'notes' => $db->notes,
            ])->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $db = $dbs->first(fn (DbConnection $db) => $db->system->name.'/'.$db->name === ($arguments['database'] ?? null));
        if ($db === null) {
            return $this->text('No such database. list_databases shows the ones you may query.', true);
        }
        if (++$this->calls > self::MAX_CALLS) {
            return $this->text('That is '.self::MAX_CALLS.' calls in this turn: enough. Work with what you have.', true);
        }

        $sql = (string) ($arguments['sql'] ?? '');
        $shown = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $sql), 160);
        try {
            $answer = $db->runQuery($sql);
        } catch (InvalidArgumentException $e) {
            $this->log("sql {$db->system->name}/{$db->name}: refused: {$shown}");

            return $this->text($e->getMessage(), true);
        } catch (Throwable $e) {
            $this->log("sql {$db->system->name}/{$db->name}: failed: {$shown}");

            return $this->text($db->redact('The query failed: '.$e->getMessage()), true);
        }

        $this->log("sql {$db->system->name}/{$db->name}: {$shown} → {$answer['rows']} rows");

        return $this->text($answer['text']);
    }

    private function key(ApiConnection $api): string
    {
        return $api->system->name.'/'.$api->name;
    }

    private function text(string $text, bool $error = false): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $error];
    }

    private function log(string $summary): void
    {
        if ($this->log !== null) {
            ($this->log)($summary);
        }
    }
}
