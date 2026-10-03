<?php

namespace App\Http\Controllers;

use App\Jira\JiraClient;
use App\Models\Space;
use App\Models\Ticket;
use App\Outbox\OutboxClient;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __invoke(JiraClient $jira, OutboxClient $outbox): Response
    {
        // Asked on each visit, so a message scheduled a moment ago shows; 30 seconds of
        // cache keeps a busy page from asking on every click.
        $waiting = Cache::remember('overview.outbox', 30, fn () => $outbox->waiting());

        $spaces = Space::query()->where('state', Space::ACTIVE)->orderBy('label')->get();

        $byId = $spaces->keyBy('id');

        $tickets = Ticket::query()
            ->with('casebookEntry:id,title')
            ->open()
            ->whereIn('space_id', $spaces->pluck('id'))
            ->orderByDesc('jira_updated_at')
            ->get();

        return Inertia::render('Overview', [
            'hasSpaces' => Space::query()->exists(),
            'spaces' => $spaces->map(fn (Space $space) => [
                'id' => $space->id,
                'label' => $space->label,
                'colour' => $space->colour,
                'synced_at' => $space->synced_at?->toIso8601String(),
                'sync_error' => $space->sync_error,
            ]),
            'tickets' => $tickets->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'key' => $ticket->key,
                'summary' => $ticket->summary,
                'space_id' => $ticket->space_id,
                'status' => $ticket->status,
                'status_tone' => $byId[$ticket->space_id]->statusTone($ticket->status, $ticket->status_category),
                'priority' => $ticket->priority,
                'reporter' => $ticket->reporter,
                'assignee' => $ticket->assignee,
                'slas' => $byId[$ticket->space_id]->visibleSlas($ticket->slas),
                // Messages waiting in jira-outbox for this ticket, and the status each will set.
                'outbox' => collect($waiting[$ticket->key] ?? [])->map(fn (array $message) => [
                    'state' => $message['state'] ?? 'scheduled',
                    'send_at' => $message['sendAt'] ?? null,
                    'visibility' => $message['visibility'] ?? 'public',
                    'to_status' => $message['transition']['toStatus'] ?? null,
                    'to_tone' => isset($message['transition']['toStatus'])
                        ? $byId[$ticket->space_id]->statusTone($message['transition']['toStatus'], $message['transition']['toCategory'] ?? null)
                        : null,
                    'assignee' => $message['assignee']['displayName'] ?? null,
                    'url' => $message['url'] ?? null,
                ])->all(),
                // A hint, not a verdict: the approved case this ticket looks most like.
                'casebook' => $ticket->casebookEntry === null ? null : [
                    'id' => $ticket->casebookEntry->id,
                    'title' => $ticket->casebookEntry->title,
                ],
                'created_at' => $ticket->jira_created_at?->toIso8601String(),
                'updated_at' => $ticket->jira_updated_at?->toIso8601String(),
                // Until a ticket has a page here, it opens in Jira.
                'url' => $jira->browseUrl($ticket->key),
                // Waiting on the requester sleeps, whatever else is going on. Agents arrive
                // with step 2; until then every other ticket waits for a first look.
                // Hidden by you beats everything; then waiting on the requester sleeps.
                'group' => match (true) {
                    $ticket->hidden_at !== null => 'hidden',
                    $byId[$ticket->space_id]->sleeps($ticket->status) => 'sleeping',
                    default => 'not-analysed',
                },
            ]),
        ]);
    }
}
