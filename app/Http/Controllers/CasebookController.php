<?php

namespace App\Http\Controllers;

use App\Jira\JiraClient;
use App\Jobs\RematchCasebook;
use App\Models\AgentTurn;
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

    public function edit(CasebookEntry $entry, JiraClient $jira): Response
    {
        return Inertia::render('Casebook/Form', [
            'entry' => [
                'id' => $entry->id,
                'title' => $entry->title,
                'system_id' => $entry->system_id,
                'space_ids' => $entry->space_ids ?? [],
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
            // The open tickets it matches right now: the quickest check on its words.
            'matches' => $entry->tickets()->open()->orderByDesc('casebook_score')->limit(20)->get()
                ->map(fn (Ticket $ticket) => [
                    'key' => $ticket->key,
                    'summary' => $ticket->summary,
                    'score' => $ticket->casebook_score,
                    'url' => $jira->browseUrl($ticket->key),
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

        // "SUP-1, SUP-2" in the form, a list in the database.
        $data['source_tickets'] = collect(preg_split('/[\s,;]+/', (string) ($data['source_tickets'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($key) => mb_strtoupper($key))->unique()->values()->all();

        return $data;
    }
}
