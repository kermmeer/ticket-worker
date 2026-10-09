<?php

namespace Tests\Feature;

use App\Agent\FeedQuickScans;
use App\Jobs\QuickScanTicket;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuickScanBatchTest extends TestCase
{
    /** @var list<Ticket> */
    private array $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['agent.claude.api_key' => 'sk-ant-test']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $make = fn (int $n, array $extra = []) => Ticket::create(['space_id' => $space->id, 'jira_id' => (string) $n, 'key' => "SUP-{$n}", 'summary' => 'x',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'jira_created_at' => now()->subDays(10 - $n), ...$extra]);
        $this->tickets = [
            $make(1), $make(2), $make(3, ['quick_scan_state' => 'done']), $make(4, ['hidden_at' => now()]), $make(5, ['quick_scan_state' => 'failed']),
        ];
    }

    private function ids(): array
    {
        return array_map(fn (Ticket $ticket) => $ticket->id, $this->tickets);
    }

    public function test_a_batch_waits_and_starts_one_at_a_time_newest_first(): void
    {
        $this->post('/quick-scans', ['ids' => $this->ids()])->assertSessionHas('success', '3 tickets will get a quick scan, one at a time; your analyses go first.');

        // SUP-1, 2 and the failed 5; not the scanned 3 or the hidden 4. One started: the newest.
        Queue::assertPushed(QuickScanTicket::class, 1);
        $this->assertSame('queued', $this->tickets[4]->fresh()->quick_scan_state);
        $this->assertSame(['pending', 'pending'], [$this->tickets[0]->fresh()->quick_scan_state, $this->tickets[1]->fresh()->quick_scan_state]);
        $this->assertNull($this->tickets[3]->fresh()->quick_scan_state);

        // While it runs, the feeder starts nothing more.
        (new FeedQuickScans)();
        Queue::assertPushed(QuickScanTicket::class, 1);
    }

    public function test_an_analysis_waiting_goes_first(): void
    {
        $this->tickets[0]->update(['quick_scan_state' => 'pending']);
        $session = AgentSession::create(['ticket_id' => $this->tickets[1]->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-00000000000d', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'x']);

        (new FeedQuickScans)();

        Queue::assertNothingPushed();
    }

    public function test_stop_the_rest(): void
    {
        $this->tickets[0]->update(['quick_scan_state' => 'pending']);
        $this->tickets[1]->update(['quick_scan_state' => 'running']);

        $this->delete('/quick-scans')->assertRedirect();

        $this->assertNull($this->tickets[0]->fresh()->quick_scan_state);
        $this->assertSame('running', $this->tickets[1]->fresh()->quick_scan_state, 'The one running finishes.');
    }
}
