<?php

namespace Tests\Feature;

use App\Jira\JiraClient;
use App\Jobs\SyncSpace;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** SLAs as Jira Service Management reports them (shapes taken from the real site, 2026-10-03). */
class SlasTest extends TestCase
{
    private const RESOLUTION = 'customfield_10111';

    private const FIRST_RESPONSE = 'customfield_10112';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jira.base' => 'https://example.atlassian.net',
            'services.jira.email' => 'me@example.com',
            'services.jira.token' => 'secret-token',
        ]);
    }

    private static function cycle(int $remainingMs, bool $breached = false, bool $paused = false, int $goalMs = 172800000): array
    {
        return [
            'breachTime' => ['iso8601' => '2026-10-12T15:41:39+0200', 'epochMillis' => 1791812499906],
            'breached' => $breached,
            'paused' => $paused,
            'goalDuration' => ['millis' => $goalMs, 'friendly' => '48h'],
            'remainingTime' => ['millis' => $remainingMs, 'friendly' => '…'],
        ];
    }

    public function test_each_sla_reads_as_running_paused_breached_met_or_missed(): void
    {
        $slas = Ticket::slasFrom([
            'a' => ['name' => 'Time to resolution', 'ongoingCycle' => self::cycle(168099906)],
            'b' => ['name' => 'Time to first response', 'ongoingCycle' => self::cycle(-3600000, breached: true)],
            'c' => ['name' => 'Time to done', 'ongoingCycle' => self::cycle(7200000, paused: true)],
            'd' => ['name' => 'Time to triage', 'completedCycles' => [self::cycle(26864344)]],
            'e' => ['name' => 'Time to close', 'completedCycles' => [self::cycle(-60000, breached: true)]],
            'f' => ['name' => 'Service restore time', 'completedCycles' => []],
        ], ['a', 'b', 'c', 'd', 'e', 'f']);

        // Running ones first, most urgent on top; finished ones after; an SLA with no cycle is left out.
        $this->assertSame(
            ['Time to first response:breached', 'Time to done:paused', 'Time to resolution:running', 'Time to close:missed', 'Time to triage:met'],
            array_map(fn ($sla) => $sla['name'].':'.$sla['state'], $slas),
        );
        $this->assertSame(-3600000, $slas[0]['remaining_ms']);
        $this->assertSame('2026-10-12T13:41:39+00:00', $slas[2]['due_at']);
    }

    public function test_a_service_space_sync_brings_the_slas_in(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        Http::fake([
            '*/rest/api/3/field' => Http::response([
                ['id' => self::RESOLUTION, 'name' => 'Time to resolution', 'schema' => ['type' => 'sd-servicelevelagreement', 'custom' => 'com.atlassian.servicedesk:sd-sla-field']],
                ['id' => 'customfield_10533', 'name' => 'Date since last comment', 'schema' => ['type' => 'any', 'custom' => 'com.atlassian.jira.toolkit:dayslastcommented']],
            ]),
            '*/rest/api/3/search/jql' => Http::response(['issues' => [[
                'id' => '1', 'key' => 'SUP-1', 'fields' => [
                    'summary' => 'Broken', 'status' => ['name' => 'To Do'], 'assignee' => ['displayName' => 'Alex Moreau'],
                    'created' => '2026-09-30T10:00:00.000+0200', 'updated' => '2026-10-01T15:30:00.000+0200',
                    self::RESOLUTION => ['name' => 'Time to resolution', 'ongoingCycle' => self::cycle(-7200000, breached: true)],
                ],
            ]], 'isLast' => true]),
        ]);

        (new SyncSpace($space))->handle(app(JiraClient::class));

        $ticket = Ticket::firstWhere('key', 'SUP-1');
        $this->assertSame('breached', $ticket->slas[0]['state']);
        $this->assertSame('Alex Moreau', $ticket->assignee);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/search/jql')
            && in_array(self::RESOLUTION, $request['fields'], true)
            && ! in_array('customfield_10533', $request['fields'], true));
    }

    public function test_a_plain_space_does_not_ask_for_slas(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE, 'type' => Space::PLAIN, 'jira_type' => 'software']);
        Http::fake(['*/rest/api/3/search/jql' => Http::response(['issues' => [], 'isLast' => true])]);

        (new SyncSpace($space))->handle(app(JiraClient::class));

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/rest/api/3/field'));
    }

    public function test_the_overview_shows_assignee_created_and_slas(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        Ticket::create([
            'space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Broken', 'status' => 'To Do',
            'assignee' => 'Alex Moreau', 'jira_created_at' => '2026-09-30 08:00:00',
            'slas' => [['name' => 'Time to resolution', 'state' => 'running', 'paused' => false, 'remaining_ms' => 1000, 'goal_ms' => 2000, 'due_at' => null]],
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('tickets.0.assignee', 'Alex Moreau')
            ->where('tickets.0.created_at', '2026-09-30T08:00:00+00:00')
            ->where('tickets.0.slas.0.state', 'running'));
    }
}
