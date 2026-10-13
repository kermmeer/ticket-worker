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
use App\Models\Ticket;
use App\Outbox\OutboxClient;
use App\Settings\SpaceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
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

        try {
            $issue = Cache::remember("ticket.issue.{$ticket->id}", 60, fn () => $jira->issue($ticket->key));
            $issueError = null;
        } catch (JiraException $e) {
            $issue = null;
            $issueError = $e->getMessage();
        }

        $session = $ticket->openSession();

        return Inertia::render('Tickets/Show', [
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
                'url' => $jira->browseUrl($ticket->key),
                'open_label' => $ticket->space->openLabel(),
                'space' => ['label' => $ticket->space->label, 'colour' => $ticket->space->colour, 'type' => $ticket->space->type],
                'quick_scan' => $ticket->quickScanData(),
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
            'systems' => app(Workspace::class)->systems($ticket)->map(fn ($system) => ['id' => $system->id, 'name' => $system->name])->values(),
            'outboxReady' => app(OutboxClient::class)->configured(),
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

    /** The agent drafts a casebook case; you review it in the form before it exists. */
    public function draftCase(Ticket $ticket): RedirectResponse
    {
        return $this->follow($ticket, 'case', Instructions::caseRequest());
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
                // A patch turn: what it changed, and the patch itself for the diff view.
                'patch' => $turn->kind === 'patch' ? [
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
