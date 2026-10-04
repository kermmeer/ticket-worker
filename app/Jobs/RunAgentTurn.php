<?php

namespace App\Jobs;

use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Agent\Workspace;
use App\Models\AgentEvent;
use App\Models\AgentTurn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

            $command = ClaudeRun::command((float) config('agent.turn_budget_usd'));
            $command = $first
                ? [...$command, '--session-id', $session->claude_session_id, '--append-system-prompt', Instructions::system($session, $systems)]
                : [...$command, '--resume', $session->claude_session_id];
            if (in_array($turn->kind, ['analysis', 'proposal'], true)) {
                $command = [...$command, '--json-schema', json_encode(Instructions::proposalSchema())];
            }
            foreach ($systems as $system) {
                $command = [...$command, '--add-dir', $system->path()];
            }

            $shorten = [$root.'/' => ''];
            foreach ($systems as $system) {
                $shorten[$system->path()] = $system->name.'/';
            }

            $run = $runner->run(
                $command, $root, $turn->prompt, $shorten,
                fn (string $type, string $summary) => $this->event($turn, $type, Str::limit($summary, 4000)),
                fn () => (bool) $turn->fresh()->stop_requested,
                $this->timeout,
            );

            $this->finish($turn, $run['result'], $run['errors']);
        } catch (Throwable $e) {
            $turn->update(['state' => AgentTurn::FAILED, 'error' => Str::limit($e->getMessage(), 2000), 'finished_at' => now()]);
            $this->event($turn, 'error', Str::limit($e->getMessage(), 500));
        }
    }

    private function finish(AgentTurn $turn, ?array $result, string $errors): void
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

        $turn->update([
            'state' => $state,
            'proposal' => $proposal,
            'answer' => $result['result'] ?? null,
            'error' => $state === AgentTurn::FAILED ? Str::limit(($result['result'] ?? '') ?: trim($errors) ?: 'The agent stopped without an answer.', 2000) : null,
            'cost_usd' => $cost,
            'duration_ms' => $result['duration_ms'] ?? null,
            'finished_at' => now(),
        ]);

        if ($cost !== null) {
            $turn->session->increment('cost_usd', $cost);
        }
        if ($state === AgentTurn::FAILED) {
            $this->event($turn, 'error', $turn->error);
        }
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
