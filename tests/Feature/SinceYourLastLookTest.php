<?php

namespace Tests\Feature;

use App\Agent\CloseIdleChats;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Needs you, new activity and chats that close: since your last look (proposal of 14 November 2026, A). */
class SinceYourLastLookTest extends TestCase
{
    private Ticket $ticket;

    private AgentSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't']);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'x', 'status' => ['name' => 'To Do'], 'attachment' => []], 'comments' => [], 'total' => 0])]);
        $this->ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'jira_updated_at' => now()->subHours(3)]);
        $this->session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000a1', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $this->session->id, 'kind' => 'message', 'prompt' => 'x', 'state' => AgentTurn::DONE, 'finished_at' => now()->subHour()]);
    }

    private function row(): array
    {
        $row = null;
        $this->get('/')->assertInertia(function (Assert $page) use (&$row) {
            $row = collect($page->toArray()['props']['tickets'])->firstWhere('id', $this->ticket->id);
        });

        return $row;
    }

    public function test_an_answer_needs_you_until_you_have_looked(): void
    {
        $this->assertSame(['needs-you', true], [$this->row()['group'], $this->row()['needs_you']]);

        $this->get("/tickets/{$this->ticket->id}");

        $row = $this->row();
        $this->assertFalse($row['needs_you']);
        $this->assertSame('parked', $row['group'], 'Worked on, nothing waiting for you.');
    }

    public function test_jira_changing_it_after_your_look_is_new_activity(): void
    {
        $this->get("/tickets/{$this->ticket->id}");
        $this->ticket->update(['jira_updated_at' => now()->addMinute()]);

        $row = $this->row();
        $this->assertSame(['new-activity', true], [$row['group'], $row['new_activity']]);
    }

    public function test_a_page_polling_is_no_look_and_the_look_before_is_kept_for_new_comments(): void
    {
        $this->get("/tickets/{$this->ticket->id}", ['X-Inertia' => 'true', 'X-Inertia-Partial-Data' => 'session', 'X-Inertia-Partial-Component' => 'Tickets/Show']);
        $this->assertTrue($this->row()['needs_you'], 'Polling did not count as looking.');

        $this->get("/tickets/{$this->ticket->id}");
        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page->whereNot('seenBefore', null));
    }

    public function test_chats_close_when_their_ticket_leaves_or_after_fourteen_quiet_days_but_not_while_working(): void
    {
        $this->ticket->update(['left_at' => now()]);
        $quiet = AgentSession::create(['ticket_id' => Ticket::create(['space_id' => $this->ticket->space_id, 'jira_id' => '2', 'key' => 'SUP-2', 'summary' => 'y', 'first_seen_at' => now(), 'last_seen_at' => now()])->id,
            'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000a2', 'model' => 'claude-opus-5']);
        $quiet->forceFill(['created_at' => now()->subDays(20)])->save();
        AgentTurn::create(['agent_session_id' => $quiet->id, 'kind' => 'message', 'prompt' => 'x', 'state' => AgentTurn::DONE])->forceFill(['created_at' => now()->subDays(15)])->save();
        $working = AgentSession::create(['ticket_id' => Ticket::create(['space_id' => $this->ticket->space_id, 'jira_id' => '3', 'key' => 'SUP-3', 'summary' => 'z', 'first_seen_at' => now(), 'last_seen_at' => now(), 'left_at' => now()])->id,
            'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000a3', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $working->id, 'kind' => 'message', 'prompt' => 'x', 'state' => AgentTurn::RUNNING]);

        (new CloseIdleChats)();

        $this->assertSame(['closed', 'closed', 'open'], [$this->session->fresh()->state, $quiet->fresh()->state, $working->fresh()->state]);
    }
}
