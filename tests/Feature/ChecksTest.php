<?php

namespace Tests\Feature;

use App\Agent\ApiToolServer;
use App\Agent\Checks;
use App\Agent\ScratchCopy;
use App\Jobs\PrepareChecks;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as Git;
use Tests\TestCase;

class ChecksTest extends TestCase
{
    private string $root;

    private System $system;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/tw-checks-'.uniqid();
        config([
            'services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't',
            'agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude',
            'agent.workspaces_path' => $this->root.'/agent/tickets', 'agent.systems_path' => $this->root.'/systems',
        ]);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'CSV', 'status' => ['name' => 'To Do'], 'attachment' => []], 'comments' => [], 'total' => 0])]);

        $this->system = System::create(['name' => 'billing', 'branch' => 'master', 'state' => System::READY]);
        File::ensureDirectoryExists($this->system->path());
        File::put($this->system->path().'/Export.php', "<?php\n\nreturn ['pdf'];\n");
        File::put($this->system->path().'/.gitignore', "deps/\n");
        foreach ([['init', '--quiet', '--initial-branch=master'], ['add', '.'], ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'start']] as $args) {
            (new Git(['git', ...$args], $this->system->path()))->mustRun();
        }
        // The setup installs a dependency (ignored by git); the checks need it to be there.
        $this->system->update(['checks_setup' => 'mkdir -p deps && echo installed > deps/marker']);
        $this->system->checks()->createMany([
            ['name' => 'deps', 'command' => 'test -f deps/marker', 'position' => 0],
            ['name' => 'csv', 'command' => "grep -q csv Export.php || (echo 'no csv in Export.php'; exit 1)", 'position' => 1],
            ['name' => 'needs vpn', 'command' => 'exit 1', 'enabled' => false, 'note' => 'behind the VPN', 'position' => 2],
        ]);

        $space = SpacesTest::space(['state' => Space::ACTIVE, 'type' => Space::PLAIN]);
        $space->systems()->attach($this->system);
        $this->ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'CSV', 'issue_type' => 'Story', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_the_workspace_is_prepared_and_checks_run_on_a_builds_uncommitted_edits(): void
    {
        (new PrepareChecks($this->system))->handle(app(Checks::class));
        $this->assertSame('ready', $this->system->fresh()->checks_state, (string) $this->system->fresh()->checks_log);

        $copy = ScratchCopy::buildPath($this->ticket, $this->system);
        app(ScratchCopy::class)->prepareBranch($this->system, $copy, 'feature/SUP-1', 'master');
        $results = app(Checks::class)->run($this->system->fresh(), $copy);
        $this->assertSame(['deps' => true, 'csv' => false], collect($results)->pluck('ok', 'name')->all(), 'The one switched off does not run.');
        $this->assertStringContainsString('no csv in Export.php', $results[1]['output']);

        File::put($copy.'/Export.php', "<?php\n\nreturn ['pdf', 'csv'];\n");
        $this->assertSame('deps ✓ · csv ✓', Checks::line(app(Checks::class)->run($this->system->fresh(), $copy)), 'Edits not yet committed count, and the installed deps stay.');
    }

    public function test_a_build_gets_run_checks_and_hands_in_with_the_result(): void
    {
        (new PrepareChecks($this->system))->handle(app(Checks::class));
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000d1', 'model' => 'claude-opus-5']);
        $copy = ScratchCopy::buildPath($this->ticket, $this->system);
        Process::fake(['*claude*' => function () use ($copy) {
            File::put($copy.'/Export.php', "<?php\n\nreturn ['pdf', 'csv'];\n");

            return Process::result(json_encode(['type' => 'result', 'result' => '', 'structured_output' => [
                'commit_message' => 'SUP-1: csv', 'summary' => 'CSV.', 'how_to_test' => 'Export.', 'guide_kind' => 'user', 'guide' => 'Pick CSV.',
            ]])."\n");
        }, '*' => Process::result('')]);

        $turn = RunAgentTurn::queueBuild($session, $this->system->fresh());

        $this->assertSame(['deps' => true, 'csv' => true], collect($turn->fresh()->patch_meta['checks'])->pluck('ok', 'name')->all());
        $this->assertStringContainsString('run_checks', $turn->fresh()->prompt);
        Process::assertRan(fn ($process) => in_array('--allowedTools', $process->command, true)
            && str_contains($process->command[array_search('--allowedTools', $process->command, true) + 1], 'mcp__ticket-worker__run_checks'));

        // The tool itself, as the agent calls it.
        $server = ApiToolServer::forTurn($turn->fresh(), collect([$this->system]));
        $listed = $server->handle(['id' => 1, 'method' => 'tools/list'])['result']['tools'];
        $this->assertSame(['run_checks'], array_column($listed, 'name'));
        $answer = $server->handle(['id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'run_checks', 'arguments' => []]])['result'];
        $this->assertStringContainsString('## csv: passed', $answer['content'][0]['text']);
    }

    public function test_a_private_repository_is_taken_from_a_system_pushed_here(): void
    {
        $lib = System::create(['name' => 'oidc-lib', 'branch' => 'master', 'state' => System::READY]);
        $this->system->update(['checks_redirects' => "git@code.internal.example:dev/oidc.git = oidc-lib\nnonsense line"]);

        $env = app(Checks::class)->redirects($this->system->fresh());
        $this->assertSame('2', $env['GIT_CONFIG_COUNT']);
        $this->assertSame("url.{$lib->path()}.insteadOf", $env['GIT_CONFIG_KEY_1']);
        $this->assertSame('git@code.internal.example:dev/oidc.git', $env['GIT_CONFIG_VALUE_1']);
    }

    public function test_checks_are_set_on_the_systems_page_and_a_new_setup_prepares_again(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->put("/systems/{$this->system->id}/checks", [
            'node' => '20', 'setup' => 'npm ci', 'redirects' => '',
            'list' => [['name' => 'lint', 'command' => 'npx ng lint', 'enabled' => true], ['name' => 'tests', 'command' => 'npx ng test', 'enabled' => false, 'note' => 'no browser']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['lint' => true, 'tests' => false], $this->system->checks()->get()->pluck('enabled', 'name')->all());
        $this->assertSame('20', $this->system->fresh()->checks_node);
        \Illuminate\Support\Facades\Queue::assertPushed(PrepareChecks::class);
        $this->put("/systems/{$this->system->id}/checks", ['node' => 'twenty', 'list' => []])->assertSessionHasErrors('node');
    }
}
