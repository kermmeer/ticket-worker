<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\Ticket;
use App\Outbox\OutboxClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    private function client(): OutboxClient
    {
        return new OutboxClient('http://outbox.test', 'outbox-token');
    }

    public function test_a_draft_goes_over_with_the_token_and_where_it_came_from(): void
    {
        Http::fake(['outbox.test/api/v1/drafts' => Http::response(['id' => 'd1', 'state' => 'draft', 'url' => 'https://outbox.example.com/SUP-1?draft=d1'], 201)]);

        $draft = $this->client()->draft('ticket-worker:proposal:7', 'SUP-1', "Thanks.\n\nFixed.", 'internal', 'https://ticket-worker.example.com/');

        $this->assertSame('draft', $draft['state']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer outbox-token')
            && $request['externalId'] === 'ticket-worker:proposal:7'
            && $request['visibility'] === 'internal'
            && $request['source']['name'] === 'Ticket Worker');
    }

    public function test_the_check_creates_nothing_and_tells_a_bad_token_from_a_good_one(): void
    {
        // Fakes stack rather than replace each other: one sequence, one answer per call.
        Http::fakeSequence()->push(['error' => 'No such draft'], 404)->push([], 401);

        $this->assertNull($this->client()->check());
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
        $this->assertSame('The outbox refused the token.', (new OutboxClient('http://outbox.test', 'wrong'))->check());

        $this->assertStringContainsString('OUTBOX_URL', (new OutboxClient(null, null))->check());
    }

    public function test_the_overview_shows_what_is_waiting_and_the_status_it_will_set(): void
    {
        config(['services.outbox.url' => 'http://outbox.test', 'services.outbox.token' => 'outbox-token']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Broken', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Ticket::create(['space_id' => $space->id, 'jira_id' => '2', 'key' => 'SUP-2', 'summary' => 'Quiet', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Http::fake(['outbox.test/api/v1/scheduled' => Http::response(['messages' => [[
            'id' => 'm1', 'issueKey' => 'SUP-1', 'state' => 'scheduled', 'sendAt' => '2026-10-05T06:00:00.000Z', 'visibility' => 'public',
            'transition' => ['toStatus' => 'Resolved', 'toCategory' => 'done'], 'assignee' => null, 'url' => 'https://outbox.example.com/SUP-1',
        ]]])]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('tickets', fn ($tickets) => $tickets->firstWhere('key', 'SUP-1')['outbox'][0]['to_status'] === 'Resolved'
                && $tickets->firstWhere('key', 'SUP-1')['outbox'][0]['to_tone'] === 'green'
                && $tickets->firstWhere('key', 'SUP-2')['outbox'] === []));
    }

    public function test_the_overview_works_when_the_outbox_does_not_answer(): void
    {
        config(['services.outbox.url' => 'http://outbox.test', 'services.outbox.token' => 'outbox-token']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Broken', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Http::fake(['*' => Http::response('down', 502)]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tickets.0.outbox', []));
    }
}
