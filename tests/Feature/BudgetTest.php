<?php

namespace Tests\Feature;

use App\Agent\Budget;
use App\Agent\FeedQuickScans;
use App\Jobs\QuickScanTicket;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    private Space $space;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['agent.claude.api_key' => 'sk-ant-test']);
        $this->space = SpacesTest::space(['state' => Space::ACTIVE]);
        $this->ticket = Ticket::create(['space_id' => $this->space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'user_id' => auth()->id(), 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000014', 'model' => 'm']);
        AgentTurn::create(['agent_session_id' => $session->id, 'user_id' => auth()->id(), 'kind' => 'analysis', 'prompt' => 'x', 'state' => 'done', 'cost_usd' => 8.5]);
    }

    public function test_no_budget_means_no_limit(): void
    {
        $this->assertNull(Budget::check($this->space, auth()->user()));
    }

    public function test_a_space_budget_warns_at_80_percent_and_stops_at_100(): void
    {
        $this->space->update(['settings' => ['monthly_budget_usd' => 10]]);
        $this->assertSame('warn', Budget::check($this->space->fresh(), auth()->user())['level']);
        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page->where('budget.level', 'warn'));

        $this->space->update(['settings' => ['monthly_budget_usd' => 8]]);
        $this->post("/tickets/{$this->ticket->id}/messages", ['text' => 'Again?'])->assertSessionHas('error');
        $this->post("/tickets/{$this->ticket->id}/quick-scan")->assertSessionHas('error');
        $this->assertSame(1, AgentTurn::count());
    }

    public function test_a_persons_budget_stops_their_work_and_their_batches(): void
    {
        auth()->user()->update(['monthly_budget_usd' => 5]);
        $this->post("/tickets/{$this->ticket->id}/analyse", ['language' => 'auto'])->assertSessionHas('error');

        $this->ticket->update(['quick_scan_state' => 'pending', 'quick_scan_user_id' => auth()->id()]);
        (new FeedQuickScans)();
        Queue::assertNotPushed(QuickScanTicket::class);
        $this->assertSame('failed', $this->ticket->fresh()->quick_scan_state);
    }

    public function test_an_admin_sets_a_persons_budget(): void
    {
        $this->put('/people/'.auth()->id().'/budget', ['monthly_budget_usd' => 50])->assertRedirect();

        $this->assertSame(50.0, auth()->user()->fresh()->monthly_budget_usd);
    }
}
