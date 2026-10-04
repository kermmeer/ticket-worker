<?php

namespace Tests\Feature;

use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Jobs\PrepareSystemFolder;
use App\Jobs\ScanSystem;
use App\Models\AgentSession;
use App\Models\System;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScanSystemTest extends TestCase
{
    private string $root;

    private System $system;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/tw-scan-'.uniqid();
        File::ensureDirectoryExists($this->root.'/systems');
        config([
            'agent.systems_path' => $this->root.'/systems',
            'agent.workspaces_path' => $this->root.'/agent/tickets',
            'agent.claude.api_key' => 'sk-ant-test',
            'agent.claude.bin' => 'claude',
        ]);

        $this->system = System::create(['name' => 'billing', 'branch' => 'main']);
        (new PrepareSystemFolder($this->system))->handle();
        $this->commit('README.md', "billing\n", 'First version');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function commit(string $file, string $content, string $message): void
    {
        $path = $this->system->path();
        file_put_contents("{$path}/{$file}", $content);
        foreach ([['git', 'add', $file], ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--quiet', '-m', $message]] as $command) {
            Process::path($path)->run($command)->throw();
        }
    }

    /** Claude Code faked, git real: only commands naming claude are faked. */
    private function fakeClaude(string $answer): void
    {
        Process::fake([
            '*claude*' => Process::describe()
                ->output(json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => $this->system->path().'/README.md']]]]])."\n")
                ->output(json_encode(['type' => 'result', 'result' => $answer, 'total_cost_usd' => 1.25])."\n")
                ->runsFor(iterations: 2),
        ]);
        Process::preventStrayProcesses(false);
    }

    public function test_scanning_needs_a_sign_in_and_queues_for_the_agent_container(): void
    {
        Queue::fake();
        config(['agent.claude.api_key' => null]);
        $this->post("/systems/{$this->system->id}/scan")->assertStatus(409);

        config(['agent.claude.api_key' => 'sk-ant-test']);
        $this->post("/systems/{$this->system->id}/scan")->assertRedirect();

        $this->assertSame('queued', $this->system->fresh()->scan_state);
        Queue::assertPushedOn('agents', ScanSystem::class);
    }

    public function test_a_scan_writes_the_context_and_remembers_its_commit(): void
    {
        $this->system->update(['scan_state' => 'queued']);
        $head = $this->system->headCommit();
        $this->fakeClaude("## What it is\nInvoicing for the shop.");

        (new ScanSystem($this->system))->handle(app(ClaudeRun::class));

        $system = $this->system->fresh();
        $this->assertSame('idle', $system->scan_state, (string) $system->scan_error);
        $this->assertStringContainsString('Invoicing for the shop.', $system->context);
        $this->assertSame($head, $system->context_commit);
        $this->assertSame(1.25, $system->scan_cost_usd);
        $this->assertSame('agent', $system->contexts()->first()->written_by);
        $this->assertStringContainsString('reading billing/README.md', $system->scan_log);
        $this->assertSame(0, $system->commitsBehind());

        $this->commit('Invoice.php', "<?php\n", 'Add invoices');
        $this->assertSame(1, $system->commitsBehind());
    }

    public function test_a_rescan_updates_with_what_changed_since(): void
    {
        $this->system->writeContext("## What it is\nOld map.", $this->system->headCommit(), 'agent');
        $this->commit('Invoice.php', "<?php\n", 'Add invoices');
        $this->system->update(['scan_state' => 'queued']);
        $this->fakeClaude("## What it is\nNew map.");

        (new ScanSystem($this->system))->handle(app(ClaudeRun::class));

        Process::assertRan(fn ($process) => is_array($process->command) && in_array('claude', $process->command, true)
            && str_contains($process->input, 'Old map.') && str_contains($process->input, 'Add invoices'));
        $this->assertSame(2, $this->system->contexts()->count(), 'The old version is kept.');
    }

    public function test_your_edit_is_kept_as_a_version_and_analyses_carry_the_context(): void
    {
        $this->put("/systems/{$this->system->id}/context", ['context' => "## Words\nfactuur → Invoice"])->assertSessionHas('success');

        $this->assertSame('you', $this->system->contexts()->first()->written_by);
        $session = new AgentSession(['reply_language' => 'auto']);
        $this->assertStringContainsString('factuur → Invoice', Instructions::system($session, [$this->system->fresh()]));
    }
}
