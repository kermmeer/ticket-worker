<?php

namespace Tests\Feature;

use App\Jira\JiraClient;
use App\Jobs\SyncSpace;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncSpaceTest extends TestCase
{
    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jira.base' => 'https://example.atlassian.net',
            'services.jira.email' => 'me@example.com',
            'services.jira.token' => 'secret-token',
        ]);
        $this->space = SpacesTest::space(['state' => Space::ACTIVE]);

        // A service space asks which fields are SLAs first; none here (SlasTest covers them).
        Http::fake(['*/rest/api/3/field' => Http::response([])]);
    }

    private function issue(string $id, string $summary = 'Something broke'): array
    {
        return ['id' => $id, 'key' => "SUP-{$id}", 'fields' => [
            'summary' => $summary,
            'status' => ['name' => 'Waiting for support', 'statusCategory' => ['key' => 'new']],
            'priority' => ['name' => 'High'],
            'issuetype' => ['name' => 'Incident'],
            'reporter' => ['displayName' => 'A. Reporter'],
            'assignee' => null,
            'created' => '2026-09-30T10:00:00.000+0200',
            'updated' => '2026-10-01T15:30:00.000+0200',
        ]];
    }

    private function sync(): void
    {
        (new SyncSpace($this->space))->handle(app(JiraClient::class));
    }

    public function test_a_sync_records_every_page_of_open_tickets(): void
    {
        Http::fakeSequence('*/rest/api/3/search/jql')
            ->push(['issues' => [$this->issue('1')], 'nextPageToken' => 'next', 'isLast' => false])
            ->push(['issues' => [$this->issue('2', 'Export stops')], 'isLast' => true]);

        $this->sync();

        $this->assertSame(['SUP-1', 'SUP-2'], Ticket::orderBy('key')->pluck('key')->all());
        $ticket = Ticket::firstWhere('key', 'SUP-1');
        $this->assertSame(['Waiting for support', 'High', 'A. Reporter'], [$ticket->status, $ticket->priority, $ticket->reporter]);
        $this->assertNotNull($this->space->fresh()->synced_at);
        $this->assertNull($this->space->fresh()->sync_error);
    }

    public function test_a_ticket_the_next_full_sync_misses_has_left_and_can_come_back(): void
    {
        Http::fakeSequence('*/rest/api/3/search/jql')
            ->push(['issues' => [$this->issue('1'), $this->issue('2')], 'isLast' => true])
            ->push(['issues' => [$this->issue('1')], 'isLast' => true])
            ->push(['issues' => [$this->issue('1'), $this->issue('2')], 'isLast' => true]);

        $this->sync();
        $this->sync();
        $this->assertNotNull(Ticket::firstWhere('key', 'SUP-2')->left_at);
        $this->assertSame(1, Ticket::open()->count());

        $this->sync();
        $this->assertNull(Ticket::firstWhere('key', 'SUP-2')->left_at);
    }

    public function test_a_failed_sync_lets_nobody_leave(): void
    {
        Http::fakeSequence('*/rest/api/3/search/jql')
            ->push(['issues' => [$this->issue('1')], 'isLast' => true])
            ->push(['errorMessages' => ['The value of the field is invalid.']], 400);

        $this->sync();
        $this->sync();

        $this->assertSame(1, Ticket::open()->count());
        $this->assertStringContainsString('The value of the field is invalid.', $this->space->fresh()->sync_error);
    }

    public function test_only_an_active_space_syncs(): void
    {
        Http::fake();
        $this->space->update(['state' => Space::PAUSED]);

        $this->sync();

        Http::assertNothingSent();
    }
}
