<?php

namespace Tests\Feature;

use App\Agent\StaleRuns;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use Tests\TestCase;

class StaleRunsTest extends TestCase
{
    public function test_a_turn_running_past_its_time_limit_is_marked_interrupted(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-00000000000b', 'model' => 'claude-opus-5']);
        $dead = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => AgentTurn::RUNNING, 'started_at' => now()->subHour()]);
        $alive = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'x', 'state' => AgentTurn::RUNNING, 'started_at' => now()->subMinutes(5)]);

        (new StaleRuns)();

        $this->assertSame(AgentTurn::FAILED, $dead->fresh()->state);
        $this->assertStringStartsWith('Interrupted', $dead->fresh()->error);
        $this->assertSame(AgentTurn::RUNNING, $alive->fresh()->state);
    }
}
