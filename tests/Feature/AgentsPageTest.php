<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentsPageTest extends TestCase
{
    public function test_it_shows_who_works_on_what_which_sign_in_is_in_use_and_the_settings_in_force(): void
    {
        config(['agent.claude.api_key' => null, 'agent.claude.oauth_token' => 'sk-ant-oat-env', 'agent.claude.model' => 'claude-opus-5']);
        $stijn = User::factory()->create(['username' => 'stijn', 'name' => 'Stijn Example']);
        $space = SpacesTest::space(['state' => Space::ACTIVE, 'settings' => ['model' => 'claude-sonnet-5']]);
        $ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Billed twice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'user_id' => $stijn->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000017', 'model' => 'm']);
        AgentTurn::create(['agent_session_id' => $session->id, 'user_id' => $stijn->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => 'running', 'started_at' => now()]);

        $this->get('/agents')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Agents', true)
            ->where('working.0.who', 'Stijn')
            ->where('working.0.ticket', 'SUP-1')
            ->where('signIns.0.set', false)
            ->where('signIns.1.set', true)
            ->where('signIns.1.what', "Shared (the server's .env)")
            ->where('spaces.0.settings.model', 'claude-sonnet-5')
            ->where('spaces.0.own', ['model']));
        $this->assertFalse(str_contains($this->get('/agents')->getContent(), 'sk-ant-oat-env'));

        // A member sees it too.
        auth()->user()->update(['role' => 'member']);
        $this->get('/agents')->assertOk();
    }
}
