<?php

namespace Tests\Feature;

use App\Outbox\OutboxClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    private function client(): OutboxClient
    {
        return new OutboxClient('http://jira-outbox:5173', 'outbox-token');
    }

    public function test_a_draft_goes_over_with_the_token_and_where_it_came_from(): void
    {
        Http::fake(['jira-outbox:5173/api/v1/drafts' => Http::response(['id' => 'd1', 'state' => 'draft', 'url' => 'https://jira.techfactory.dev/SUP-1?draft=d1'], 201)]);

        $draft = $this->client()->draft('ticket-worker:proposal:7', 'SUP-1', "Thanks.\n\nFixed.", 'internal', 'https://ticketworker.techfactory.dev/');

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
        $this->assertSame('The outbox refused the token.', (new OutboxClient('http://jira-outbox:5173', 'wrong'))->check());

        $this->assertStringContainsString('OUTBOX_URL', (new OutboxClient(null, null))->check());
    }
}
