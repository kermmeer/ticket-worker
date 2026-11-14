<?php

namespace App\Jobs;

use App\Agent\Budget;
use App\Agent\Instructions;
use App\Casebook\Matcher;
use App\Jira\JiraClient;
use App\Jira\JiraException;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a space's open tickets from Jira into the overview.
 *
 * Tickets that a complete pass no longer finds have left: solved, moved or relabelled.
 * A pass that fails halfway changes nothing about who left, or a Jira hiccup would
 * empty the overview.
 */
class SyncSpace implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One sync per space at a time; a slow one is not joined by the next minute's. */
    public int $uniqueFor = 600;

    public function __construct(public Space $space) {}

    public function uniqueId(): string
    {
        return (string) $this->space->id;
    }

    public function handle(JiraClient $jira): void
    {
        $space = $this->space->fresh();

        if ($space === null || $space->state !== Space::ACTIVE) {
            return;
        }

        // The space's own site, or the default one.
        $jira = $space->jira();

        $now = now();
        // A space's first sync brings in everything it has: none of that is "new".
        $firstSync = $space->synced_at === null;
        $space->update(['sync_attempted_at' => $now]);
        $seen = [];
        $fresh = [];
        $casebook = Matcher::forSpace($space);
        $rejected = Matcher::rejections();

        // SLAs live in service spaces only. Not being able to read them is no reason to
        // stop the sync: the tickets come in without.
        try {
            $slaFields = $space->type === Space::SERVICE ? array_keys($jira->slaFields()) : [];
        } catch (JiraException) {
            $slaFields = [];
        }

        try {
            $next = null;

            do {
                $page = $jira->search($space->jql(), [...JiraClient::TICKET_FIELDS, ...$slaFields], $next);

                foreach ($page['issues'] as $issue) {
                    $seen[] = (string) $issue['id'];
                    $ticket = Ticket::record($space, $issue, $now, $slaFields);
                    $ticket->rememberMatch($casebook->best($ticket->matchText(), $rejected[$ticket->id] ?? []));
                    if ($ticket->wasRecentlyCreated && ! $firstSync) {
                        $fresh[] = $ticket;
                    }
                    $ticket->save();
                }

                $next = $page['next'];
            } while ($next !== null);
        } catch (JiraException $e) {
            $space->update(['sync_error' => $e->getMessage()]);

            return;
        }

        // Gone from the open tickets: say so, and take their status as it is now (resolved,
        // mostly), which the search above no longer brings.
        $left = $space->tickets()->open()->where('local', false)->whereNotIn('jira_id', $seen)->get();
        $space->tickets()->whereKey($left->modelKeys())->update(['left_at' => $now]);
        // Their chats are over: closed, unless an agent is still at work on one.
        if ($left->isNotEmpty()) {
            (new \App\Agent\CloseIdleChats)();
        }
        foreach ($left->chunk(100) as $chunk) {
            try {
                foreach ($jira->search('id in ('.$chunk->pluck('jira_id')->map(fn ($id) => (int) $id)->implode(',').')', ['status'])['issues'] as $issue) {
                    $space->tickets()->where('jira_id', (string) $issue['id'])->update([
                        'status' => $issue['fields']['status']['name'] ?? null,
                        'status_category' => $issue['fields']['status']['statusCategory']['key'] ?? null,
                    ]);
                }
            } catch (JiraException) {
                // Deleted, or out of reach: they have left either way.
            }
        }
        $space->update(['synced_at' => $now, 'sync_error' => null]);

        // New tickets get their quick scan, if the space has it on and an agent can sign in;
        // a flood beyond the limit waits for you to press Quick scan.
        if ($space->quick_scan && Instructions::instanceCredentials() !== [] && Budget::allows($space, null)) {
            // Development tickets only where the space asks for it: a scan is support triage.
            $devToo = (bool) \App\Settings\SpaceSettings::for($space)->get('dev_quick_scan');
            $fresh = array_values(array_filter($fresh, fn (Ticket $ticket) => $devToo || ! $ticket->setRelation('space', $space)->isDevelopment()));
            foreach (array_slice($fresh, 0, (int) config('agent.quick.per_sync')) as $ticket) {
                QuickScanTicket::start($ticket);
            }
        }
    }
}
