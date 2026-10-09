<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use App\Models\Space;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HandoverTest extends TestCase
{
    public function test_take_over_and_leave_a_note_for_the_next_person(): void
    {
        config(['services.jira.base' => '']);
        $stijn = User::factory()->create(['username' => 'stijn', 'name' => 'Stijn Example']);
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'user_id' => $stijn->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000015', 'model' => 'm']);

        $this->get("/tickets/{$ticket->id}")->assertInertia(fn (Assert $page) => $page->where('session.owner', 'Stijn'));
        $this->post("/tickets/{$ticket->id}/take-over")->assertSessionHas('success');
        $this->assertSame(auth()->id(), $session->fresh()->user_id);

        $this->post("/tickets/{$ticket->id}/close", ['note' => 'Credit note 4711 is the culprit; waiting on Finance.'])->assertRedirect();
        $this->get("/tickets/{$ticket->id}")->assertInertia(fn (Assert $page) => $page
            ->where('session', null)
            ->where('history.0.handover_note', 'Credit note 4711 is the culprit; waiting on Finance.')
            ->where('history.0.closed_by', 'Test'));
    }
}
