<?php

namespace App\Jobs;

use App\Agent\Budget;
use App\Agent\ApiToolServer;
use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Agent\ScratchCopy;
use App\Agent\Workspace;
use App\Models\AgentEvent;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\System;
use App\Models\User;
use App\Settings\SpaceSettings;
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
            // Fresh every turn: a comment added in Jira, or a branch pushed for the ticket since
            // the chat began, is there for the next question (attachments already here stay).
            $this->event($turn, 'tool', 'gathering the ticket, its attachments and the casebook');
            try {
                $skipped = $workspace->prepare($ticket);
            } catch (Throwable $e) {
                // A follow-up has the workspace of the turn before: a Jira hiccup is no reason to fail it.
                if ($first) {
                    throw $e;
                }
                $skipped = ['the ticket could not be refreshed ('.Str::limit($e->getMessage(), 200).'); the workspace is as it was'];
            }
            foreach ($skipped as $note) {
                $this->event($turn, 'text', "Skipped: {$note}");
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
                    'prompt' => Instructions::patchRequest($ticket->key, $system->name, 'patch/'.$system->name, $base, $turn->note),
                    'patch_meta' => ['system_id' => $system->id, 'system' => $system->name, 'base' => $base],
                ]);
            }

            // A build works on the ticket's branch: the one published before, or a new one from its base.
            if ($turn->kind === 'build') {
                $meta = $turn->patch_meta ?? [];
                $system = System::find($meta['system_id'] ?? null) ?? throw new \RuntimeException('The system for this build is gone.');
                $copy = ScratchCopy::buildPath($ticket, $system);
                $this->event($turn, 'tool', "copying {$system->name} to build/{$system->name} on {$meta['branch']}");
                $prepared = app(ScratchCopy::class)->prepareBranch($system, $copy, $meta['branch'], $meta['base']);
                $turn->update([
                    'prompt' => Instructions::buildRequest($ticket, $system->name, 'build/'.$system->name, $meta['branch'], $meta['base'], $prepared['continued'], $turn->note,
                        $system->load('checks')->runnableChecks()->pluck('name')->all()),
                    'patch_meta' => [...$meta, 'system' => $system->name, 'base_sha' => $prepared['base'], 'continued' => $prepared['continued']],
                ]);
            }

            // The systems' APIs, through the tool's MCP server, which holds the secrets.
            $writes = in_array($turn->kind, ['patch', 'build'], true);
            // A patch gets no tools; a build its system's checks, when it has any; the rest the data tools.
            $tools = match (true) {
                $turn->kind === 'build' => ApiToolServer::buildToolNames(System::with('checks')->find($turn->patch_meta['system_id'] ?? null)),
                $writes => [],
                default => ApiToolServer::toolNamesFor($systems),
            };
            $hasApis = $tools !== [];
            $settings = SpaceSettings::for($ticket->space);
            // A question in the chat on the lighter model; digging deeper, patches and cases on the full one.
            // A case draft writes up what the conversation found: no investigation, so the lighter model.
            $chat = in_array($turn->kind, ['message', 'case', 'refine', 'ticket'], true);
            $model = $settings->get($chat ? 'chat_model' : 'model');
            $turn->update(['model' => $model]);
            $command = ClaudeRun::command(
                (float) $settings->get('turn_budget_usd'),
                write: $writes,
                mcpTools: $tools,
                model: $model,
                effort: $settings->get($chat ? 'chat_effort' : 'effort'),
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
            } elseif ($turn->kind === 'build') {
                $command = [...$command, '--json-schema', json_encode(Instructions::buildSchema())];
            } elseif ($turn->kind === 'refine') {
                $command = [...$command, '--json-schema', json_encode(Instructions::refineSchema())];
            } elseif ($turn->kind === 'ticket') {
                $command = [...$command, '--json-schema', json_encode(Instructions::ticketDraftSchema())];
            }
            // A patch turn may write, and restricted mode lets it write in every folder it is
            // given: so it gets only the workspace, where its copy is. Without Docker's
            // read-only mounts, that is what keeps the systems themselves untouched.
            if (! $writes) {
                foreach ($systems->filter->hasCode() as $system) {
                    $command = [...$command, '--add-dir', $system->path()];
                }
            }

            $shorten = [$root.'/' => ''];
            foreach ($systems->filter->hasCode() as $system) {
                $shorten[$system->path()] = $system->name.'/';
            }

            $run = $runner->run(
                // The answer rules go along with every turn but the casebook draft, unsaved: the
                // log shows what you asked, not the house rules.
                $command, $root, $turn->kind === 'case' ? $turn->prompt : $turn->prompt.Instructions::answerRules($ticket->space), $shorten,
                // What the agent says is kept whole, commands and all; tool steps are one-liners.
                fn (string $type, string $summary) => $this->event($turn, $type, Str::limit($summary, $type === 'text' ? 15000 : 4000)),
                fn () => (bool) $turn->fresh()->stop_requested,
                $this->timeout,
                Instructions::credentials($turn->user),
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
        // A resumed conversation reports its running total since the first turn: this turn
        // cost the difference from the last turn's total.
        $total = isset($result['total_cost_usd']) ? (float) $result['total_cost_usd'] : null;
        $before = (float) AgentTurn::query()->where('agent_session_id', $turn->agent_session_id)->where('id', '<', $turn->id)
            ->whereNotNull('claude_total_usd')->orderByDesc('id')->value('claude_total_usd');
        $cost = $total === null ? null : round($total >= $before ? $total - $before : $total, 4);

        if ($turn->stop_requested) {
            $state = AgentTurn::STOPPED;
        } elseif ($result === null || (($result['is_error'] ?? false) && ! is_array($result['structured_output'] ?? null) && ! self::editedBeforeTheEnd($turn, $copy))) {
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

        $ticketDraft = $state === AgentTurn::DONE && $turn->kind === 'ticket'
            ? ($result['structured_output'] ?? self::jsonFrom((string) ($result['result'] ?? '')))
            : null;

        $refinement = $state === AgentTurn::DONE && $turn->kind === 'refine'
            ? ($result['structured_output'] ?? self::jsonFrom((string) ($result['result'] ?? '')))
            : null;

        $patch = null;
        $patchMeta = $turn->patch_meta;
        if ($state === AgentTurn::DONE && in_array($turn->kind, ['patch', 'build'], true)) {
            $answer = $result['structured_output'] ?? self::jsonFrom((string) ($result['result'] ?? '')) ?? [];
            $message = trim((string) ($answer['commit_message'] ?? '')) ?: "{$turn->session->ticket->key}: ".($turn->kind === 'build' ? 'build' : 'fix');
            $built = app(ScratchCopy::class)->build($copy, $message);
            $patch = $built['patch'] ?? null;
            $patchMeta = [
                ...($patchMeta ?? []),
                'subject' => strtok($message, "\n"),
                'summary' => $answer['summary'] ?? null,
                'how_to_test' => $answer['how_to_test'] ?? null,
                'risks' => ($result['is_error'] ?? false) && $answer === []
                    ? 'Unfinished: the agent ran out of its turn budget while still editing. Read every change before you use it.'
                    : ($answer['risks'] ?? null),
                'files' => $built['files'] ?? [],
                'guide_kind' => $answer['guide_kind'] ?? null,
                'guide' => $answer['guide'] ?? null,
            ];
            if ($built === null) {
                $this->event($turn, 'text', 'No '.($turn->kind === 'build' ? 'new commit' : 'patch').': the agent changed nothing. '.($answer['summary'] ?? ''));
            }
            // On the server, for your clones to fetch: by the worker, which may write the systems.
            if ($built !== null && $turn->kind === 'build') {
                $publish = true;
                // What it hands in, checked once more: the result goes with the build.
                $system = System::with('checks')->find($patchMeta['system_id'] ?? null);
                if ($system?->runnableChecks()->isNotEmpty()) {
                    $this->event($turn, 'tool', 'running the checks on what it handed in');
                    try {
                        $results = app(\App\Agent\Checks::class)->run($system, $copy);
                        $patchMeta['checks'] = collect($results)->map(fn ($r) => [...$r, 'output' => $r['ok'] ? '' : Str::limit($r['output'], 4000)])->all();
                        $this->event($turn, 'tool', 'checks: '.\App\Agent\Checks::line($results));
                    } catch (Throwable $e) {
                        $patchMeta['checks_error'] = Str::limit($e->getMessage(), 500);
                    }
                }
            }
        }

        $turn->update([
            'state' => $state,
            'proposal' => $proposal,
            'case_draft' => $caseDraft,
            'refinement' => $refinement,
            'ticket_draft' => $ticketDraft,
            'patch' => $patch,
            'patch_meta' => $patchMeta,
            'answer' => $result['result'] ?? null,
            'error' => $state === AgentTurn::FAILED ? Str::limit(($result['result'] ?? '') ?: trim($errors) ?: 'The agent stopped without an answer.', 2000) : null,
            'cost_usd' => $cost,
            'claude_total_usd' => $total,
            ...ClaudeRun::tokens($result),
            'duration_ms' => $result['duration_ms'] ?? null,
            'finished_at' => now(),
        ]);

        if ($cost !== null) {
            $turn->session->increment('cost_usd', $cost);
        }
        if ($publish ?? false) {
            PublishBranch::dispatch($turn->fresh());
        }
        if ($state === AgentTurn::FAILED) {
            $this->event($turn, 'error', $turn->error);
        } elseif ($state === AgentTurn::DONE && ($result['is_error'] ?? false)) {
            $this->event($turn, 'text', in_array($turn->kind, ['patch', 'build'], true) && ! is_array($result['structured_output'] ?? null)
                ? 'Ended on '.self::why($result, $cost).' while still editing: the patch holds what it changed until then. Check it before you use it.'
                : 'Ended on '.self::why($result, $cost).' just after answering: the answer is kept.');
        }

        // A code fix gets its patch without being asked, unless that is switched off.
        if ($proposal !== null && ($proposal['fix_kind'] ?? null) === 'code' && SpaceSettings::for($turn->session->ticket->space)->get('auto_patch')
            && Budget::allows($turn->session->ticket->space, $turn->user)
            && ($system = self::systemFor($proposal, $systems)) !== null) {
            self::queuePatch($turn->session, $system, $turn->user);
        }
    }

    /** The system a proposal is about, by name; the only one when there is just one. */
    public static function systemFor(?array $proposal, Collection $systems): ?System
    {
        $name = mb_strtolower(trim((string) ($proposal['system'] ?? '')));
        // A patch needs code to change.
        $systems = $systems->filter->hasCode()->values();

        // The agent writes "boss (billing)" as readily as "boss": a name standing on its own
        // counts, the longest first, so "ng-boss" is not taken for "boss".
        $named = $systems->first(fn (System $system) => mb_strtolower($system->name) === $name)
            ?? $systems->sortByDesc(fn (System $system) => mb_strlen($system->name))
                ->first(fn (System $system) => preg_match('/(?<![\w-])'.preg_quote(mb_strtolower($system->name), '/').'(?![\w-])/u', $name) === 1);

        return $named ?? ($systems->count() === 1 ? $systems->first() : null);
    }

    /**
     * Build the ticket on its branch in one system. The branch is the one built before, or
     * named after the ticket; its base the one it had, the one asked for, the space's, or the
     * system's own branch.
     */
    public static function queueBuild(AgentSession $session, System $system, ?string $base = null, ?string $request = null, ?User $user = null): AgentTurn
    {
        $ticket = $session->ticket;
        $existing = \App\Models\TicketBranch::query()->where(['ticket_id' => $ticket->id, 'system_id' => $system->id])->first();
        $turn = AgentTurn::create([
            'agent_session_id' => $session->id, 'kind' => 'build', 'prompt' => '', 'note' => $request, 'user_id' => $user?->id ?? auth()->id(),
            'patch_meta' => [
                'system_id' => $system->id, 'system' => $system->name,
                // The branch built on before; else one named after the ticket already in the
                // system (yours, ODOSSER-898); else a new one.
                'branch' => $existing?->name ?? (\App\Agent\Workspace::ticketBranches($system, $ticket->key)[0] ?? $ticket->branchName()),
                'base' => $existing?->base ?? (filled($base) ? $base : (SpaceSettings::for($ticket->space)->get('base_branch') ?: $system->branch)),
            ],
        ]);
        self::dispatch($turn);

        return $turn;
    }

    /** @param  ?string  $request  what you asked for in your own words, when you asked in a message */
    public static function queuePatch(AgentSession $session, System $system, ?User $user = null, ?string $request = null): AgentTurn
    {
        $turn = AgentTurn::create([
            'agent_session_id' => $session->id, 'kind' => 'patch', 'prompt' => '', 'note' => $request, 'user_id' => $user?->id ?? auth()->id(),
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

    /**
     * A patch turn that ran out of budget (or steps) after it had changed files: the patch is
     * made from the copy, not from its answer, so what it did is not lost. It says so.
     */
    private static function editedBeforeTheEnd(AgentTurn $turn, ?string $copy): bool
    {
        return in_array($turn->kind, ['patch', 'build'], true) && $copy !== null && $turn->events()->where('type', 'tool')
            ->where(fn ($query) => $query->where('summary', 'like', 'edit %')->orWhere('summary', 'like', 'write %'))->exists();
    }

    /** Why a run ended as an error, in words. */
    private static function why(array $result, ?float $cost): string
    {
        return match ($result['subtype'] ?? null) {
            'error_max_budget_usd' => 'the turn budget ($'.number_format((float) $cost, 2).')',
            'error_max_turns' => 'the step limit',
            default => 'an error',
        };
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
