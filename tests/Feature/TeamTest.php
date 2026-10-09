<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamTest extends TestCase
{
    public function test_you_follow_the_spaces_you_work_on(): void
    {
        $billing = SpacesTest::space(['state' => Space::ACTIVE, 'label' => 'Billing', 'project_key' => 'BIL']);
        $support = SpacesTest::space(['state' => Space::ACTIVE, 'label' => 'Support', 'project_key' => 'SUP']);

        $this->put("/spaces/{$billing->id}/follow", ['follow' => true])->assertRedirect();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('spaces', fn ($spaces) => collect($spaces)->firstWhere('id', $billing->id)['followed'] === true
                && collect($spaces)->firstWhere('id', $support->id)['followed'] === false));
        $this->get('/spaces')->assertInertia(fn (Assert $page) => $page->where('spaces.0.followers', ['Test']));

        $this->put("/spaces/{$billing->id}/follow", ['follow' => false]);
        $this->assertSame(0, auth()->user()->followedSpaces()->count());
    }

    public function test_an_admin_sees_what_each_person_used_this_month(): void
    {
        $me = auth()->user();
        $me->update(['role' => 'admin']);
        $other = User::factory()->create(['username' => 'stijn', 'name' => 'Stijn']);
        $ticket = Ticket::create(['space_id' => SpacesTest::space()->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now(),
            'quick_scan_user_id' => $other->id, 'quick_scan_cost_usd' => 0.12, 'quick_scan_at' => now(), 'quick_scan_state' => 'done']);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000012', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'user_id' => $other->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => 'done', 'cost_usd' => 2.0]);
        AgentTurn::create(['agent_session_id' => $session->id, 'user_id' => $me->id, 'kind' => 'message', 'prompt' => 'x', 'state' => 'done', 'cost_usd' => 0.5]);

        $this->get('/account')->assertInertia(fn (Assert $page) => $page
            ->where('account.usage', ['cost' => 0.5, 'turns' => 1, 'scans' => 0])
            ->where('people', fn ($people) => collect($people)->firstWhere('username', 'stijn')['usage'] === ['cost' => 2.12, 'turns' => 1, 'scans' => 1]));
    }
}
