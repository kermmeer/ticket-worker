<?php

namespace Tests\Feature;

use App\Agent\ClaudeRun;
use App\Agent\ScratchCopy;
use App\Agent\Workspace;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Setting;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as Git;
use Tests\TestCase;

class PatchTest extends TestCase
{
    private string $root;

    private Ticket $ticket;

    private System $system;

    private AgentSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/tw-patch-'.uniqid();
        config([
            'services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't',
            'agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude',
            'agent.workspaces_path' => $this->root.'/tickets', 'agent.systems_path' => $this->root.'/systems',
        ]);
        Http::fake(['*' => Http::response(['displayName' => 'Sam Example'])]);

        // A real git repository as the system: the patch is built with real git.
        $this->system = System::create(['name' => 'billing', 'branch' => 'main', 'state' => System::READY]);
        File::ensureDirectoryExists($this->system->path());
        File::put($this->system->path().'/Invoice.php', "<?php\n\nreturn 300;\n");
        foreach ([['init', '--quiet', '--initial-branch=main'], ['add', '.'], ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'start']] as $args) {
            (new Git(['git', ...$args], $this->system->path()))->mustRun();
        }

        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $space->systems()->attach($this->system);
        $this->ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Billed twice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000009', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $this->session->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => AgentTurn::DONE,
            'proposal' => ['system' => 'billing', 'fix_kind' => 'code', 'fix' => 'Bill 300 once']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private static function line(array $event): string
    {
        return json_encode($event)."\n";
    }

    public function test_the_agent_edits_a_copy_and_the_tool_turns_it_into_a_patch_for_git_am(): void
    {
        $copy = ScratchCopy::path($this->ticket, $this->system);
        $answer = ['commit_message' => "SUP-1: bill the trunk once\n\nThe recurring line was added twice.", 'summary' => 'Bills once.', 'how_to_test' => 'Run the September billing.'];

        // Claude, faked: it edits the copy, then answers.
        Process::fake(['*claude*' => function () use ($copy, $answer) {
            File::put($copy.'/Invoice.php', "<?php\n\nreturn 150;\n");

            return Process::result(self::line(['type' => 'result', 'result' => '', 'structured_output' => $answer]));
        }, '*' => Process::result('')]);

        $turn = RunAgentTurn::queuePatch($this->session, $this->system);
        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));
        $turn->refresh();

        $this->assertSame(AgentTurn::DONE, $turn->state, (string) $turn->error);
        $this->assertStringContainsString('Subject: [PATCH] SUP-1: bill the trunk once', $turn->patch);
        $this->assertStringContainsString('-return 300;', $turn->patch);
        $this->assertStringContainsString('+return 150;', $turn->patch);
        $this->assertSame([['path' => 'Invoice.php', 'added' => 1, 'removed' => 1]], $turn->patch_meta['files']);
        $this->assertSame("<?php\n\nreturn 300;\n", File::get($this->system->path().'/Invoice.php'), 'The system itself is untouched.');

        // Writing is allowed in this turn, and only this one.
        Process::assertRan(fn ($process) => in_array('Read,Grep,Glob,Edit,Write', $process->command, true));
        // ...and only in the workspace: the systems are not among its folders.
        Process::assertDidntRun(fn ($process) => in_array('--add-dir', $process->command, true));

        $this->get("/tickets/{$this->ticket->id}/turns/{$turn->id}/patch")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/x-patch; charset=utf-8')
            ->assertDownload('sup-1-bill-the-trunk-once.patch');
    }

    public function test_nothing_changed_means_no_patch(): void
    {
        Process::fake(['*claude*' => Process::result(self::line(['type' => 'result', 'result' => '', 'structured_output' => ['commit_message' => 'x', 'summary' => 'Not a code change after all.', 'how_to_test' => '-']]))]);

        $turn = RunAgentTurn::queuePatch($this->session, $this->system);
        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));

        $this->assertNull($turn->fresh()->patch);
        $this->assertSame(AgentTurn::DONE, $turn->fresh()->state);
    }

    public function test_an_analysis_with_a_code_fix_queues_a_patch_unless_switched_off(): void
    {
        Queue::fake();
        $proposal = ['problem' => 'p', 'system' => 'billing', 'cause' => 'c', 'evidence' => [], 'fix' => 'f', 'fix_kind' => 'code',
            'reply_draft' => 'r', 'confidence' => 'high', 'confidence_reason' => 'x'];
        Process::fake(['*claude*' => Process::result(self::line(['type' => 'result', 'result' => '', 'structured_output' => $proposal])), '*' => Process::result('')]);

        $turn = AgentTurn::create(['agent_session_id' => $this->session->id, 'kind' => 'proposal', 'prompt' => 'restate']);
        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));
        $this->assertSame(1, AgentTurn::where('kind', 'patch')->count());

        Setting::put(Setting::AUTO_PATCH, '0');
        $turn = AgentTurn::create(['agent_session_id' => $this->session->id, 'kind' => 'proposal', 'prompt' => 'restate']);
        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));
        $this->assertSame(1, AgentTurn::where('kind', 'patch')->count());
    }

    public function test_the_button_picks_the_proposals_system(): void
    {
        Queue::fake();

        $this->post("/tickets/{$this->ticket->id}/patch")->assertRedirect();

        $turn = AgentTurn::where('kind', 'patch')->sole();
        $this->assertSame('billing', $turn->patch_meta['system']);
        Queue::assertPushed(RunAgentTurn::class);
    }

    public function test_a_patch_from_another_ticket_is_not_served(): void
    {
        $other = Ticket::create(['space_id' => $this->ticket->space_id, 'jira_id' => '2', 'key' => 'SUP-2', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $turn = AgentTurn::create(['agent_session_id' => $this->session->id, 'kind' => 'patch', 'prompt' => '', 'state' => AgentTurn::DONE, 'patch' => 'From x']);

        $this->get("/tickets/{$other->id}/turns/{$turn->id}/patch")->assertNotFound();
    }
}
