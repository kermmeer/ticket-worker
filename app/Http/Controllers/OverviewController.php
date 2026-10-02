<?php

namespace App\Http\Controllers;

use App\Jira\JiraClient;
use App\Models\Space;
use App\Models\Ticket;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __invoke(JiraClient $jira): Response
    {
        $spaces = Space::query()->where('state', Space::ACTIVE)->orderBy('label')->get();

        $byId = $spaces->keyBy('id');

        $tickets = Ticket::query()
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
                'created_at' => $ticket->jira_created_at?->toIso8601String(),
                'updated_at' => $ticket->jira_updated_at?->toIso8601String(),
                // Until a ticket has a page here, it opens in Jira.
                'url' => $jira->browseUrl($ticket->key),
                // Waiting on the requester sleeps, whatever else is going on. Agents arrive
                // with step 2; until then every other ticket waits for a first look.
                'group' => $byId[$ticket->space_id]->sleeps($ticket->status) ? 'sleeping' : 'not-analysed',
            ]),
        ]);
    }
}
