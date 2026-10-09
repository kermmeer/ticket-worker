<?php

namespace Tests\Feature;

use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Agent\Workspace;
use App\Jobs\PrepareSystemFolder;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiProjectTest extends TestCase
{
    public function test_an_api_project_is_added_in_one_form_and_ready_at_once(): void
    {
        Queue::fake();

        $this->post('/systems', [
            'kind' => 'api', 'name' => 'boss-api', 'base_url' => 'https://boss.example.com/api',
            'auth' => 'basic', 'username' => 'support', 'secret' => 's3cret', 'notes' => 'GET /services/{id}',
        ])->assertSessionHasNoErrors()->assertRedirect('/systems');

        $system = System::sole();
        $this->assertSame([System::API, System::READY], [$system->kind, $system->state]);
        $this->assertSame('s3cret', $system->apis()->sole()->secret);
        $this->assertSame('GET /services/{id}', $system->apis()->sole()->notes);
        Queue::assertNotPushed(PrepareSystemFolder::class);
    }

    public function test_it_needs_an_address(): void
    {
        $this->post('/systems', ['kind' => 'api', 'name' => 'boss-api', 'auth' => 'none'])->assertSessionHasErrors('base_url');
        $this->assertSame(0, System::count());
    }

    public function test_an_analysis_calls_its_api_and_reads_no_folder_for_it(): void
    {
        config(['agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude', 'agent.workspaces_path' => sys_get_temp_dir().'/tw-apiproj-'.uniqid(),
            'services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'a@b.c', 'services.jira.token' => 't']);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'x', 'status' => ['name' => 'Open'], 'attachment' => []], 'comments' => [], 'total' => 0])]);
        $system = System::create(['name' => 'boss-api', 'kind' => System::API, 'branch' => '-', 'state' => System::READY]);
        $system->apis()->create(['name' => 'api', 'base_url' => 'https://boss.example.com', 'auth' => 'none']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $space->systems()->attach($system);
        $ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-00000000000e', 'model' => 'claude-opus-5']);
        $turn = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'x']);
        Process::fake(['*claude*' => Process::result(json_encode(['type' => 'result', 'result' => 'ok'])."\n"), '*' => Process::result('')]);

        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));

        Process::assertRan(fn ($process) => in_array('--mcp-config', $process->command, true) && ! in_array('--add-dir', $process->command, true));
        $this->assertStringContainsString('boss-api: no code, only APIs to call', Instructions::system($session, collect([$system])));
        $this->assertSame(AgentTurn::DONE, $turn->fresh()->state, (string) $turn->fresh()->error);
    }

    public function test_there_is_no_code_to_scan(): void
    {
        config(['agent.claude.api_key' => 'sk-ant-test']);
        $system = System::create(['name' => 'boss-api', 'kind' => System::API, 'branch' => '-', 'state' => System::READY]);

        $this->post("/systems/{$system->id}/scan")->assertStatus(409);
    }
}
