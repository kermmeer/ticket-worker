<?php

namespace Tests\Feature;

use App\Agent\ApiDocs;
use App\Agent\ApiToolServer;
use App\Agent\ClaudeRun;
use App\Jobs\WriteApiDocs;
use App\Models\ApiConnection;
use App\Models\System;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process as Git;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    private System $system;

    private ApiConnection $api;

    private const SPEC = <<<'YAML'
    openapi: 3.0.0
    info:
      title: 'Order API'
    servers:
      - url: https://ordering.example.com/api
    paths:
      /api/external/order:
        get:
          summary: 'List all orders.'
          parameters:
            - name: status
              in: query
              schema: { type: integer }
          responses:
            '200': { description: 'The orders.' }
        post:
          summary: 'Place an order.'
          responses:
            '201': { description: 'Created.' }
    YAML;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.systems_path' => sys_get_temp_dir().'/tw-apidocs-'.uniqid(), 'agent.workspaces_path' => sys_get_temp_dir().'/tw-apidocs-ws-'.uniqid().'/tickets',
            'agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude']);
        $this->system = System::create(['name' => 'boss', 'branch' => 'master', 'state' => System::READY]);
        File::ensureDirectoryExists($this->system->path());
        File::put($this->system->path().'/a.php', "<?php\n");
        $this->api = $this->system->apis()->create(['name' => 'ordering-api', 'base_url' => 'https://ordering.example.com/api', 'auth' => 'none', 'access' => 'read']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->system->path());
        parent::tearDown();
    }

    private function commit(): void
    {
        foreach ([['init', '--quiet', '--initial-branch=master'], ['add', '.'], ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'start']] as $args) {
            (new Git(['git', ...$args], $this->system->path()))->mustRun();
        }
    }

    public function test_a_spec_in_the_code_named_after_the_api_becomes_its_docs_without_an_agent(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);
        Process::fake();
        File::ensureDirectoryExists($this->system->path().'/swagger-ui/docs');
        File::put($this->system->path().'/swagger-ui/docs/ordering.yaml', self::SPEC);
        $this->commit();

        (new WriteApiDocs($this->api))->handle(app(ApiDocs::class), app(ClaudeRun::class));

        $api = $this->api->fresh();
        $this->assertSame('ready', $api->docs_state, (string) $api->docs_error);
        $this->assertSame(['GET /external/order', 'POST /external/order'], array_map(fn ($e) => "{$e['method']} {$e['path']}", $api->docs), 'Under the base address: its /api is not doubled.');
        $this->assertSame('status', $api->docs[0]['params'][0]['name']);
        $this->assertStringContainsString('swagger-ui/docs/ordering.yaml', $api->docs_source);
        Process::assertNotRan(fn ($process) => in_array('claude', $process->command, true));
    }

    public function test_a_spec_the_api_serves_is_read_first(): void
    {
        Http::fake(['ordering.example.com/api/openapi.json' => Http::response(json_decode(json_encode(\Symfony\Component\Yaml\Yaml::parse(self::SPEC)), true)), '*' => Http::response('', 404)]);
        Process::fake();
        $this->commit();

        (new WriteApiDocs($this->api))->handle(app(ApiDocs::class), app(ClaudeRun::class));

        $this->assertSame('OpenAPI spec served at /openapi.json', $this->api->fresh()->docs_source);
        $this->assertCount(2, $this->api->fresh()->docs);
    }

    public function test_without_a_spec_an_agent_writes_them_from_the_code(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->commit();
        $endpoints = [['method' => 'GET', 'path' => '/invoice/{id}', 'summary' => 'One invoice.', 'params' => [['name' => 'id', 'in' => 'path', 'type' => 'integer', 'required' => true]], 'source' => 'boss/billing/InvoiceController.php:40']];
        Process::fake(['*claude*' => Process::result(json_encode(['type' => 'result', 'result' => '', 'structured_output' => ['endpoints' => $endpoints], 'total_cost_usd' => 0.4])."\n"), '*' => Process::result('')]);

        (new WriteApiDocs($this->api))->handle(app(ApiDocs::class), app(ClaudeRun::class));

        $api = $this->api->fresh();
        $this->assertSame([$endpoints[0]['path'], 0.4], [$api->docs[0]['path'], $api->docs_cost_usd]);
        $this->assertStringContainsString('written from the code of boss', $api->docs_source);
        Process::assertRan(fn ($process) => in_array('--json-schema', $process->command, true) && in_array($this->system->path(), $process->command, true));
    }

    public function test_the_agent_looks_endpoints_up_instead_of_guessing(): void
    {
        $this->api->update(['docs' => ApiDocs::endpoints(\Symfony\Component\Yaml\Yaml::parse(self::SPEC), $this->api)]);
        $this->assertContains('mcp__ticket-worker__api_docs', ApiToolServer::toolNamesFor([$this->system]));

        $server = new ApiToolServer(collect([$this->api->fresh('system')]));
        $answer = $server->handle(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'api_docs', 'arguments' => ['api' => 'boss/ordering-api', 'search' => 'place order']]])['result'];

        $this->assertStringContainsString('POST /external/order: Place an order.', $answer['content'][0]['text']);
        $this->assertStringNotContainsString('List all orders', $answer['content'][0]['text']);
    }

    public function test_everyone_reads_the_docs_and_editors_write_them(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->api->update(['docs' => [['method' => 'GET', 'path' => '/x', 'summary' => 'X', 'params' => []]], 'docs_source' => 'OpenAPI spec served at /openapi.json']);
        auth()->user()->update(['role' => 'member']);

        $this->get('/apis')->assertInertia(fn (Assert $page) => $page->component('ApiDocs/Index')->where('apis.0.endpoints', 1));
        $this->get("/apis/{$this->api->id}")->assertInertia(fn (Assert $page) => $page->component('ApiDocs/Show')->where('endpoints.0.path', '/x'));
        $this->post("/systems/{$this->system->id}/apis/{$this->api->id}/docs")->assertForbidden();

        auth()->user()->update(['role' => 'editor']);
        $this->post("/systems/{$this->system->id}/apis/{$this->api->id}/docs")->assertSessionHas('success');
        \Illuminate\Support\Facades\Queue::assertPushed(WriteApiDocs::class);
    }
}
