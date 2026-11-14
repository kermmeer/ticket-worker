<?php

namespace App\Http\Controllers;

use App\Agent\Budget;
use App\Agent\Instructions;
use App\Agent\Workspace;
use App\Jira\JiraClient;
use App\Jira\JiraException;
use App\Jobs\QuickScanTicket;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\AuditEvent;
use App\Models\Ticket;
use App\Outbox\OutboxClient;
use App\Settings\SpaceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** A ticket's page: the ticket from Jira, the agent at work, its proposal (CONCEPT.md §7, §8). */
class TicketController extends Controller
{
    public const LANGUAGES = ['auto', 'English', 'Nederlands', 'Français', 'Dansk', 'Deutsch'];

    public function show(Ticket $ticket): Response
    {
        $ticket->load('space.site');
        $jira = $ticket->space->jira();

        // Fresh from Jira on every visit and refresh; the cache is for the page's own polling
        // while an agent works, which asks every few seconds. Own work has no Jira issue yet.
        $issueError = null;
        if ($ticket->isLocal()) {
            $issue = $ticket->localIssue();
        } else {
            if (! request()->hasHeader('X-Inertia-Partial-Data')) {
                Cache::forget("ticket.issue.{$ticket->id}");
            }
            try {
                $issue = Cache::remember("ticket.issue.{$ticket->id}", 60, fn () => $jira->issue($ticket->key));
                $ticket->takeLive($issue);
            } catch (JiraException $e) {
                $issue = null;
                $issueError = $e->getMessage();
            }
        }

        $session = $ticket->openSession();
        // Your last look before this one: what came since is marked new. A page's own polling is no look.
        $seenBefore = $ticket->seenAt(auth()->user());
        if (! request()->hasHeader('X-Inertia-Partial-Data')) {
            $ticket->markSeen(auth()->user());
        }

        return Inertia::render('Tickets/Show', [
            'seenBefore' => $seenBefore?->toIso8601String(),
            'ticket' => [
                'id' => $ticket->id,
                'key' => $ticket->key,
                'summary' => $ticket->summary,
                'status' => $ticket->status,
                'status_tone' => $ticket->space->statusTone($ticket->status, $ticket->status_category),
                'priority' => $ticket->priority,
                'assignee' => $ticket->assignee,
                'assigned_to_me' => Ticket::isMine($ticket->assignee_account_id, $ticket->assignee, auth()->user()),
                'slas' => $ticket->space->visibleSlas($ticket->slas),
                'url' => $ticket->isLocal() ? null : $jira->browseUrl($ticket->key),
                // Own work: what you wrote, your files, and the Jira ticket the agent drafted for it.
                'local' => $ticket->isLocal(),
                // How it ended here, when it did; and whether an agent worked on it (then Done asks about its answer).
                'done' => $ticket->done_at ? [
                    'at' => $ticket->done_at->toIso8601String(), 'by' => \App\Models\User::find($ticket->done_by)?->firstName(),
                    'how' => $ticket->done_how, 'verdict' => $ticket->done_verdict, 'note' => $ticket->done_note,
                ] : null,
                'worked' => $ticket->sessions()->exists(),
                'local_description' => $ticket->local_description,
                'files' => $ticket->isLocal() ? array_map(fn ($file) => [...$file, 'url' => route('work.file', [$ticket, $file['name']], false)], $ticket->localFiles()) : [],
                'ticket_draft' => $ticket->isLocal() ? AgentTurn::query()->whereIn('agent_session_id', $ticket->sessions()->select('id'))->whereNotNull('ticket_draft')->latest('id')->first()?->ticket_draft : null,
                'open_label' => $ticket->space->openLabel(),
                'space' => ['label' => $ticket->space->label, 'colour' => $ticket->space->colour, 'type' => $ticket->space->type],
                'quick_scan' => $ticket->quickScanData(),
                'kind' => $ticket->kind(),
                'branch_name' => $ticket->branchName(),
                // The code built for it, per repository: branch, commits, lines.
                'branches' => $ticket->branches()->with('system:id,name')->get()->map->summary()->values(),
                // Branches named after it already in its systems, built here or not: Build continues those.
                // Changes the agent proposed through an API: waiting first, then the last decided.
                'changes' => \App\Models\ApiCall::query()->with(['api.system', 'decider'])->where('ticket_id', $ticket->id)
                    ->orderByRaw("state = 'waiting' desc")->latest('id')->limit(20)->get()->map->summary()->values(),
                'repo_branches' => app(Workspace::class)->systems($ticket->load('space'))->filter->hasCode()
                    ->flatMap(fn ($system) => array_map(fn ($name) => ['system' => $system->name, 'name' => $name], Workspace::ticketBranches($system, $ticket->key)))->values(),
                'base_branch' => SpaceSettings::for($ticket->space)->get('base_branch'),
                // The latest refinement, from whichever chat made it.
                'refinement' => AgentTurn::query()->whereIn('agent_session_id', $ticket->sessions()->select('id'))->whereNotNull('refinement')->latest('id')->first()?->refinement,
                // Gone from the space's open tickets: resolved, or moved out of its rules.
                'left' => $ticket->left_at !== null || $ticket->status_category === 'done',
            ],
            'issue' => $issue,
            'issueError' => $issueError,
            'session' => $session ? $this->sessionData($session) : null,
            // The chat before this one, folded above it: a fresh chat reads on from where it left off.
            'earlier' => ($earlier = $ticket->sessions()->where('state', 'closed')->where('closed_at', '>', now()->subDays(14))->latest('id')->first())
                ? [...$this->sessionData($earlier), 'closed_at' => $earlier->closed_at?->toIso8601String()] : null,
            'chatModel' => SpaceSettings::for($ticket->space)->get('chat_model'),
            'deepModel' => SpaceSettings::for($ticket->space)->get('model'),
            'freshAfter' => (int) SpaceSettings::for($ticket->space)->get('fresh_after_minutes'),
            // Closed sessions: what each did and what it cost, newest first.
            'history' => $ticket->sessions()->where('state', 'closed')->latest('id')->get()->map->summary(),
            'languages' => self::LANGUAGES,
            'agentReady' => Instructions::credentials() !== [],
            // A budget near or past its end: said before anyone presses a button.
            'budget' => Budget::check($ticket->space, auth()->user()),
            // What a patch can be for.
            'systems' => app(Workspace::class)->systems($ticket)->map(fn ($system) => ['id' => $system->id, 'name' => $system->name, 'branch' => $system->branch, 'code' => $system->hasCode()])->values(),
            'outboxReady' => app(OutboxClient::class)->configured(),
            'jiraWrite' => config('services.jira.write') === 'real',
            'doneHow' => Ticket::DONE_HOW,
            'verdicts' => Ticket::VERDICTS,
            'workTypes' => \App\Http\Controllers\OwnWorkController::TYPES,
            // Your greeting and sign-off: an empty reply box fills with it, the agent's draft goes inside it.
            'replyTemplate' => \App\Models\Setting::replyTemplateFor(auth()->user()?->firstName() ?? $jira->firstName()),
        ]);
    }

    /** What you can paste to the agent at once: a query result fits, a whole dump does not. */
    private const MAX_TEXT = 100000;

    /** Dig deeper: the full analysis, on the full model, ending in a proposal. */

    public function analyse(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(self::LANGUAGES)],
            'note' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
        ], ['note.max' => 'Too long to send: at most :max characters. Leave out rows the agent does not need.']);
        abort_unless(Instructions::credentials() !== [], 409, 'No ANTHROPIC_API_KEY or CLAUDE_CODE_OAUTH_TOKEN yet: see Setup.');

        if ($reason = $this->overBudget($ticket)) {
            return back()->with('error', $reason);
        }

        if ($ticket->openSession()?->busy()) {
            return back()->with('error', 'The agent is still working on this ticket.');
        }

        [$session, $earlier] = $this->chat($ticket, $data['language']);
        $session->update(['reply_language' => $data['language']]);

        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
        $this->turn($session, 'analysis', Instructions::analysis($ticket, $note, $earlier), $note);

        return back();
    }

    /**
     * The chat to continue: the open one, unless it has been quiet longer than the space's
     * fresh_after_minutes. Then, or when there is none, a new one, with what the last one found.
     *
     * @return array{0: AgentSession, 1: ?string} the chat, and the summary its first turn carries
     */
    private function chat(Ticket $ticket, ?string $language = null): array
    {
        $session = $ticket->openSession();
        if ($session !== null) {
            $last = $session->turns()->reorder()->latest('id')->first();
            $quietSince = $last?->finished_at ?? $last?->created_at ?? $session->created_at;
            if (! $session->busy() && $quietSince->lt(now()->subMinutes((int) SpaceSettings::for($ticket->space)->get('fresh_after_minutes')))) {
                $session->update(['state' => 'closed', 'closed_at' => now()]);
                $session = null;
            }
        }
        if ($session !== null) {
            return [$session, null];
        }

        $previous = $ticket->sessions()->where('state', 'closed')->where('closed_at', '>', now()->subDays(14))->latest('id')->first();

        return [AgentSession::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->id(),
            'claude_session_id' => (string) Str::uuid(),
            'reply_language' => $language ?? 'auto',
            'model' => SpaceSettings::for($ticket->space)->get('model'),
        ]), $previous ? Instructions::carryOver($previous) : null];
    }

    /** A question in the chat; the first one starts it. */
    public function message(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.self::MAX_TEXT],
            'language' => ['nullable', Rule::in(self::LANGUAGES)],
        ], [
            'text.max' => 'Too long to send: at most :max characters. Leave out rows the agent does not need.',
        ]);

        // "Make a patch" in a message: the agent would write the diff into its answer, with
        // nothing to download. A patch turn makes the file.
        // "Build it" on a development ticket: a build on its branch, with these words along.
        if ($ticket->isDevelopment() && (self::asksForPatch($data['text']) || self::asksForBuild($data['text']))
            && ! $ticket->openSession()?->busy() && ! $this->overBudget($ticket)
            && ($system = $this->buildSystem($ticket, $ticket->openSession()))) {
            [$session] = $this->chat($ticket, $data['language'] ?? null);
            RunAgentTurn::queueBuild($session, $system, request: trim($data['text']));

            return back();
        }

        if (self::asksForPatch($data['text']) && ($session = $ticket->openSession()) && ! $session->busy() && ! $this->overBudget($ticket)) {
            $system = RunAgentTurn::systemFor($session->turns()->whereNotNull('proposal')->reorder()->latest('id')->first()?->proposal, app(Workspace::class)->systems($ticket->load('space')));
            if ($system !== null) {
                RunAgentTurn::queuePatch($session, $system, request: $data['text']);

                return back();
            }
        }

        abort_unless(Instructions::credentials() !== [], 409, 'No Anthropic sign-in yet: see Setup.');
        if ($reason = $this->overBudget($ticket)) {
            return back()->with('error', $reason);
        }
        if ($ticket->openSession()?->busy()) {
            return back()->with('error', 'The agent is still working; wait or stop it.');
        }

        $text = trim($data['text']);
        [$session, $earlier] = $this->chat($ticket, $data['language'] ?? null);
        // The first question of a chat comes with the ticket, the quick scan and what an earlier chat found.
        $first = $session->turns()->doesntExist();
        $this->turn($session, 'message', $first ? Instructions::chatStart($ticket, $text, $earlier) : $text, $text);

        return back();
    }

    /** "Build it", "implement this", "go ahead and code it", "bouw het": not "how would you build it?". */
    public static function asksForBuild(string $text): bool
    {
        // "Build it", "implement this"; or "fix", "change", "apply" when it is meant as a go-ahead:
        // "let's get it fixed now", "please apply that", not "the fix is in commit 1f04597".
        $asks = preg_match('/\b(build|implement|code it|bouw|implementeer)\b/i', $text)
            || (preg_match('/\b(fix|fixed|change|apply|make the change|maak|pas aan)\b/i', $text)
                && preg_match("/\\b(let'?s|lets|please|go ahead|now|do it|can you|could you|graag|nu)\\b/i", $text));

        return (bool) $asks
            && ! preg_match('/^\s*(why|what|how|which|should|would|waarom|wat|hoe|welke)\b/i', $text)
            && ! str_contains($text, '?');
    }

    /** Build the ticket on its branch: from the button, with the system and base you picked. */
    public function build(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'system' => ['nullable', 'integer'],
            'base' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'text' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
        ]);
        abort_unless(Instructions::credentials() !== [], 409, 'No Anthropic sign-in yet: see Setup.');
        if ($reason = $this->overBudget($ticket)) {
            return back()->with('error', $reason);
        }
        if ($ticket->openSession()?->busy()) {
            return back()->with('error', 'The agent is still working; wait or stop it.');
        }
        $system = $this->buildSystem($ticket, $ticket->openSession(), $data['system'] ?? null);
        if ($system === null) {
            return back()->with('error', 'Which system should it be built in? Pick one.');
        }

        [$session] = $this->chat($ticket);
        RunAgentTurn::queueBuild($session, $system, $data['base'] ?? null, filled($data['text'] ?? null) ? trim($data['text']) : null);

        return back();
    }

    /** The system to build in: the one asked for, the one built in before, the proposal's, or the only one. */
    private function buildSystem(Ticket $ticket, ?AgentSession $session, ?int $id = null): ?\App\Models\System
    {
        $systems = app(Workspace::class)->systems($ticket->load('space'))->filter->hasCode()->values();
        if ($id !== null) {
            return $systems->firstWhere('id', $id);
        }
        $built = $ticket->branches()->latest('updated_at')->first();

        return ($built ? $systems->firstWhere('id', $built->system_id) : null)
            ?? RunAgentTurn::systemFor($session?->turns()->whereNotNull('proposal')->reorder()->latest('id')->first()?->proposal, $systems);
    }

    /** "Can you create a patch…", "make it a patch", "maak een patch": not "why did the patch fail?". */
    public static function asksForPatch(string $text): bool
    {
        return (bool) preg_match('/\bpatch(es)?\b/i', $text)
            && (bool) preg_match('/\b(make|create|write|prepare|build|generate|give|draft|turn|maak|schrijf|geef)\b/i', $text)
            && ! preg_match('/^\s*(why|what|how|waarom|wat|hoe)\b/i', $text);
    }

    public function restate(Ticket $ticket): RedirectResponse
    {
        return $this->follow($ticket, 'proposal', Instructions::restate($ticket->space));
    }

    /** Refine a development ticket: questions, acceptance criteria, where it lands, a size. */
    public function refine(Ticket $ticket): RedirectResponse
    {
        abort_unless(Instructions::credentials() !== [], 409, 'No Anthropic sign-in yet: see Setup.');
        if ($reason = $this->overBudget($ticket)) {
            return back()->with('error', $reason);
        }
        if ($ticket->openSession()?->busy()) {
            return back()->with('error', 'The agent is still working; wait or stop it.');
        }
        [$session] = $this->chat($ticket);
        $this->turn($session, 'refine', Instructions::refineRequest($ticket));

        return back();
    }

    /** Own work, ready for Jira: the agent drafts its ticket, title, type and description, screenshots in it. */
    public function draftTicket(Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->isLocal(), 404);
        abort_unless(Instructions::credentials() !== [], 409, 'No Anthropic sign-in yet: see Setup.');
        if ($reason = $this->overBudget($ticket)) {
            return back()->with('error', $reason);
        }
        if ($ticket->openSession()?->busy()) {
            return back()->with('error', 'The agent is still working; wait or stop it.');
        }
        [$session] = $this->chat($ticket);
        $this->turn($session, 'ticket', Instructions::ticketDraftRequest($ticket));

        return back();
    }

    /**
     * Own work becomes the Jira ticket made for it: its chats, refinements and branches move
     * onto that ticket, the branches take its key, and the own-work item goes. The ticket is
     * found by its key, or made first when you ask for that and Jira may be written to.
     */
    public function link(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->isLocal(), 404);
        $data = $request->validate([
            'key' => ['required_without:create', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z][A-Za-z0-9_]*-\d+$/'],
            'create' => ['nullable', 'boolean'],
            'title' => ['required_with:create', 'nullable', 'string', 'max:250'],
            'type' => ['required_with:create', 'nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:32000'],
        ], ['key.regex' => 'A Jira key, like DEVBESUP-3300.']);
        if ($ticket->openSession()?->busy()) {
            return back()->with('error', 'The agent is still working; wait or stop it.');
        }
        $space = $ticket->space;
        $jira = $space->jira();

        try {
            if ($data['create'] ?? false) {
                if (config('services.jira.write') !== 'real') {
                    return back()->with('error', 'JIRA_WRITE=dummy: nothing was made in Jira. Copy the draft into a new ticket there, and link it by its key.');
                }
                $key = $jira->createIssue($space->project_key, $data['type'], $data['title'], (string) ($data['description'] ?? ''));
                foreach ($ticket->localFiles() as $file) {
                    $jira->attach($key, $ticket->filesPath().'/'.$file['name']);
                }
            } else {
                $key = strtoupper($data['key']);
            }
            $found = $jira->search('key = "'.$key.'"', [...\App\Jira\JiraClient::TICKET_FIELDS], max: 1)['issues'][0] ?? null;
        } catch (JiraException $e) {
            return back()->with('error', 'Jira: '.$e->getMessage());
        }
        if ($found === null) {
            return back()->withErrors(['key' => "Jira has no {$key} that this tool can see."]);
        }

        $real = Ticket::record($space, $found, now());
        \Illuminate\Support\Facades\DB::transaction(function () use ($ticket, $real) {
            // A chat cannot follow to the new key's folder: closed, the next one starts from its summary.
            $ticket->sessions()->where('state', 'open')->update(['state' => 'closed', 'closed_at' => now()]);
            AgentSession::where('ticket_id', $ticket->id)->update(['ticket_id' => $real->id]);
            foreach ($ticket->branches()->get() as $branch) {
                if ($real->branches()->where('system_id', $branch->system_id)->exists()) {
                    continue; // the real ticket has its own branch there already: this one stays as it is
                }
                $branch->update(['ticket_id' => $real->id]);
                \App\Jobs\RenameBranch::dispatch($branch->fresh(), $real->branchName());
            }
        });
        // The screenshots go along, for the next agent on it.
        if ($ticket->localFiles() !== []) {
            File::copyDirectory($ticket->filesPath(), $real->filesPath());
        }
        File::deleteDirectory($ticket->filesPath());
        AuditEvent::record('work.link', $ticket->key, "became {$real->key}".(($data['create'] ?? false) ? ' (made in Jira)' : ''));
        $ticket->delete();

        return to_route('tickets.show', $real)->with('success', "Linked: this is {$real->key} now, with the work done for it.");
    }

    /** The agent drafts a casebook case; you review it in the form before it exists. */
    public function draftCase(Ticket $ticket): RedirectResponse
    {
        abort_unless(Instructions::credentials() !== [], 409, 'No Anthropic sign-in yet: see Setup.');
        if ($reason = $this->overBudget($ticket)) {
            return back()->with('error', $reason);
        }
        if ($ticket->openSession()?->busy()) {
            return back()->with('error', 'The agent is still working; wait or stop it.');
        }
        // In the chat that is open, or a fresh one with what the last one found (after Done, say).
        [$session, $earlier] = $this->chat($ticket);
        $this->turn($session, 'case', Instructions::caseRequest().(filled($earlier) ? "\n\n{$earlier}" : ''));

        return back();
    }

    /**
     * Done here: how it was solved, whether the agent's answer was right, a note. The chat
     * closes, unless the agent is to write the case first; then it closes by itself later.
     */
    public function done(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'how' => ['required', Rule::in(array_keys(Ticket::DONE_HOW))],
            'verdict' => ['nullable', Rule::in(array_keys(Ticket::VERDICTS))],
            'note' => ['nullable', 'string', 'max:500'],
            'case' => ['nullable', 'boolean'],
        ]);
        $ticket->update(['done_at' => now(), 'done_by' => auth()->id(), 'done_how' => $data['how'], 'done_verdict' => $data['verdict'] ?? null, 'done_note' => $data['note'] ?? null]);
        AuditEvent::record('ticket.done', $ticket->key, Ticket::DONE_HOW[$data['how']].(isset($data['verdict']) ? ', the agent '.strtolower(Ticket::VERDICTS[$data['verdict']]) : ''));

        if ($data['case'] ?? false) {
            return $this->draftCase($ticket)->with('success', 'Done. The agent writes the case now: review it when it is ready, below.');
        }
        $session = $ticket->openSession();
        if ($session !== null && ! $session->busy()) {
            $session->update(['state' => 'closed', 'closed_at' => now()]);
        }

        return back()->with('success', 'Done.');
    }

    /**
     * The whole step at once, as the outbox does it: the reply (public, or an internal note),
     * files, and then the assignee and the status. Your template alone is never posted: with
     * nothing written in it, only the changes go (CONCEPT.md, decided 14 November 2026).
     */
    public function send(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_if($ticket->isLocal(), 409, 'Own work has no Jira ticket yet: link it first.');
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:32000'],
            'visibility' => ['nullable', Rule::in(['public', 'internal'])],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:25600'],
            'transition' => ['nullable', 'string', 'max:20'],
            'fields' => ['nullable', 'array'],
            'assignee' => ['nullable', 'string', 'max:200'],
        ]);
        $jira = $ticket->space->jira();
        $text = self::onlyTemplate((string) ($data['body'] ?? '')) ? '' : trim((string) ($data['body'] ?? ''));
        $files = $request->file('files') ?? [];
        $assignee = $data['assignee'] ?? 'keep';
        $transition = filled($data['transition'] ?? null) ? $data['transition'] : null;
        if ($text === '' && $files === [] && $transition === null && $assignee === 'keep') {
            return back()->with('error', 'Nothing to send: write a reply, or pick a status or an assignee.');
        }

        $plan = array_filter([
            $text !== '' || $files !== [] ? (($data['visibility'] ?? 'public') === 'internal' ? 'an internal note' : 'the reply').($files ? ' with '.count($files).' file'.(count($files) === 1 ? '' : 's') : '') : null,
            $assignee !== 'keep' ? 'the assignee' : null,
            $transition !== null ? 'the status' : null,
        ]);
        if (config('services.jira.write') !== 'real') {
            return back()->with('error', "JIRA_WRITE=dummy: nothing was sent. With it real, {$ticket->key} would now get ".implode(', then ', $plan).'.');
        }

        $done = [];
        try {
            $names = [];
            foreach ($files as $file) {
                $name = Str::limit(preg_replace('/[^A-Za-z0-9._ -]/', '_', $file->getClientOriginalName()) ?: 'file', 120, '');
                $jira->attachFile($ticket->key, $file->getRealPath(), $name);
                $names[] = $name;
            }
            if ($text !== '' || $names !== []) {
                $jira->comment($ticket->key, $text, ($data['visibility'] ?? 'public') === 'internal', $names);
                $done[] = ($data['visibility'] ?? 'public') === 'internal' ? 'internal note posted' : 'reply posted';
            }
            if ($assignee !== 'keep') {
                $accountId = match (true) {
                    $assignee === 'me' => auth()->user()?->jira_account_id ?? throw new RuntimeException('Your Jira account is not linked: link it on Your account.'),
                    $assignee === 'unassign' => null,
                    $assignee === 'reporter' => $jira->issue($ticket->key)['reporter_account_id'] ?? throw new RuntimeException('The reporter cannot be assigned.'),
                    str_starts_with($assignee, 'account:') => substr($assignee, 8),
                    default => throw new RuntimeException('Unknown assignee.'),
                };
                $jira->assign($ticket->key, $accountId);
                $done[] = $accountId === null ? 'unassigned' : 'assigned';
            }
            if ($transition !== null) {
                // Only what Jira offers now: the ticket may have moved since the form was opened.
                $offered = collect($jira->transitions($ticket->key))->firstWhere('id', $transition)
                    ?? throw new RuntimeException('Jira no longer offers that status change from where the ticket is now.');
                $jira->transition($ticket->key, $transition, (array) ($data['fields'] ?? []));
                $done[] = "status {$offered['to']}";
            }
        } catch (JiraException|RuntimeException $e) {
            AuditEvent::record('ticket.send', $ticket->key, ($done ? implode(', ', $done).'; then ' : '').'failed: '.Str::limit($e->getMessage(), 200));

            return back()->with('error', ($done ? 'Done: '.implode(', ', $done).'. Then it stopped: ' : 'Nothing was sent: ').$e->getMessage());
        }

        Cache::forget("ticket.issue.{$ticket->id}");
        // Your own step is no news to you: the change it made in Jira does not count as new activity.
        $ticket->markSeen(auth()->user());
        AuditEvent::record('ticket.send', $ticket->key, implode(', ', $done));

        return back()->with('success', ucfirst(implode(', ', $done)).'.');
    }

    /** Your template with nothing written in it: the frame alone, never posted. */
    public static function onlyTemplate(string $text): bool
    {
        $template = \App\Models\Setting::replyTemplateFor(auth()->user()?->firstName());
        if ($template === null || trim($text) === '') {
            return trim($text) === '';
        }
        $squash = fn (string $value) => preg_replace('/\s+/', '', $value);

        return $squash($text) === $squash(str_replace('{reply}', '', $template));
    }

    /** What the reply form offers: the status changes from here, and who the ticket can go to. */
    public function sendOptions(Ticket $ticket): \Illuminate\Http\JsonResponse
    {
        $jira = $ticket->space->jira();
        try {
            $issue = $jira->issue($ticket->key);

            return response()->json([
                'status' => $issue['status'],
                'transitions' => $jira->transitions($ticket->key),
                'assignee' => $issue['assignee'],
                'reporter' => ['name' => $issue['reporter'], 'assignable' => filled($issue['reporter_account_id'] ?? null) && ! ($issue['reporter_is_customer'] ?? false)],
                'me' => filled(auth()->user()?->jira_account_id),
            ]);
        } catch (JiraException $e) {
            return response()->json(['error' => $e->getMessage(), 'transitions' => []]);
        }
    }

    public function assignable(Request $request, Ticket $ticket): \Illuminate\Http\JsonResponse
    {
        try {
            return response()->json(['people' => $ticket->space->jira()->assignable($ticket->key, (string) $request->query('q', ''))]);
        } catch (JiraException $e) {
            return response()->json(['people' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Not done after all. */
    public function undone(Ticket $ticket): RedirectResponse
    {
        $ticket->update(['done_at' => null, 'done_by' => null, 'done_how' => null, 'done_verdict' => null, 'done_note' => null]);
        AuditEvent::record('ticket.done', $ticket->key, 'not done after all');

        return back();
    }

    /** A quick first look, by hand: for a ticket from before quick scans were on, or again. */
    public function quickScan(Ticket $ticket): RedirectResponse
    {
        abort_unless(Instructions::credentials() !== [], 409, 'No Anthropic sign-in yet: see Setup.');
        if ($reason = $this->overBudget($ticket)) {
            return back()->with('error', $reason);
        }
        QuickScanTicket::start($ticket);

        return back();
    }

    /** Assign the ticket to yourself in Jira; with JIRA_WRITE=dummy, say so and send nothing. */
    public function assignToMe(Request $request, Ticket $ticket): RedirectResponse
    {
        $jira = $ticket->space->jira();
        $user = $request->user();
        if (! filled($user->jira_account_id)) {
            return back()->with('error', 'Link your Jira user first, on Your account.');
        }
        if (config('services.jira.write') !== 'real') {
            return back()->with('error', "JIRA_WRITE=dummy: nothing was sent. With it real, {$ticket->key} would now be assigned to {$user->jira_name}.");
        }

        try {
            // Your account id is the default site's; on another site, you are found by your Jira name.
            $accountId = $ticket->space->jira_site_id
                ? (collect($jira->findUsers((string) $user->jira_name))->firstWhere('name', $user->jira_name)['account_id'] ?? null)
                : $user->jira_account_id;
            if ($accountId === null) {
                return back()->with('error', "{$user->jira_name} is not a user on {$ticket->space->site->name}.");
            }
            $jira->assign($ticket->key, $accountId);
        } catch (JiraException $e) {
            return back()->with('error', $e->getMessage());
        }
        $ticket->update(['assignee' => $user->jira_name, 'assignee_account_id' => $accountId]);
        Cache::forget("ticket.issue.{$ticket->id}");

        return back()->with('success', "{$ticket->key} is assigned to you in Jira.");
    }

    /** Have the agent write its fix as a patch (CONCEPT.md §8). */
    public function patch(Request $request, Ticket $ticket, Workspace $workspace): RedirectResponse
    {
        if ($reason = $this->overBudget($ticket)) {
            return back()->with('error', $reason);
        }
        $session = $ticket->openSession();
        abort_if($session === null, 409, 'Analyse first.');
        if ($session->busy()) {
            return back()->with('error', 'The agent is still working; wait or stop it.');
        }

        $systems = $workspace->systems($ticket->load('space'));
        $data = $request->validate(['system' => ['nullable', 'integer']]);
        $system = isset($data['system'])
            ? $systems->firstWhere('id', $data['system'])
            : RunAgentTurn::systemFor($session->turns()->whereNotNull('proposal')->reorder()->latest('id')->first()?->proposal, $systems);

        if ($system === null) {
            return back()->with('error', 'Which system should the patch be for? Pick one.');
        }

        RunAgentTurn::queuePatch($session, $system);

        return back();
    }

    /** The patch file, for `git am`. */
    public function patchFile(Ticket $ticket, AgentTurn $turn): HttpResponse
    {
        abort_unless($turn->session->ticket_id === $ticket->id && filled($turn->patch), 404);

        $name = Str::slug($turn->patch_meta['subject'] ?? 'fix');
        $name = Str::startsWith($name, Str::slug($ticket->key)) ? $name : Str::slug($ticket->key).'-'.$name;

        return response($turn->patch, 200, [
            'Content-Type' => 'text/x-patch; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.Str::limit($name, 80, '').'.patch"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function stop(Ticket $ticket): RedirectResponse
    {
        $ticket->openSession()?->turns()->whereIn('state', [AgentTurn::QUEUED, AgentTurn::RUNNING])
            ->update(['stop_requested' => true]);
        // A turn still waiting in the queue never starts.
        $ticket->openSession()?->turns()->where('state', AgentTurn::QUEUED)
            ->update(['state' => AgentTurn::STOPPED, 'finished_at' => now()]);

        return back();
    }

    public function close(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:4000']]);
        $session = $ticket->openSession();
        abort_if($session?->busy(), 409, 'Stop the agent first.');
        $session?->update(['state' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id(), 'handover_note' => filled($data['note'] ?? null) ? trim($data['note']) : null]);

        return back()->with('success', 'New chat: your next question starts afresh, with a short summary of this one.');
    }

    /** Make someone else's open session yours: their work stays, under their name. */
    public function takeOver(Ticket $ticket): RedirectResponse
    {
        $session = $ticket->openSession();
        abort_if($session === null, 409, 'There is no session to take over.');
        $from = $session->user?->firstName();
        $session->update(['user_id' => auth()->id()]);

        return back()->with('success', $from ? "It is yours now; {$from}'s work on it stays in the log." : 'It is yours now.');
    }

    /** Hand the reply draft to the outbox, where it waits for you to send it. */
    public function draft(Request $request, Ticket $ticket, OutboxClient $outbox): RedirectResponse
    {
        $jira = $ticket->space->jira();
        $data = $request->validate([
            'body' => ['required', 'string', 'max:32000'],
            'visibility' => ['required', Rule::in(['public', 'internal'])],
        ]);
        $session = $ticket->openSession();

        try {
            $draft = $outbox->draft(
                'ticket-worker:session:'.($session?->id ?? 'none'),
                $ticket->key,
                $data['body'],
                $data['visibility'],
                route('tickets.show', $ticket),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Cache::forget('overview.outbox');

        // The outbox's link to the draft, or its ticket page; only ever a web address.
        $url = $draft['url'] ?? null;
        $url = is_string($url) && preg_match('#^https?://#i', $url) ? $url : $jira->browseUrl($ticket->key);

        return back()->with('success', 'The draft waits in the outbox.')->with('outbox_url', $url);
    }

    /** The budget this work would draw on, when it is spent: the reason, else null. */
    private function overBudget(Ticket $ticket): ?string
    {
        $check = Budget::check($ticket->space, auth()->user());

        return ($check['level'] ?? null) === 'stop' ? $check['text'] : null;
    }

    private function follow(Ticket $ticket, string $kind, string $prompt): RedirectResponse
    {
        if ($reason = $this->overBudget($ticket)) {
            return back()->with('error', $reason);
        }
        $session = $ticket->openSession();
        abort_if($session === null, 409, 'Analyse first.');
        if ($session->busy()) {
            return back()->with('error', 'The agent is still working; wait or stop it.');
        }

        $this->turn($session, $kind, $prompt);

        return back();
    }

    private function turn(AgentSession $session, string $kind, string $prompt, ?string $note = null): void
    {
        RunAgentTurn::dispatch(AgentTurn::create(['agent_session_id' => $session->id, 'user_id' => auth()->id(), 'kind' => $kind, 'prompt' => $prompt, 'note' => $note]));
    }

    private function sessionData(AgentSession $session): array
    {
        $turns = $session->turns()->with(['events', 'user:id,name,username'])->get();

        return [
            'id' => $session->id,
            'reply_language' => $session->reply_language,
            // Whose it is: someone else's can be taken over.
            'owner' => $session->user ? ($session->user->is(auth()->user()) ? null : $session->user->firstName()) : null,
            'cost_usd' => $session->cost_usd,
            'tokens_in' => $turns->sum(fn (AgentTurn $turn) => (int) $turn->tokensIn()),
            'tokens_cached' => $turns->sum(fn (AgentTurn $turn) => (int) $turn->cache_read_tokens),
            'tokens_out' => $turns->sum(fn (AgentTurn $turn) => (int) $turn->output_tokens),
            'busy' => $turns->contains(fn ($turn) => in_array($turn->state, [AgentTurn::QUEUED, AgentTurn::RUNNING], true)),
            'proposal' => $turns->whereNotNull('proposal')->last()?->proposal,
            'refinement' => $turns->whereNotNull('refinement')->last()?->refinement,
            'turns' => $turns->map(fn (AgentTurn $turn) => [
                'id' => $turn->id,
                'kind' => $turn->kind,
                // Who asked: "you", or their first name, so a shared ticket reads as a conversation.
                'by' => $turn->user === null ? 'you' : ($turn->user->is(auth()->user()) ? 'you' : $turn->user->firstName()),
                // Your own words show; the long analysis instruction does not.
                'prompt' => $turn->kind === 'message' ? ($turn->note ?? $turn->prompt) : $turn->note,
                'model' => $turn->model,
                'state' => $turn->state,
                'answer' => $turn->kind === 'message' ? $turn->answer : null,
                'error' => $turn->error,
                'case_draft' => $turn->case_draft !== null,
                'refinement' => $turn->refinement,
                // A patch turn: what it changed, and the patch itself for the diff view.
                'patch' => in_array($turn->kind, ['patch', 'build'], true) ? [
                    'meta' => collect($turn->patch_meta ?? [])->except('system_id'),
                    'text' => $turn->patch,
                ] : null,
                'cost_usd' => $turn->cost_usd,
                'tokens_in' => $turn->tokensIn(),
                'tokens_cached' => $turn->cache_read_tokens,
                'tokens_out' => $turn->output_tokens,
                'started_at' => $turn->started_at?->toIso8601String(),
                'finished_at' => $turn->finished_at?->toIso8601String(),
                'events' => $turn->events->map(fn ($event) => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'summary' => $event->summary,
                    'at' => $event->created_at?->toIso8601String(),
                ]),
            ])->values(),
        ];
    }
}
