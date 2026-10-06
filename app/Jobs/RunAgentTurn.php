<?php

namespace App\Jobs;

use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Agent\ScratchCopy;
use App\Agent\Workspace;
use App\Models\AgentEvent;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\ApiConnection;
use App\Models\Setting;
use App\Models\System;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * One agent turn: Claude Code, headless, in the ticket's workspace (CONCEPT.md §10).
 * Runs on the agents queue, so in the agent container, the only one with the CLI and
 * with the systems mounted read-only. Each step goes to agent_events as it happens, so
 * the ticket page can show the agent at work.
 */
class RunAgentTurn implements ShouldQueue
{
    use Queueable;

    /** Turns spend money: a failed one is not tried again by itself. */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public AgentTurn $turn)
    {
        $this->onQueue('agents');
    }

    public function handle(Workspace $workspace, ClaudeRun $runner): void
    {
        $turn = $this->turn->fresh();
        if ($turn === null || $turn->state !== AgentTurn::QUEUED) {
            return;
        }

        $session = $turn->session;
        $ticket = $session->ticket()->with('space')->first();
        $turn->update(['state' => AgentTurn::RUNNING, 'started_at' => now()]);

        try {
            $first = $session->turns()->where('id', '<', $turn->id)->doesntExist();
            if ($turn->kind === 'analysis' || $first) {
                $this->event($turn, 'tool', 'gathering the ticket, its attachments and the casebook');
                $skipped = $workspace->prepare($ticket);
                foreach ($skipped as $note) {
                    $this->event($turn, 'text', "Skipped: {$note}");
                }
            }

            $systems = $workspace->systems($ticket);
            $root = Workspace::path($ticket);

            // A patch turn works on a copy of its system, made fresh at the system's commit now.
            $copy = null;
            if ($turn->kind === 'patch') {
                $system = System::find($turn->patch_meta['system_id'] ?? null) ?? throw new \RuntimeException('The system for this patch is gone.');
                $copy = ScratchCopy::path($ticket, $system);
                $this->event($turn, 'tool', "copying {$system->name} to patch/{$system->name}");
                $base = app(ScratchCopy::class)->prepare($system, $copy);
                $turn->update([
                    'prompt' => Instructions::patchRequest($ticket->key, $system->name, 'patch/'.$system->name, $base),
                    'patch_meta' => ['system_id' => $system->id, 'system' => $system->name, 'base' => $base],
                ]);
            }

            // The systems' APIs, through the tool's MCP server, which holds the secrets.
            $hasApis = $turn->kind !== 'patch' && ApiConnection::whereIn('system_id', $systems->pluck('id'))->exists();
            $command = ClaudeRun::command(
                (float) config('agent.turn_budget_usd'),
                write: $turn->kind === 'patch',
                mcpTools: $hasApis ? ['mcp__ticket-worker__list_apis', 'mcp__ticket-worker__call_api'] : [],
            );
            if ($hasApis) {
                $command = [...$command, '--mcp-config', json_encode(['mcpServers' => ['ticket-worker' => [
                    'type' => 'stdio', 'command' => PHP_BINARY, 'args' => [base_path('artisan'), 'agent:tools', (string) $turn->id],
                ]]], JSON_UNESCAPED_SLASHES)];
            }
            $command = $first
                ? [...$command, '--session-id', $session->claude_session_id, '--append-system-prompt', Instructions::system($session, $systems)]
                : [...$command, '--resume', $session->claude_session_id];
            if (in_array($turn->kind, ['analysis', 'proposal'], true)) {
                $command = [...$command, '--json-schema', json_encode(Instructions::proposalSchema())];
            } elseif ($turn->kind === 'case') {
                $command = [...$command, '--json-schema', json_encode(Instructions::caseSchema())];
            } elseif ($turn->kind === 'patch') {
                $command = [...$command, '--json-schema', json_encode(Instructions::patchSchema())];
            }
            // A patch turn may write, and restricted mode lets it write in every folder it is
            // given: so it gets only the workspace, where its copy is. Without Docker's
            // read-only mounts, that is what keeps the systems themselves untouched.
            if ($turn->kind !== 'patch') {
                foreach ($systems as $system) {
                    $command = [...$command, '--add-dir', $system->path()];
                }
            }

            $shorten = [$root.'/' => ''];
            foreach ($systems as $system) {
                $shorten[$system->path()] = $system->name.'/';
            }

            $run = $runner->run(
                // The answer rules go along with every turn but the casebook draft, unsaved: the
                // log shows what you asked, not the house rules.
                $command, $root, $turn->kind === 'case' ? $turn->prompt : $turn->prompt.Instructions::answerRules(), $shorten,
                // What the agent says is kept whole, commands and all; tool steps are one-liners.
                fn (string $type, string $summary) => $this->event($turn, $type, Str::limit($summary, $type === 'text' ? 15000 : 4000)),
                fn () => (bool) $turn->fresh()->stop_requested,
                $this->timeout,
            );

            $this->finish($turn, $run['result'], $run['errors'], $copy, $systems);
        } catch (Throwable $e) {
            $turn->update(['state' => AgentTurn::FAILED, 'error' => Str::limit($e->getMessage(), 2000), 'finished_at' => now()]);
            $this->event($turn, 'error', Str::limit($e->getMessage(), 500));
        }
    }

    private function finish(AgentTurn $turn, ?array $result, string $errors, ?string $copy, Collection $systems): void
    {
        $turn->refresh();
        $cost = isset($result['total_cost_usd']) ? (float) $result['total_cost_usd'] : null;

        if ($turn->stop_requested) {
            $state = AgentTurn::STOPPED;
        } elseif ($result === null || ($result['is_error'] ?? false)) {
            $state = AgentTurn::FAILED;
        } else {
            $state = AgentTurn::DONE;
        }

        $proposal = null;
        if ($state === AgentTurn::DONE && in_array($turn->kind, ['analysis', 'proposal'], true)) {
            $proposal = $result['structured_output'] ?? self::jsonFrom((string) ($result['result'] ?? ''));
        }

        $caseDraft = $state === AgentTurn::DONE && $turn->kind === 'case'
            ? ($result['structured_output'] ?? self::jsonFrom((string) ($result['result'] ?? '')))
            : null;

        $patch = null;
        $patchMeta = $turn->patch_meta;
        if ($state === AgentTurn::DONE && $turn->kind === 'patch') {
            $answer = $result['structured_output'] ?? self::jsonFrom((string) ($result['result'] ?? '')) ?? [];
            $message = trim((string) ($answer['commit_message'] ?? '')) ?: "{$turn->session->ticket->key}: fix";
            $built = app(ScratchCopy::class)->build($copy, $message);
            $patch = $built['patch'] ?? null;
            $patchMeta = [
                ...($patchMeta ?? []),
                'subject' => strtok($message, "\n"),
                'summary' => $answer['summary'] ?? null,
                'how_to_test' => $answer['how_to_test'] ?? null,
                'risks' => $answer['risks'] ?? null,
                'files' => $built['files'] ?? [],
            ];
            if ($built === null) {
                $this->event($turn, 'text', 'No patch: the agent changed nothing. '.($answer['summary'] ?? ''));
            }
        }

        $turn->update([
            'state' => $state,
            'proposal' => $proposal,
            'case_draft' => $caseDraft,
            'patch' => $patch,
            'patch_meta' => $patchMeta,
            'answer' => $result['result'] ?? null,
            'error' => $state === AgentTurn::FAILED ? Str::limit(($result['result'] ?? '') ?: trim($errors) ?: 'The agent stopped without an answer.', 2000) : null,
            'cost_usd' => $cost,
            ...ClaudeRun::tokens($result),
            'duration_ms' => $result['duration_ms'] ?? null,
            'finished_at' => now(),
        ]);

        if ($cost !== null) {
            $turn->session->increment('cost_usd', $cost);
        }
        if ($state === AgentTurn::FAILED) {
            $this->event($turn, 'error', $turn->error);
        }

        // A code fix gets its patch without being asked, unless that is switched off.
        if ($proposal !== null && ($proposal['fix_kind'] ?? null) === 'code' && Setting::autoPatch()
            && ($system = self::systemFor($proposal, $systems)) !== null) {
            self::queuePatch($turn->session, $system);
        }
    }

    /** The system a proposal is about, by name; the only one when there is just one. */
    public static function systemFor(?array $proposal, Collection $systems): ?System
    {
        $name = mb_strtolower(trim((string) ($proposal['system'] ?? '')));

        return $systems->first(fn (System $system) => mb_strtolower($system->name) === $name)
            ?? ($systems->count() === 1 ? $systems->first() : null);
    }

    public static function queuePatch(AgentSession $session, System $system): AgentTurn
    {
        $turn = AgentTurn::create([
            'agent_session_id' => $session->id, 'kind' => 'patch', 'prompt' => '',
            'patch_meta' => ['system_id' => $system->id, 'system' => $system->name],
        ]);
        self::dispatch($turn);

        return $turn;
    }

    /** The queue gave up on it (a worker died, say): the page must not wait forever. */
    public function failed(?Throwable $e): void
    {
        $turn = $this->turn->fresh();
        if ($turn !== null && in_array($turn->state, [AgentTurn::QUEUED, AgentTurn::RUNNING], true)) {
            $turn->update(['state' => AgentTurn::FAILED, 'error' => 'Interrupted: the agent container stopped. Ask again.', 'finished_at' => now()]);
        }
    }

    /** A proposal written as JSON in the answer, fenced or not. */
    private static function jsonFrom(string $text): ?array
    {
        if (preg_match('/\{.*\}/s', $text, $match)) {
            $decoded = json_decode($match[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function event(AgentTurn $turn, string $type, string $summary): void
    {
        AgentEvent::create(['agent_turn_id' => $turn->id, 'type' => $type, 'summary' => $summary]);
    }
}
