<?php

namespace App\Http\Controllers;

use App\Jira\JiraClient;
use App\Models\AgentSession;
use App\Models\AgentTurn;
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

        // Only the spaces this person may see; the tickets follow from them.
        $spaces = Space::query()->with('site')->visibleTo(auth()->user())->where('state', Space::ACTIVE)->orderBy('label')->get();

        $byId = $spaces->keyBy('id');
        // Per space, which issue types are development work.
        $devTypes = $spaces->mapWithKeys(fn (Space $space) => [$space->id => \App\Settings\SpaceSettings::for($space)->types('development_types')]);

        $tickets = Ticket::query()
            ->with(['casebookEntry:id,title', 'branches.system:id,name'])
            ->open()
            ->whereIn('space_id', $spaces->pluck('id'))
            ->orderByDesc('jira_updated_at')
            ->get();

        // Where each ticket's conversation stands: its latest session and that session's last turn.
        $sessions = AgentSession::query()
            ->whereIn('ticket_id', $tickets->pluck('id'))
            ->with(['turns' => fn ($query) => $query->select('id', 'agent_session_id', 'state'), 'user:id,name,username'])
            ->orderBy('id')
            ->get()
            ->keyBy('ticket_id');

        $followed = request()->user()?->followedSpaces()->pluck('spaces.id')->all() ?? [];

        return Inertia::render('Overview', [
            'hasSpaces' => Space::query()->exists(),
            // How often a sync is due: a space far past it has a worker down somewhere.
            'syncEvery' => \App\Models\Setting::hyper() ? 1 : (int) config('agent.sync_every_minutes'),
            'spaces' => $spaces->map(fn (Space $space) => [
                'id' => $space->id,
                'label' => $space->label,
                'colour' => $space->colour,
                'synced_at' => $space->synced_at?->toIso8601String(),
                'followed' => in_array($space->id, $followed, true),
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
                'assignee_account_id' => $ticket->assignee_account_id,
                'mine' => Ticket::isMine($ticket->assignee_account_id, $ticket->assignee, auth()->user()),
                'slas' => $byId[$ticket->space_id]->visibleSlas($ticket->slas),
                // Messages waiting in the outbox for this ticket, and the status each will set.
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
                'url' => $ticket->local ? null : $byId[$ticket->space_id]->jira()->browseUrl($ticket->key),
                'local' => (bool) $ticket->local,
                'open_label' => $byId[$ticket->space_id]->openLabel(),
                'page' => route('tickets.show', $ticket, false),
                // Only what the row shows: the overview refreshes every 30 seconds with every ticket,
                // and the whole scan (its steps, its cause, its next step) made that a megabyte.
                'quick_scan' => $ticket->quick_scan_state === null ? null : [
                    'state' => $ticket->quick_scan_state,
                    'cost_usd' => $ticket->quick_scan_cost_usd,
                    'result' => $ticket->quick_scan === null ? null : [
                        'kind' => $ticket->quick_scan['kind'] ?? null,
                        'system' => $ticket->quick_scan['system'] ?? null,
                        'summary' => $ticket->quick_scan['summary'] ?? null,
                        'data_check' => isset($ticket->quick_scan['data_check']) ? ['still_present' => $ticket->quick_scan['data_check']['still_present'] ?? 'unknown'] : null,
                    ],
                ],
                // Whose open session it is: "Karl is on it", so two people never work one ticket blind.
                'on_it' => ($session = $sessions[$ticket->id] ?? null) !== null && $session->state !== 'closed' && $session->user !== null
                    ? ($session->user->is(auth()->user()) ? 'you' : $session->user->firstName())
                    : null,
                // Waiting on the requester sleeps, whatever else is going on. Agents arrive
                // with step 2; until then every other ticket waits for a first look.
                'kind' => ($dev = in_array(mb_strtolower((string) $ticket->issue_type), $devTypes[$ticket->space_id] ?? [], true)) ? 'development' : 'support',
                'issue_type' => $ticket->issue_type,
                // The code built for it, in short.
                'branches' => $ticket->branches->map(fn ($branch) => [
                    'system' => $branch->system?->name, 'name' => $branch->name, 'commits' => $branch->commits, 'added' => $branch->added, 'removed' => $branch->removed,
                ])->values(),
                'group' => $this->group($ticket, $sessions[$ticket->id] ?? null, $byId[$ticket->space_id], $dev),
            ]),
        ]);
    }

    /**
     * What a ticket needs from you (CONCEPT.md §6). Hidden by you beats everything; an
     * agent at work or waiting for you comes next; then sleep, a closed session, or nothing yet.
     */
    private function group(Ticket $ticket, ?AgentSession $session, Space $space, bool $development = false): string
    {
        $last = $session?->turns->last();

        return match (true) {
            $ticket->hidden_at !== null => 'hidden',
            $session?->state === 'open' && in_array($last?->state, [AgentTurn::QUEUED, AgentTurn::RUNNING], true) => 'working',
            $session?->state === 'open' && $last !== null => 'needs-you',
            (bool) $ticket->local => 'own-work',
            $space->sleeps($ticket->status) => 'sleeping',
            // Development goes by where it is in Jira, not by what an agent did.
            $development => $ticket->status_category === 'indeterminate' ? 'dev-in-progress' : 'dev-to-do',
            $session?->state === 'closed' => 'parked',
            default => 'not-analysed',
        };
    }
}
