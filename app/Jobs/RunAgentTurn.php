<?php

namespace App\Jobs;

use App\Agent\Instructions;
use App\Agent\StreamReader;
use App\Agent\Workspace;
use App\Models\AgentEvent;
use App\Models\AgentTurn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
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

    public function handle(Workspace $workspace): void
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

            $command = [config('agent.claude.bin'), '-p',
                '--output-format', 'stream-json', '--verbose',
                '--model', config('agent.claude.model'), '--effort', config('agent.claude.effort'),
                // Read-only: no shell, no network, no writing; nothing that would ask anyone.
                '--restricted', '--strict-mcp-config',
                '--tools', 'Read,Grep,Glob',
                '--allowedTools', 'Read,Grep,Glob',
                '--permission-prompts', 'none',
                '--max-budget-usd', (string) config('agent.turn_budget_usd'),
            ];
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
            $reader = new StreamReader($shorten);

            // The prompt goes in on stdin: every option above takes a value, and a prompt
            // left as the last argument could be read as one of them.
            $process = Process::path($root)
                ->command($command)
                ->env(Instructions::credentials())
                ->input($turn->prompt)
                ->timeout($this->timeout)
                ->start();

            $result = null;
            $lastCheck = microtime(true);

            while ($process->running()) {
                $result = $this->record($turn, $reader->feed($process->latestOutput()), $result);

                // Stop asked for on the page: ask the CLI to end, then insist.
                if (microtime(true) - $lastCheck > 2) {
                    $lastCheck = microtime(true);
                    if ($turn->fresh()->stop_requested) {
                        // SIGINT and SIGKILL by number: the image has no pcntl constants.
                        $process->signal(2);
                        usleep(1500000);
                        if ($process->running()) {
                            $process->signal(9);
                        }
                        break;
                    }
                }
                usleep(300000);
            }

            $result = $this->record($turn, $reader->feed($process->latestOutput()), $result);
            $finished = $process->wait();
            $result = $this->record($turn, $reader->finish(), $result);

            $this->finish($turn, $result, $finished->errorOutput());
        } catch (Throwable $e) {
            $turn->update(['state' => AgentTurn::FAILED, 'error' => Str::limit($e->getMessage(), 2000), 'finished_at' => now()]);
            $this->event($turn, 'error', Str::limit($e->getMessage(), 500));
        }
    }

    /** Store the steps; hand back the result line when it came by. */
    private function record(AgentTurn $turn, array $events, ?array $result): ?array
    {
        foreach ($events as $event) {
            if ($event['type'] === 'result') {
                $result = $event['result'];
            } else {
                $this->event($turn, $event['type'], Str::limit($event['summary'], 4000));
            }
        }

        return $result;
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
