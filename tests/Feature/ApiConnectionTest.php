<?php

namespace Tests\Feature;

use App\Agent\ApiToolServer;
use App\Agent\ClaudeRun;
use App\Agent\Workspace;
use App\Jobs\RunAgentTurn;
use App\Models\AgentEvent;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\ApiConnection;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class ApiConnectionTest extends TestCase
{
    private System $system;

    private ApiConnection $api;

    protected function setUp(): void
    {
        parent::setUp();

        config(['agent.systems_path' => sys_get_temp_dir().'/tw-api-systems']);
        $this->system = System::create(['name' => 'boss', 'branch' => 'master', 'state' => System::READY]);
        $this->api = $this->system->apis()->create([
            'name' => 'api', 'base_url' => 'https://boss.example.com/api', 'auth' => 'basic',
            'username' => 'support', 'secret' => 's3cret-token', 'notes' => 'GET /services/{id}',
        ]);
    }

    public function test_the_tool_signs_in_and_the_secret_is_stored_encrypted(): void
    {
        Http::fake(['boss.example.com/*' => Http::response(['id' => 337800, 'recurring' => 300])]);

        $answer = $this->api->call('GET', '/services/337800');

        $this->assertSame(200, $answer['status']);
        $this->assertStringContainsString('"recurring": 300', $answer['body']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://boss.example.com/api/services/337800'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('support:s3cret-token')));
        $this->assertStringNotContainsString('s3cret-token', DB::table('api_connections')->value('secret'));
    }

    public function test_only_paths_under_the_base_address(): void
    {
        foreach (['https://evil.example.com/x', '//evil.example.com/x', '/a/../../admin', '..', 'file:///etc/passwd', '\\\\evil'] as $path) {
            try {
                $this->api->urlFor($path);
                $this->fail("{$path} got through");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('https://boss.example.com/api/services/1?x=2', $this->api->urlFor('services/1?x=2'));
    }

    public function test_read_only_unless_post_is_allowed(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->expectException(InvalidArgumentException::class);
        $this->api->call('DELETE', '/services/1');
    }

    public function test_a_secret_the_api_echoes_never_reaches_the_agent(): void
    {
        Http::fake(['*' => Http::response(['debug' => 'auth was support:s3cret-token'], 401)]);

        $answer = $this->api->call('GET', '/me');

        $this->assertStringNotContainsString('s3cret-token', $answer['body']);
        $this->assertStringContainsString('[secret]', $answer['body']);
    }

    public function test_the_mcp_server_lists_and_calls_and_logs_each_call(): void
    {
        Http::fake(['boss.example.com/*' => Http::response(['recurring' => 300])]);
        $turn = $this->turn();
        $server = ApiToolServer::forTurn($turn, collect([$this->system]));

        $init = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
        $this->assertSame('2025-06-18', $init['result']['protocolVersion']);
        $this->assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $tools = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['result']['tools'];
        $this->assertSame(['list_apis', 'call_api'], array_column($tools, 'name'));
        $this->assertStringNotContainsString('s3cret', json_encode($tools));

        $listed = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_apis']]);
        $this->assertStringContainsString('boss/api', $listed['result']['content'][0]['text']);
        $this->assertStringNotContainsString('s3cret', $listed['result']['content'][0]['text']);

        $called = $server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'call_api', 'arguments' => ['api' => 'boss/api', 'path' => '/services/337800']]]);
        $this->assertFalse($called['result']['isError']);
        $this->assertStringStartsWith('HTTP 200', $called['result']['content'][0]['text']);
        $this->assertSame('api boss/api GET /services/337800 → 200', AgentEvent::where('agent_turn_id', $turn->id)->value('summary'));

        $refused = $server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'call_api', 'arguments' => ['api' => 'boss/api', 'path' => 'https://elsewhere.example.com/']]]);
        $this->assertTrue($refused['result']['isError']);

        $this->assertSame(-32601, $server->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/list'])['error']['code']);
    }

    public function test_the_page_knows_a_secret_is_set_but_never_sees_it(): void
    {
        $this->get('/systems')->assertInertia(fn (Assert $page) => $page
            ->where('systems.0.apis.0.has_secret', true)
            ->missing('systems.0.apis.0.secret'));
        $this->assertStringNotContainsString('s3cret-token', $this->get('/systems')->getContent());
    }

    public function test_saving_without_a_secret_keeps_the_one_there_is(): void
    {
        $this->put("/systems/{$this->system->id}/apis/{$this->api->id}", [
            'name' => 'api', 'base_url' => 'https://boss.example.com/api/v2', 'auth' => 'basic', 'username' => 'support', 'secret' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('s3cret-token', $this->api->fresh()->secret);
        $this->assertSame('https://boss.example.com/api/v2', $this->api->fresh()->base_url);
    }

    public function test_an_analysis_gets_the_tool_server_when_its_systems_have_apis(): void
    {
        config(['agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude', 'agent.workspaces_path' => sys_get_temp_dir().'/tw-api-ws-'.uniqid()]);
        $turn = $this->turn();
        Process::fake(['*claude*' => Process::result(json_encode(['type' => 'result', 'result' => 'ok'])."\n"), '*' => Process::result('')]);

        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));

        Process::assertRan(function ($process) use ($turn) {
            $at = array_search('--mcp-config', $process->command, true);
            $config = $at === false ? null : json_decode($process->command[$at + 1], true);

            return $config !== null
                && $config['mcpServers']['ticket-worker']['args'][1] === 'agent:tools'
                && $config['mcpServers']['ticket-worker']['args'][2] === (string) $turn->id
                && str_contains($process->command[array_search('--allowedTools', $process->command, true) + 1], 'mcp__ticket-worker__call_api')
                && ! str_contains(implode(' ', $process->command), 's3cret');
        });
    }

    private function turn(): AgentTurn
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $space->systems()->attach($this->system);
        $ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-00000000000a', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => AgentTurn::DONE]);

        return AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'Check service 337800']);
    }
}
