<?php

namespace Tests\Feature;

use App\Jira\JiraClient;
use App\Jobs\SyncSpace;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HiddenTicketsTest extends TestCase
{
    public function test_a_hidden_ticket_leaves_the_board_until_it_is_unhidden(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $ticket = Ticket::create([
            'space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Not ours', 'status' => 'Waiting for customer',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->post("/tickets/{$ticket->id}/hide")->assertRedirect();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tickets.0.group', 'hidden'));

        $this->delete("/tickets/{$ticket->id}/hide")->assertRedirect();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tickets.0.group', 'sleeping'));
    }

    public function test_a_sync_leaves_a_hidden_ticket_hidden(): void
    {
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 'secret-token']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $ticket = Ticket::create([
            'space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Not ours',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'hidden_at' => now(),
        ]);
        Http::fake([
            '*/rest/api/3/field' => Http::response([]),
            '*/rest/api/3/search/jql' => Http::response(['issues' => [['id' => '1', 'key' => 'SUP-1', 'fields' => ['summary' => 'Still not ours']]], 'isLast' => true]),
        ]);

        (new SyncSpace($space))->handle(app(JiraClient::class));

        $this->assertNotNull($ticket->fresh()->hidden_at);
        $this->assertSame('Still not ours', $ticket->fresh()->summary);
    }
}
