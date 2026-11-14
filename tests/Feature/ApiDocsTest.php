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

    private function claude(array $output, float $cost = 0.4): void
    {
        Process::fake(['*claude*' => Process::result(json_encode(['type' => 'result', 'result' => '', 'structured_output' => $output, 'total_cost_usd' => $cost])."\n"), '*' => Process::result('')]);
    }

    private function push(string $file, string $text): void
    {
        File::ensureDirectoryExists(dirname($this->system->path().'/'.$file));
        File::put($this->system->path().'/'.$file, $text);
        foreach ([['add', '.'], ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'more']] as $args) {
            (new Git(['git', ...$args], $this->system->path()))->mustRun();
        }
    }

    private function headCommit(): string
    {
        return trim((new Git(['git', 'rev-parse', 'HEAD'], $this->system->path()))->mustRun()->getOutput());
    }

    public function test_a_spec_in_the_code_is_read_and_the_code_too_for_what_it_leaves_out(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);
        File::ensureDirectoryExists($this->system->path().'/swagger-ui/docs');
        File::put($this->system->path().'/swagger-ui/docs/ordering.yaml', self::SPEC);
        $this->commit();
        $this->claude(['endpoints' => [
            ['method' => 'GET', 'path' => '/external/order', 'summary' => 'Guessed differently.'],
            ['method' => 'DELETE', 'path' => '/external/order/{id}', 'summary' => 'Cancel an order.'],
        ], 'files' => ['boss/ordering/OrderController.php:12']]);

        (new WriteApiDocs($this->api))->handle(app(ApiDocs::class), app(ClaudeRun::class));

        $api = $this->api->fresh();
        $this->assertSame('ready', $api->docs_state, (string) $api->docs_error);
        $this->assertSame(['GET /external/order', 'DELETE /external/order/{id}', 'POST /external/order'], array_map(fn ($e) => "{$e['method']} {$e['path']}", $api->docs), 'Under the base address: its /api is not doubled.');
        $this->assertSame('List all orders.', $api->docs[0]['summary'], 'The spec wins where both describe an endpoint.');
        $this->assertSame('status', $api->docs[0]['params'][0]['name']);
        $this->assertStringContainsString('swagger-ui/docs/ordering.yaml (2), and written from the code of boss', $api->docs_source);
        $this->assertSame(['ordering/OrderController.php', 'swagger-ui/docs/ordering.yaml'], $api->docs_files);
        $this->assertSame($this->headCommit(), $api->docs_commit);
        Process::assertRan(fn ($process) => in_array('--json-schema', $process->command, true) && str_contains($process->input, 'already describes these endpoints')
            && str_contains($process->input, '- POST /external/order: Place an order.'));
    }

    public function test_after_a_push_that_leaves_its_files_alone_the_docs_just_move_on(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->commit();
        $this->api->update(['docs' => [['method' => 'GET', 'path' => '/x', 'summary' => 'X']], 'docs_state' => 'ready', 'docs_commit' => $this->headCommit(), 'docs_files' => ['api/XController.php'],
            'docs_source' => 'written from the code of boss at '.substr($this->headCommit(), 0, 10)]);
        $this->push('web/page.php', "<?php\n");
        Process::fake();

        (new WriteApiDocs($this->api, null, afterPush: true))->handle(app(ApiDocs::class), app(ClaudeRun::class));

        $api = $this->api->fresh();
        $this->assertSame([$this->headCommit(), 'ready', 'written from the code of boss at '.substr($this->headCommit(), 0, 10)], [$api->docs_commit, $api->docs_state, $api->docs_source]);
        Process::assertNotRan(fn ($process) => in_array('claude', $process->command, true));
    }

    public function test_after_a_push_to_its_files_the_agent_reports_what_changed(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->commit();
        $this->push('api/XController.php', "<?php // x and y\n");
        $since = $this->headCommit();
        $this->api->update(['docs' => [['method' => 'GET', 'path' => '/x', 'summary' => 'X'], ['method' => 'GET', 'path' => '/y/{id}', 'summary' => 'Y']],
            'docs_state' => 'ready', 'docs_commit' => $since, 'docs_files' => ['api/XController.php'], 'docs_cost_usd' => 1.5]);
        $this->push('api/XController.php', "<?php // x changed, y gone, z new\n");
        $this->claude(['changed' => [['method' => 'GET', 'path' => '/x', 'summary' => 'X, now paged.'], ['method' => 'POST', 'path' => '/z', 'summary' => 'Z']],
            'removed' => ['GET /y/{yid}'], 'files' => ['api/XController.php']], 0.2);

        (new WriteApiDocs($this->api, null, afterPush: true))->handle(app(ApiDocs::class), app(ClaudeRun::class));

        $api = $this->api->fresh();
        $this->assertSame('ready', $api->docs_state, (string) $api->docs_error);
        $this->assertSame(['GET /x: X, now paged.', 'POST /z: Z'], array_map(fn ($e) => "{$e['method']} {$e['path']}: {$e['summary']}", $api->docs));
        $this->assertSame([$this->headCommit(), 1.7], [$api->docs_commit, $api->docs_cost_usd]);
        Process::assertRan(fn ($process) => in_array('--json-schema', $process->command, true) && str_contains($process->input, '- api/XController.php')
            && str_contains($process->input, 'changes.diff') && str_contains($process->input, '- GET /y/{id}: Y'));
        $diff = dirname(config('agent.workspaces_path')).'/scans/boss-ordering-api/changes.diff';
        $this->assertStringContainsString('z new', File::get($diff));
    }

    public function test_a_push_brings_docs_from_the_code_up_to_date_by_itself_once_it_is_quiet(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['agent.claude.api_key' => 'sk-ant-test']);
        $this->commit();
        $old = $this->headCommit();
        $this->api->update(['docs' => [['method' => 'GET', 'path' => '/x', 'summary' => 'X']], 'docs_state' => 'ready', 'docs_commit' => $old, 'docs_written_at' => now()->subHours(3)]);
        $served = $this->system->apis()->create(['name' => 'served', 'base_url' => 'https://s.example.com', 'auth' => 'none', 'access' => 'read',
            'docs' => [['method' => 'GET', 'path' => '/s', 'summary' => 'S']], 'docs_state' => 'ready', 'docs_source' => 'OpenAPI spec served at /openapi.json']);
        $this->push('api/XController.php', "<?php\n");
        Process::fake(['*rev-parse*' => Process::result($this->headCommit()."\n")]);

        (new \App\Agent\UpdateApiDocs)();
        \Illuminate\Support\Facades\Queue::assertNothingPushed();

        touch($this->system->path().'/.git/logs/HEAD', now()->subMinutes(11)->getTimestamp());
        (new \App\Agent\UpdateApiDocs)();
        \Illuminate\Support\Facades\Queue::assertPushed(WriteApiDocs::class, 1);
        \Illuminate\Support\Facades\Queue::assertPushed(WriteApiDocs::class, fn ($job) => $job->api->is($this->api) && $job->afterPush);
        $this->assertSame('queued', $this->api->fresh()->docs_state);
        $this->assertNull($served->fresh()->docs_commit);
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
        $this->claude(['endpoints' => $endpoints, 'files' => ['billing/InvoiceController.php']]);

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
