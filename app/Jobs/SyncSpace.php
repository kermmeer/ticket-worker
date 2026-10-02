<?php

namespace App\Jobs;

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

        $now = now();
        $space->update(['sync_attempted_at' => $now]);
        $seen = [];

        try {
            $next = null;

            do {
                $page = $jira->search($space->jql(), JiraClient::TICKET_FIELDS, $next);

                foreach ($page['issues'] as $issue) {
                    $seen[] = (string) $issue['id'];
                    Ticket::record($space, $issue, $now);
                }

                $next = $page['next'];
            } while ($next !== null);
        } catch (JiraException $e) {
            $space->update(['sync_error' => $e->getMessage()]);

            return;
        }

        $space->tickets()->open()->whereNotIn('jira_id', $seen)->update(['left_at' => $now]);
        $space->update(['synced_at' => $now, 'sync_error' => null]);
    }
}
