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

        // Tickets confirmed on each case: one problem, handled together.
        $confirmed = $tickets->where('casebook_manual', true)->whereNotNull('casebook_entry_id')->countBy('casebook_entry_id');

        // Your last look at each ticket, and the agent's last answer on it (any chat).
        $seen = \Illuminate\Support\Facades\DB::table('ticket_views')->where('user_id', auth()->id())->whereIn('ticket_id', $tickets->pluck('id'))->pluck('seen_at', 'ticket_id');
        $answered = \Illuminate\Support\Facades\DB::table('agent_turns')->join('agent_sessions', 'agent_sessions.id', '=', 'agent_turns.agent_session_id')
            ->whereIn('agent_sessions.ticket_id', $tickets->pluck('id'))->whereNotNull('agent_turns.finished_at')
            ->selectRaw('agent_sessions.ticket_id as ticket, max(agent_turns.finished_at) as at')->groupBy('agent_sessions.ticket_id')->pluck('at', 'ticket');
        $state = fn (Ticket $ticket) => $this->state($ticket, $sessions[$ticket->id] ?? null, $seen[$ticket->id] ?? null, $answered[$ticket->id] ?? null);

        $rows = $tickets->map(fn (Ticket $ticket) => [
            'id' => $ticket->id,
            'key' => $ticket->key,
            'summary' => $ticket->summary,
            'space_id' => $ticket->space_id,
            'status' => $ticket->status,
            'status_tone' => $byId[$ticket->space_id]->statusTone($ticket->status, $ticket->status_category),
            'status_category' => $ticket->status_category,
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
            // A hint, not a verdict: the approved case this ticket looks most like. Confirmed,
            // it groups with the other tickets confirmed on that case.
            'casebook' => $ticket->casebookEntry === null ? null : [
                'id' => $ticket->casebookEntry->id,
                'title' => $ticket->casebookEntry->title,
                'confirmed' => (bool) $ticket->casebook_manual,
                'others' => $ticket->casebook_manual ? max(0, ($confirmed[$ticket->casebook_entry_id] ?? 1) - 1) : 0,
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
            // Since your last look: an answer waiting, Jira changes; and whether it was marked done here.
            ...($flags = $state($ticket)),
            'done' => $ticket->done_at !== null,
            'group' => $this->group($ticket, $sessions[$ticket->id] ?? null, $byId[$ticket->space_id], $dev, $flags),
        ])->values();

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
            'tickets' => $rows,
            // Your next ten (CONCEPT.md, proposal of 14 November 2026, D): each with its reason.
            'nextTen' => $this->nextTen($rows, $sessions),
        ]);
    }

    /**
     * What a ticket needs from you (CONCEPT.md §6). Hidden by you beats everything; an
     * agent at work or waiting for you comes next; then sleep, a closed session, or nothing yet.
     */
    /**
     * Your next ten: new activity on a ticket you work, then an answer waiting for you, then
     * an SLA still in time and due soonest (yours, then nobody's in your spaces), then high
     * priority assigned to you. Never sleeping, hidden or done; each ticket once.
     *
     * @return list<array{id: int, reason: string}>
     */
    private function nextTen(\Illuminate\Support\Collection $rows, \Illuminate\Support\Collection $sessions): array
    {
        $mineToWork = fn (array $row) => $row['mine'] || ($sessions[$row['id']]->user_id ?? null) === auth()->id() || ($row['local'] ?? false);
        $live = $rows->filter(fn (array $row) => ! in_array($row['group'], ['sleeping', 'hidden'], true) && ! $row['done']);
        $due = fn (array $row) => collect($row['slas'] ?? [])->filter(fn ($sla) => ($sla['state'] ?? '') === 'running' && ! ($sla['paused'] ?? false) && ($sla['remaining_ms'] ?? -1) > 0)->min('remaining_ms');

        $picked = [];
        $take = function (iterable $candidates, callable $reason) use (&$picked) {
            foreach ($candidates as $row) {
                if (count($picked) >= 10) {
                    return;
                }
                $picked[$row['id']] ??= ['id' => $row['id'], 'reason' => $reason($row)];
            }
        };
        $take($live->filter(fn ($row) => $row['new_activity'] && $mineToWork($row)), fn () => 'Changed in Jira since you last looked');
        $take($live->filter(fn ($row) => $row['needs_you'] && $mineToWork($row)), fn () => 'The agent answered since you last looked');
        $withDue = $live->filter(fn ($row) => $due($row) !== null && ($row['mine'] || $row['assignee'] === null))
            ->sortBy(fn ($row) => [$row['mine'] ? 0 : 1, $due($row)]);
        $take($withDue, fn ($row) => 'SLA due in '.self::duration((int) $due($row)).($row['mine'] ? '' : ', assigned to nobody'));
        $take($live->filter(fn ($row) => $row['mine'] && in_array(strtolower((string) $row['priority']), ['highest', 'high', 'critical', 'blocker'], true)),
            fn ($row) => "{$row['priority']} priority, yours");

        return array_values($picked);
    }

    private static function duration(int $ms): string
    {
        $minutes = intdiv($ms, 60000);

        return $minutes < 60 ? "{$minutes} min" : ($minutes < 1440 ? intdiv($minutes, 60).' h '.($minutes % 60).' min' : intdiv($minutes, 1440).' d');
    }

    /**
     * What happened since you last looked (CONCEPT.md, proposal of 14 November 2026, A):
     * the agent answered (needs you), or Jira changed the ticket (new activity). Without a
     * look of yours yet, the agent's last answer is where "since" starts.
     *
     * @return array{needs_you: bool, new_activity: bool, seen_at: ?string}
     */
    private function state(Ticket $ticket, ?AgentSession $session, ?string $seenAt, ?string $answeredAt): array
    {
        $seen = $seenAt ? \Illuminate\Support\Carbon::parse($seenAt) : null;
        $answered = $answeredAt ? \Illuminate\Support\Carbon::parse($answeredAt) : null;
        $last = $session?->turns->last();
        $needsYou = $ticket->done_at === null && $session?->state === 'open' && $last?->state === AgentTurn::DONE
            && $answered !== null && ($seen === null || $answered->gt($seen));
        $since = $seen ?? $answered;
        $mine = $answered !== null || Ticket::isMine($ticket->assignee_account_id, $ticket->assignee, auth()->user());
        $newActivity = $ticket->done_at === null && $mine && $since !== null && $ticket->jira_updated_at?->gt($since);

        return ['needs_you' => $needsYou, 'new_activity' => (bool) $newActivity, 'seen_at' => $seen?->toIso8601String()];
    }

    private function group(Ticket $ticket, ?AgentSession $session, Space $space, bool $development = false, array $flags = []): string
    {
        $last = $session?->turns->last();

        return match (true) {
            $ticket->hidden_at !== null => 'hidden',
            $session?->state === 'open' && in_array($last?->state, [AgentTurn::QUEUED, AgentTurn::RUNNING], true) => 'working',
            $flags['needs_you'] ?? false => 'needs-you',
            $flags['new_activity'] ?? false => 'new-activity',
            (bool) $ticket->local => 'own-work',
            $space->sleeps($ticket->status) => 'sleeping',
            // Development goes by where it is in Jira, not by what an agent did.
            $development => $ticket->status_category === 'indeterminate' ? 'dev-in-progress' : 'dev-to-do',
            $session !== null => 'parked',
            default => 'not-analysed',
        };
    }
}
