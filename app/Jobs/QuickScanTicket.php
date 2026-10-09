<?php

namespace App\Jobs;

use App\Agent\ClaudeRun;
use App\Agent\FeedQuickScans;
use App\Agent\Instructions;
use App\Agent\Workspace;
use App\Models\ApiConnection;
use App\Models\Ticket;
use App\Models\User;
use App\Settings\SpaceSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * The quick first look at a ticket (CONCEPT.md §7): what it is, where, and for a data
 * problem whether it is still there. A lighter model with a small budget, no session to
 * come back to: the analysis proper starts fresh when you press Analyse.
 */
class QuickScanTicket implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Ticket $ticket)
    {
        $this->onQueue('agents');
    }

    /** Queue one, unless one is waiting or running already. */
    public static function start(Ticket $ticket): bool
    {
        if (in_array($ticket->quick_scan_state, ['queued', 'running'], true)) {
            return false;
        }
        // Who asked runs it on their own Claude sign-in; a scan nobody asked for, on the instance's.
        $ticket->forceFill(['quick_scan_state' => 'queued', 'quick_scan_error' => null, 'quick_scan_steps' => [],
            'quick_scan_user_id' => auth()->id() ?? ($ticket->quick_scan_state === 'pending' ? $ticket->quick_scan_user_id : null)])->save();
        self::dispatch($ticket);

        return true;
    }

    public function handle(Workspace $workspace, ClaudeRun $runner): void
    {
        $ticket = $this->ticket->fresh(['space']);
        if ($ticket === null || $ticket->quick_scan_state !== 'queued') {
            return;
        }
        $ticket->forceFill(['quick_scan_state' => 'running'])->save();

        try {
            Ticket::addQuickStep($ticket->id, 'gathering the ticket and its attachments');
            $workspace->prepare($ticket);
            $systems = $workspace->systems($ticket);
            $root = Workspace::path($ticket);

            $hasApis = ApiConnection::whereIn('system_id', $systems->pluck('id'))->exists();
            $command = [
                ...ClaudeRun::command(
                    (float) ($settings = SpaceSettings::for($ticket->space))->get('quick_budget_usd'),
                    mcpTools: $hasApis ? ['mcp__ticket-worker__list_apis', 'mcp__ticket-worker__call_api'] : [],
                    model: $settings->get('quick_model'),
                    effort: $settings->get('quick_effort'),
                ),
                // Nothing to resume: a quick scan is one look, not a conversation.
                '--no-session-persistence',
                '--append-system-prompt', Instructions::quickScanSystem($ticket, $systems),
                '--json-schema', json_encode(Instructions::quickScanSchema()),
            ];
            foreach ($systems->filter->hasCode() as $system) {
                $command = [...$command, '--add-dir', $system->path()];
            }
            if ($hasApis) {
                $command = [...$command, '--mcp-config', json_encode(['mcpServers' => ['ticket-worker' => [
                    'type' => 'stdio', 'command' => PHP_BINARY, 'args' => [base_path('artisan'), 'agent:tools', '--ticket='.$ticket->id],
                ]]], JSON_UNESCAPED_SLASHES)];
            }

            $shorten = [$root.'/' => ''];
            foreach ($systems->filter->hasCode() as $system) {
                $shorten[$system->path()] = $system->name.'/';
            }

            $run = $runner->run(
                $command, $root, Instructions::quickScanRequest($ticket), $shorten,
                fn (string $type, string $summary) => $type === 'tool' ? Ticket::addQuickStep($ticket->id, $summary) : null,
                fn () => false,
                $this->timeout,
                Instructions::credentials($ticket->quick_scan_user_id ? User::find($ticket->quick_scan_user_id) : null),
            );

            $result = $run['result'];
            $answer = $result['structured_output'] ?? null;
            $tokens = ClaudeRun::tokens($result);
            $ticket->forceFill([
                'quick_scan_state' => is_array($answer) ? 'done' : 'failed',
                'quick_scan' => is_array($answer) ? $answer : null,
                'quick_scan_error' => is_array($answer) ? null : Str::limit((string) (($result['result'] ?? '') ?: trim($run['errors']) ?: 'The scan ended without an answer.'), 1000),
                'quick_scan_cost_usd' => isset($result['total_cost_usd']) ? (float) $result['total_cost_usd'] : null,
                'quick_scan_tokens' => array_sum(array_filter($tokens, 'is_int')) ?: null,
                'quick_scan_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $ticket->forceFill(['quick_scan_state' => 'failed', 'quick_scan_error' => Str::limit($e->getMessage(), 1000), 'quick_scan_at' => now()])->save();
        }

        // A batch carries on at once rather than at the next minute.
        (new FeedQuickScans)();
    }

    /** The queue gave up on it: the row must not say "scanning" forever. */
    public function failed(?Throwable $e): void
    {
        $ticket = $this->ticket->fresh();
        if ($ticket !== null && in_array($ticket->quick_scan_state, ['queued', 'running'], true)) {
            $ticket->forceFill(['quick_scan_state' => 'failed', 'quick_scan_error' => 'Interrupted: the agent container stopped.'])->save();
        }
    }
}
