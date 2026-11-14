<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DoneTest extends TestCase
{
    private Ticket $ticket;

    private AgentSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't', 'agent.claude.api_key' => 'sk-ant-test']);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'x', 'status' => ['name' => 'Resolved', 'statusCategory' => ['key' => 'done']], 'attachment' => []], 'comments' => [], 'total' => 0])]);
        $this->ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'issue_type' => 'Incident', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000d9', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $this->session->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => AgentTurn::DONE, 'finished_at' => now()]);
    }

    public function test_a_ticket_resolved_in_jira_asks_how_and_done_records_it_and_closes_the_chat(): void
    {
        $this->ticket->update(['left_at' => now()]);
        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page->where('ticket.left', true)->where('ticket.worked', true)->where('ticket.done', null));

        $this->post("/tickets/{$this->ticket->id}/done", ['how' => 'data', 'verdict' => 'right', 'note' => 'Credited the doubled line.'])->assertSessionHasNoErrors();

        $ticket = $this->ticket->fresh();
        $this->assertSame(['data', 'right', 'Credited the doubled line.'], [$ticket->done_how, $ticket->done_verdict, $ticket->done_note]);
        $this->assertSame('closed', $this->session->fresh()->state);
        $this->get('/agents')->assertInertia(fn (Assert $page) => $page->where('verdicts.support.right', 1));

        $this->post("/tickets/{$this->ticket->id}/undone");
        $this->assertNull($this->ticket->fresh()->done_at);
    }

    public function test_done_with_a_case_has_the_agent_write_it_and_keeps_the_chat_for_it(): void
    {
        $this->post("/tickets/{$this->ticket->id}/done", ['how' => 'code', 'verdict' => 'partly', 'case' => true])->assertSessionHasNoErrors();

        $this->assertSame('case', AgentTurn::query()->latest('id')->value('kind'));
        $this->assertSame('open', $this->session->fresh()->state);
        $this->post("/tickets/{$this->ticket->id}/done", ['how' => 'nonsense'])->assertSessionHasErrors('how');
    }

    public function test_a_done_ticket_never_needs_you(): void
    {
        $this->ticket->update(['done_at' => now(), 'done_how' => 'reply']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tickets.0.needs_you', false)->where('tickets.0.done', true));
    }
}
