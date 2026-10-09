<?php

namespace Tests\Feature;

use App\Agent\ApiToolServer;
use App\Agent\ClaudeRun;
use App\Agent\Workspace;
use App\Jira\JiraClient;
use App\Jobs\QuickScanTicket;
use App\Jobs\SyncSpace;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuickScanTest extends TestCase
{
    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't',
            'agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude',
            'agent.workspaces_path' => sys_get_temp_dir().'/tw-quick-'.uniqid(), 'agent.systems_path' => sys_get_temp_dir().'/tw-quick-systems',
        ]);
        $this->space = SpacesTest::space(['state' => Space::ACTIVE, 'quick_scan' => true]);
    }

    private function issue(string $id): array
    {
        return ['id' => $id, 'key' => "SUP-{$id}", 'fields' => [
            'summary' => "Ticket {$id}", 'status' => ['name' => 'Open', 'statusCategory' => ['key' => 'new']],
            'created' => '2026-10-09T10:00:00.000+0200', 'updated' => '2026-10-09T10:00:00.000+0200',
        ]];
    }

    /** What Jira lists now. Fakes stack rather than replace, so one fake reads this. */
    private array $inJira = [];

    private bool $faked = false;

    private function syncWith(array $ids): void
    {
        $this->inJira = $ids;
        if (! $this->faked) {
            Http::fake([
                '*/rest/api/3/field' => Http::response([]),
                '*/rest/api/3/search/jql' => fn () => Http::response(['issues' => array_map(fn ($id) => $this->issue($id), $this->inJira), 'isLast' => true]),
            ]);
            $this->faked = true;
        }
        (new SyncSpace($this->space->fresh()))->handle(app(JiraClient::class));
    }

    public function test_only_tickets_that_arrive_after_the_first_sync_get_a_quick_scan(): void
    {
        Queue::fake();

        $this->syncWith(['1', '2']);
        Queue::assertNothingPushed();

        $this->syncWith(['1', '2', '3']);
        Queue::assertPushed(QuickScanTicket::class, 1);
        Queue::assertPushed(QuickScanTicket::class, fn ($job) => $job->ticket->key === 'SUP-3');
        $this->assertSame('queued', Ticket::firstWhere('key', 'SUP-3')->quick_scan_state);
    }

    public function test_a_space_without_quick_scan_scans_nothing(): void
    {
        Queue::fake();
        $this->space->update(['quick_scan' => false]);

        $this->syncWith(['1']);
        $this->syncWith(['1', '2']);

        Queue::assertNothingPushed();
    }

    public function test_the_scan_runs_light_and_keeps_its_answer(): void
    {
        $ticket = Ticket::create(['space_id' => $this->space->id, 'jira_id' => '9', 'key' => 'SUP-9', 'summary' => 'Billed 600 instead of 300', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'x', 'status' => ['name' => 'Open'], 'attachment' => []], 'comments' => [], 'total' => 0])]);
        $answer = ['summary' => 'Trunk billed twice in September', 'system' => 'boss', 'kind' => 'data', 'likely_cause' => 'Duplicate recurring line',
            'data_check' => ['still_present' => 'yes', 'evidence' => 'Invoice 483646 still has two recurring lines'], 'next_step' => 'Credit one line', 'confidence' => 'medium'];
        Process::fake(['*claude*' => Process::result(json_encode(['type' => 'result', 'result' => '', 'structured_output' => $answer, 'total_cost_usd' => 0.07,
            'usage' => ['input_tokens' => 900, 'output_tokens' => 300]])."\n"), '*' => Process::result('')]);

        QuickScanTicket::start($ticket);
        (new QuickScanTicket($ticket))->handle(app(Workspace::class), app(ClaudeRun::class));
        $ticket->refresh();

        $this->assertSame('done', $ticket->quick_scan_state);
        $this->assertSame('yes', $ticket->quick_scan['data_check']['still_present']);
        $this->assertSame(0.07, $ticket->quick_scan_cost_usd);
        Process::assertRan(fn ($process) => in_array('--no-session-persistence', $process->command, true)
            && $process->command[array_search('--model', $process->command, true) + 1] === config('agent.quick.model')
            && $process->command[array_search('--effort', $process->command, true) + 1] === 'low'
            && $process->command[array_search('--max-budget-usd', $process->command, true) + 1] === '0.5'
            && $process->command[array_search('--tools', $process->command, true) + 1] === 'Read,Grep,Glob');

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tickets.0.quick_scan.result.summary', 'Trunk billed twice in September'));
    }

    public function test_a_quick_scan_with_apis_logs_its_calls_in_the_tickets_trail(): void
    {
        Http::fake(['boss.example.com/*' => Http::response(['lines' => 2])]);
        $system = System::create(['name' => 'boss', 'branch' => 'master', 'state' => System::READY]);
        $system->apis()->create(['name' => 'api', 'base_url' => 'https://boss.example.com', 'auth' => 'bearer', 'secret' => 'tok3n-secret']);
        $this->space->systems()->attach($system);
        $ticket = Ticket::create(['space_id' => $this->space->id, 'jira_id' => '9', 'key' => 'SUP-9', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $server = ApiToolServer::forQuickScan($ticket, collect([$system]));
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'call_api', 'arguments' => ['api' => 'boss/api', 'path' => '/invoices/483646']]]);

        $this->assertSame(['api boss/api GET /invoices/483646 → 200'], $ticket->fresh()->quick_scan_steps);
    }

    public function test_the_button_scans_an_older_ticket(): void
    {
        Queue::fake();
        $ticket = Ticket::create(['space_id' => $this->space->id, 'jira_id' => '9', 'key' => 'SUP-9', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->post("/tickets/{$ticket->id}/quick-scan")->assertRedirect();
        $this->post("/tickets/{$ticket->id}/quick-scan")->assertRedirect();

        Queue::assertPushed(QuickScanTicket::class, 1);
    }
}
