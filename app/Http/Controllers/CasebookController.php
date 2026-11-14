<?php

namespace App\Http\Controllers;

use App\Casebook\Matcher;
use App\Jira\JiraClient;
use App\Jobs\RematchCasebook;
use App\Models\AgentTurn;
use App\Models\AuditEvent;
use App\Models\CasebookEntry;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The casebook: solved cases for the next agent, and for you (CONCEPT.md §8). */
class CasebookController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Casebook/Index', [
            'entries' => CasebookEntry::query()
                ->with('system:id,name')
                ->withCount(['tickets as open_matches' => fn ($query) => $query->open()])
                ->orderByRaw("case state when 'approved' then 0 when 'draft' then 1 else 2 end")
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (CasebookEntry $entry) => [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'symptoms' => Str::limit($entry->symptoms, 220),
                    'system' => $entry->system?->name,
                    'state' => $entry->state,
                    'written_by' => $entry->written_by,
                    'source_tickets' => $entry->source_tickets ?? [],
                    'used_count' => $entry->used_count,
                    'open_matches' => $entry->open_matches,
                    'updated_at' => $entry->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * A new case, started from a ticket (?ticket=SUP-1234) or from an agent's draft
     * (?turn=12). Nothing is saved until you submit the form.
     */
    public function create(Request $request): Response
    {
        if ($request->filled('turn') && ($turn = AgentTurn::query()->with('session.ticket')->find($request->integer('turn')))?->case_draft) {
            $draft = $turn->case_draft;

            return Inertia::render('Casebook/Form', [
                'entry' => [
                    'id' => null,
                    'title' => (string) ($draft['title'] ?? ''),
                    'system_id' => System::query()->where('name', $draft['system'] ?? '')->value('id'),
                    'symptoms' => (string) ($draft['symptoms'] ?? ''),
                    'cause' => (string) ($draft['cause'] ?? ''),
                    'solution' => (string) ($draft['solution'] ?? ''),
                    'keywords' => (string) ($draft['keywords'] ?? ''),
                    'source_tickets' => $turn->session->ticket->key,
                    'state' => CasebookEntry::DRAFT,
                    // The kind of ticket it was written from: the kind it hints for.
                    'kind' => $turn->session->ticket->kind(),
                ],
                'systems' => System::query()->orderBy('name')->get(['id', 'name']),
                'spaces' => \App\Models\Space::query()->orderBy('label')->get(['id', 'label', 'colour']),
                'matches' => [],
                'fromAgent' => true,
            ]);
        }

        $ticket = $request->filled('ticket')
            ? Ticket::query()->with('space.systems:id')->firstWhere('key', $request->string('ticket'))
            : null;
        $systems = $ticket?->space?->systems;

        return Inertia::render('Casebook/Form', [
            'entry' => [
                'id' => null,
                'title' => $ticket?->summary ?? '',
                'system_id' => $systems?->count() === 1 ? $systems->first()->id : null,
                'symptoms' => '',
                'cause' => '',
                'solution' => '',
                'keywords' => '',
                'source_tickets' => $ticket ? $ticket->key : '',
                'state' => CasebookEntry::DRAFT,
            ],
            'systems' => System::query()->orderBy('name')->get(['id', 'name']),
            'spaces' => \App\Models\Space::query()->orderBy('label')->get(['id', 'label', 'colour']),
            'matches' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // A member proposes; an editor approves. Until then the agents are not offered it.
        if (! $request->user()->atLeast('editor')) {
            $data['state'] = CasebookEntry::DRAFT;
        }
        $entry = CasebookEntry::create($data + ['written_by' => $request->user()->firstName()]);
        \App\Models\AuditEvent::record('case.create', $entry->title, "written as {$entry->state}");
        RematchCasebook::dispatch();

        return $request->user()->atLeast('editor')
            ? to_route('casebook.edit', $entry)->with('success', 'Written down. Approve it to have it offered.')
            : to_route('casebook.index')->with('success', 'Proposed. An editor approves it, and from then on the agents are offered it.');
    }

    /** "Not related": untie the ticket, and never tie it to this case again. */
    /**
     * One reply to every open ticket confirmed on this case: one problem, one answer, each
     * still its own Jira ticket. Your template alone is never posted.
     */
    public function replyAll(\Illuminate\Http\Request $request, CasebookEntry $entry): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:32000'], 'visibility' => ['nullable', 'in:public,internal']]);
        if (TicketController::onlyTemplate($data['body'])) {
            return back()->with('error', 'Nothing written in the template: nothing to send.');
        }
        $tickets = $entry->tickets()->open()->where('casebook_manual', true)->where('local', false)->visibleTo(auth()->user())->with('space')->get();
        if ($tickets->isEmpty()) {
            return back()->with('error', 'No open ticket is confirmed on this case.');
        }
        if (config('services.jira.write') !== 'real') {
            return back()->with('error', 'JIRA_WRITE=dummy: nothing was sent. With it real, this reply would now go to '.$tickets->pluck('key')->implode(', ').'.');
        }

        $sent = [];
        $failed = [];
        foreach ($tickets as $ticket) {
            try {
                $ticket->space->jira()->comment($ticket->key, trim($data['body']), ($data['visibility'] ?? 'public') === 'internal');
                $ticket->markSeen(auth()->user());
                $sent[] = $ticket->key;
            } catch (\App\Jira\JiraException $e) {
                $failed[] = "{$ticket->key} ({$e->getMessage()})";
            }
        }
        AuditEvent::record('case.reply-all', $entry->title, 'posted to '.(implode(', ', $sent) ?: 'none').($failed ? '; failed: '.implode(', ', $failed) : ''));

        return back()->with($failed ? 'error' : 'success', ($sent ? 'Posted to '.implode(', ', $sent).'.' : '').($failed ? ' Not to '.implode('; ', $failed).'.' : ''));
    }

    /** Tie a ticket to this case yourself, by its key: also one whose words do not match, or one already done. */
    public function link(\Illuminate\Http\Request $request, CasebookEntry $entry): RedirectResponse
    {
        $key = strtoupper(trim((string) $request->validate(['key' => ['required', 'string', 'max:40']])['key']));
        $ticket = Ticket::query()->visibleTo(auth()->user())->where('key', $key)->latest('id')->first();
        if ($ticket === null) {
            return back()->withErrors(['key' => "No ticket {$key} that you can see here."]);
        }

        \Illuminate\Support\Facades\DB::table('casebook_rejections')->where(['casebook_entry_id' => $entry->id, 'ticket_id' => $ticket->id])->delete();
        $ticket->forceFill(['casebook_entry_id' => $entry->id, 'casebook_score' => null, 'casebook_manual' => true])->save();
        AuditEvent::record('case.link', $entry->title, "{$ticket->key} tied to it by hand");

        return back()->with('success', "{$ticket->key} is tied to this case. The agent reads the case first on it.");
    }

    public function unlink(CasebookEntry $entry, Ticket $ticket): RedirectResponse
    {
        $ticket->casebook_manual = false;
        \Illuminate\Support\Facades\DB::table('casebook_rejections')->insertOrIgnore([
            'casebook_entry_id' => $entry->id, 'ticket_id' => $ticket->id, 'user_id' => auth()->id(), 'created_at' => now(),
        ]);
        if ($ticket->casebook_entry_id === $entry->id) {
            // The next best case, if one fits; else none.
            $ticket->rememberMatch(Matcher::forSpace($ticket->space)->best($ticket->matchText(), Matcher::rejections()[$ticket->id] ?? [], $ticket->kind()));
            $ticket->save();
        }
        if ($ticket->isDirty()) {
            $ticket->save();
        }
        AuditEvent::record('case.unlink', $entry->title, "{$ticket->key} is not related");

        return back()->with('success', "{$ticket->key} is no longer tied to this case, and will not be again.");
    }

    /** Changed your mind: the ticket may match this case again. */
    public function relink(CasebookEntry $entry, Ticket $ticket): RedirectResponse
    {
        \Illuminate\Support\Facades\DB::table('casebook_rejections')->where(['casebook_entry_id' => $entry->id, 'ticket_id' => $ticket->id])->delete();
        $ticket->rememberMatch(Matcher::forSpace($ticket->space)->best($ticket->matchText(), Matcher::rejections()[$ticket->id] ?? [], $ticket->kind()));
        $ticket->save();
        AuditEvent::record('case.relink', $entry->title, "{$ticket->key} may match it again");

        return back()->with('success', $ticket->casebook_entry_id === $entry->id ? "{$ticket->key} is tied to this case again." : "{$ticket->key} may match this case again; its words do not, right now.");
    }

    public function edit(CasebookEntry $entry, JiraClient $jira): Response
    {
        return Inertia::render('Casebook/Form', [
            'replyTemplate' => \App\Models\Setting::replyTemplateFor(auth()->user()?->firstName()),
            'entry' => [
                'id' => $entry->id,
                'title' => $entry->title,
                'system_id' => $entry->system_id,
                'space_ids' => $entry->space_ids ?? [],
                'kind' => $entry->kind ?? 'both',
                'symptoms' => $entry->symptoms,
                'cause' => $entry->cause ?? '',
                'solution' => $entry->solution,
                'keywords' => $entry->keywords ?? '',
                'source_tickets' => implode(', ', $entry->source_tickets ?? []),
                'state' => $entry->state,
                'written_by' => $entry->written_by,
                'used_count' => $entry->used_count,
            ],
            'systems' => System::query()->orderBy('name')->get(['id', 'name']),
            'spaces' => \App\Models\Space::query()->orderBy('label')->get(['id', 'label', 'colour']),
            // Every open ticket tied to it, strongest first; and those you said are not related.
            // Those tied by hand first, also when they are done; then the matched ones, strongest first.
            'linked' => $entry->tickets()->where(fn ($query) => $query->whereNull('left_at')->orWhere('casebook_manual', true))
                ->visibleTo(auth()->user())->orderByDesc('casebook_manual')->orderByDesc('casebook_score')->get()
                ->map(fn (Ticket $ticket) => [
                    'id' => $ticket->id,
                    'key' => $ticket->key,
                    'summary' => $ticket->summary,
                    'score' => $ticket->casebook_score,
                    'manual' => $ticket->casebook_manual,
                    'done' => $ticket->left_at !== null,
                    'page' => route('tickets.show', $ticket, false),
                ]),
            'unlinked' => Ticket::query()->visibleTo(auth()->user())
                ->whereIn('id', \Illuminate\Support\Facades\DB::table('casebook_rejections')->where('casebook_entry_id', $entry->id)->pluck('ticket_id'))
                ->orderBy('key')->get()->map(fn (Ticket $ticket) => [
                    'id' => $ticket->id, 'key' => $ticket->key, 'summary' => $ticket->summary, 'page' => route('tickets.show', $ticket, false),
                ]),
        ]);
    }

    public function update(Request $request, CasebookEntry $entry): RedirectResponse
    {
        $before = $entry->getAttributes();
        $entry->update($this->validated($request));
        \App\Models\AuditEvent::record('case.update', $entry->title, 'changed '.\App\Models\AuditEvent::changes($before, $entry->getAttributes()));
        RematchCasebook::dispatch();

        return back()->with('success', 'Saved. Open tickets are matched again.');
    }

    public function destroy(CasebookEntry $entry): RedirectResponse
    {
        \App\Models\AuditEvent::record('case.remove', $entry->title, 'removed');
        $entry->delete();
        RematchCasebook::dispatch();

        return to_route('casebook.index')->with('success', 'Removed from the casebook.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'system_id' => ['nullable', 'integer', Rule::exists('systems', 'id')],
            // Empty: every space.
            'kind' => ['nullable', Rule::in(['support', 'development', 'both'])],
            'space_ids' => ['nullable', 'array'],
            'space_ids.*' => ['integer', Rule::exists('spaces', 'id')],
            'symptoms' => ['required', 'string', 'max:10000'],
            'cause' => ['nullable', 'string', 'max:10000'],
            'solution' => ['required', 'string', 'max:20000'],
            'keywords' => ['nullable', 'string', 'max:2000'],
            'source_tickets' => ['nullable', 'string', 'max:2000'],
            'state' => ['required', Rule::in([CasebookEntry::DRAFT, CasebookEntry::APPROVED, CasebookEntry::RETIRED])],
        ]);

        $data['space_ids'] = array_values(array_map('intval', $data['space_ids'] ?? [])) ?: null;
        // Both kinds: no kind at all, so the matcher offers it to every ticket.
        $data['kind'] = in_array($data['kind'] ?? 'both', ['support', 'development'], true) ? $data['kind'] : null;

        // "SUP-1, SUP-2" in the form, a list in the database.
        $data['source_tickets'] = collect(preg_split('/[\s,;]+/', (string) ($data['source_tickets'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($key) => mb_strtoupper($key))->unique()->values()->all();

        return $data;
    }
}
