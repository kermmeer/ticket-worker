<?php

namespace App\Http\Controllers;

use App\Agent\Instructions;
use App\Jira\JiraClient;
use App\Jira\JiraException;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Ticket;
use App\Outbox\OutboxClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(Ticket $ticket, JiraClient $jira): Response
    {
        $ticket->load('space');

        try {
            $issue = Cache::remember("ticket.issue.{$ticket->key}", 60, fn () => $jira->issue($ticket->key));
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
                'slas' => $ticket->space->visibleSlas($ticket->slas),
                'url' => $jira->browseUrl($ticket->key),
                'space' => ['label' => $ticket->space->label, 'colour' => $ticket->space->colour, 'type' => $ticket->space->type],
            ],
            'issue' => $issue,
            'issueError' => $issueError,
            'session' => $session ? $this->sessionData($session) : null,
            'languages' => self::LANGUAGES,
            'agentReady' => Instructions::credentials() !== [],
            'outboxReady' => app(OutboxClient::class)->configured(),
        ]);
    }

    /** Start an analysis: a new conversation, or a fresh analysis in the open one. */
    public function analyse(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate(['language' => ['required', Rule::in(self::LANGUAGES)]]);
        abort_unless(Instructions::credentials() !== [], 409, 'No ANTHROPIC_API_KEY or CLAUDE_CODE_OAUTH_TOKEN yet: see Setup.');

        $session = $ticket->openSession();
        if ($session?->busy()) {
            return back()->with('error', 'The agent is still working on this ticket.');
        }

        $session ??= AgentSession::create([
            'ticket_id' => $ticket->id,
            'claude_session_id' => (string) Str::uuid(),
            'reply_language' => $data['language'],
            'model' => config('agent.claude.model'),
        ]);
        $session->update(['reply_language' => $data['language']]);

        $this->turn($session, 'analysis', Instructions::analysis($ticket));

        return back();
    }

    public function message(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:8000']]);

        return $this->follow($ticket, 'message', $data['text']);
    }

    public function restate(Ticket $ticket): RedirectResponse
    {
        return $this->follow($ticket, 'proposal', Instructions::restate());
    }

    /** The agent drafts a casebook case; you review it in the form before it exists. */
    public function draftCase(Ticket $ticket): RedirectResponse
    {
        return $this->follow($ticket, 'case', Instructions::caseRequest());
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

    public function close(Ticket $ticket): RedirectResponse
    {
        $session = $ticket->openSession();
        abort_if($session?->busy(), 409, 'Stop the agent first.');
        $session?->update(['state' => 'closed']);

        return back()->with('success', 'Session closed. Analyse starts a new one.');
    }

    /** Hand the reply draft to jira-outbox, where it waits for you to send it. */
    public function draft(Request $request, Ticket $ticket, OutboxClient $outbox): RedirectResponse
    {
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

        return back()->with('success', 'The draft waits in the outbox: '.($draft['url'] ?? ''));
    }

    private function follow(Ticket $ticket, string $kind, string $prompt): RedirectResponse
    {
        $session = $ticket->openSession();
        abort_if($session === null, 409, 'Analyse first.');
        if ($session->busy()) {
            return back()->with('error', 'The agent is still working; wait or stop it.');
        }

        $this->turn($session, $kind, $prompt);

        return back();
    }

    private function turn(AgentSession $session, string $kind, string $prompt): void
    {
        RunAgentTurn::dispatch(AgentTurn::create(['agent_session_id' => $session->id, 'kind' => $kind, 'prompt' => $prompt]));
    }

    private function sessionData(AgentSession $session): array
    {
        $turns = $session->turns()->with('events')->get();

        return [
            'id' => $session->id,
            'reply_language' => $session->reply_language,
            'cost_usd' => $session->cost_usd,
            'tokens_in' => $turns->sum(fn (AgentTurn $turn) => (int) $turn->tokensIn()),
            'tokens_cached' => $turns->sum(fn (AgentTurn $turn) => (int) $turn->cache_read_tokens),
            'tokens_out' => $turns->sum(fn (AgentTurn $turn) => (int) $turn->output_tokens),
            'busy' => $turns->contains(fn ($turn) => in_array($turn->state, [AgentTurn::QUEUED, AgentTurn::RUNNING], true)),
            'proposal' => $turns->whereNotNull('proposal')->last()?->proposal,
            'turns' => $turns->map(fn (AgentTurn $turn) => [
                'id' => $turn->id,
                'kind' => $turn->kind,
                // Your own words show; the long analysis instruction does not.
                'prompt' => $turn->kind === 'message' ? $turn->prompt : null,
                'state' => $turn->state,
                'answer' => $turn->kind === 'message' ? $turn->answer : null,
                'error' => $turn->error,
                'case_draft' => $turn->case_draft !== null,
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
