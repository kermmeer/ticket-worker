<?php

namespace Tests\Feature;

use App\Agent\ScratchCopy;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use App\Models\TicketBranch;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process as Git;
use Tests\TestCase;

/** Build: the agent writes the change on the ticket's branch, which lands in the system's repository here. */
class BuildTest extends TestCase
{
    private string $root;

    private Ticket $ticket;

    private System $system;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/tw-build-'.uniqid();
        config([
            'services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't',
            'agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude',
            'agent.workspaces_path' => $this->root.'/tickets', 'agent.systems_path' => $this->root.'/systems',
        ]);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'Export as CSV', 'description' => 'Add a CSV export.', 'status' => ['name' => 'To Do'], 'attachment' => []], 'comments' => [], 'total' => 0])]);

        $this->system = System::create(['name' => 'billing', 'branch' => 'master', 'state' => System::READY]);
        File::ensureDirectoryExists($this->system->path());
        File::put($this->system->path().'/Export.php', "<?php\n\nreturn ['pdf'];\n");
        $this->git(['init', '--quiet', '--initial-branch=master'], ['add', '.'], ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'start']);

        $space = SpacesTest::space(['state' => Space::ACTIVE, 'type' => Space::PLAIN]);
        $space->systems()->attach($this->system);
        $this->ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Export as CSV', 'issue_type' => 'Story', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function git(array ...$commands): string
    {
        $out = '';
        foreach ($commands as $arguments) {
            $out = (new Git(['git', '-c', 'safe.directory=*', ...$arguments], $this->system->path()))->mustRun()->getOutput();
        }

        return $out;
    }

    /** Claude, faked: writes $content into the copy's Export.php, then answers. */
    private function agentWrites(string $content): void
    {
        $copy = ScratchCopy::buildPath($this->ticket, $this->system);
        Process::fake(['*claude*' => function () use ($copy, $content) {
            File::put($copy.'/Export.php', $content);

            return Process::result(json_encode(['type' => 'result', 'result' => '', 'structured_output' => [
                'commit_message' => 'SUP-1: export as CSV', 'summary' => 'Adds CSV.', 'how_to_test' => 'Export a list.',
                'guide_kind' => 'user', 'guide' => "## CSV export\n\nPick CSV in the export menu.",
            ]])."\n");
        }, '*' => Process::result('')]);
    }

    public function test_a_build_lands_on_the_tickets_branch_in_the_systems_repository_and_the_next_one_builds_on_it(): void
    {
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000b1', 'model' => 'claude-opus-5']);

        $this->agentWrites("<?php\n\nreturn ['pdf', 'csv'];\n");
        $turn = RunAgentTurn::queueBuild($session, $this->system);

        $this->assertSame(AgentTurn::DONE, $turn->fresh()->state, (string) $turn->fresh()->error);
        $this->assertSame('feature/SUP-1', $turn->fresh()->patch_meta['branch']);
        $this->assertSame('user', $turn->fresh()->patch_meta['guide_kind']);
        $this->assertStringContainsString("'csv'", $this->git(['show', 'feature/SUP-1:Export.php']), 'On the server, for your clones to fetch.');
        $this->assertSame("<?php\n\nreturn ['pdf'];\n", File::get($this->system->path().'/Export.php'), 'Its checkout stays as it was.');
        $branch = TicketBranch::sole();
        $this->assertSame([1, 1, 1, 1, 'master'], [$branch->commits, $branch->files, $branch->added, $branch->removed, $branch->base]);

        // A follow-up builds on the branch: a second commit, not a fresh start.
        $this->agentWrites("<?php\n\nreturn ['pdf', 'csv', 'xlsx'];\n");
        RunAgentTurn::queueBuild($session, $this->system, request: 'also xlsx');
        $this->assertSame(2, $branch->fresh()->commits);
        $this->assertStringContainsString('build on it, do not start over', AgentTurn::query()->latest('id')->value('prompt') ?? '');

        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page
            ->where('ticket.kind', 'development')
            ->where('ticket.branches.0.name', 'feature/SUP-1')
            ->where('ticket.branches.0.commits', 2)
            ->where('ticket.branches.0.fetch', 'git fetch ticket-worker feature/SUP-1 && git checkout feature/SUP-1'));
    }

    public function test_refine_writes_questions_criteria_and_a_size_on_the_chat_model(): void
    {
        config(['agent.chat.model' => 'claude-sonnet-5']);
        $refinement = ['summary' => 'Export lists as CSV.', 'questions' => ['Which columns?'], 'acceptance_criteria' => ['A CSV downloads'], 'code' => ['billing/Export.php: add csv'], 'size' => 'S', 'size_reason' => 'One exporter.'];
        Process::fake(['*claude*' => Process::describe()->output(json_encode(['type' => 'result', 'result' => '', 'structured_output' => $refinement])."\n")->runsFor(iterations: 1), '*' => Process::result('')]);

        $this->post("/tickets/{$this->ticket->id}/refine")->assertSessionHasNoErrors();

        $turn = AgentTurn::sole();
        $this->assertSame(['refine', AgentTurn::DONE, 'claude-sonnet-5'], [$turn->kind, $turn->state, $turn->model]);
        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page
            ->where('ticket.refinement.size', 'S')
            ->where('ticket.refinement.questions.0', 'Which columns?'));
    }

    public function test_build_it_in_the_chat_of_a_development_ticket_builds(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->post("/tickets/{$this->ticket->id}/messages", ['text' => 'Looks right, build it'])->assertSessionHasNoErrors();
        $turn = AgentTurn::sole();
        $this->assertSame(['build', 'feature/SUP-1', 'master'], [$turn->kind, $turn->patch_meta['branch'], $turn->patch_meta['base']]);

        $this->assertFalse(\App\Http\Controllers\TicketController::asksForBuild('How would you build it?'));
    }

    /** Your own branch for the ticket, pushed here: ODOSSER-898 style, the key alone. */
    private function yourBranch(): string
    {
        $this->git(['checkout', '--quiet', '-b', 'SUP-1']);
        File::put($this->system->path().'/Export.php', "<?php\n\nreturn ['pdf', 'csv-started'];\n");
        $this->git(['-c', 'user.name=you', '-c', 'user.email=you@example.com', 'commit', '--quiet', '-am', 'SUP-1: start the CSV export']);
        $hash = trim($this->git(['rev-parse', '--short', 'HEAD']));
        $this->git(['checkout', '--quiet', 'master']);

        return $hash;
    }

    public function test_the_agent_gets_the_work_already_on_the_tickets_own_branch(): void
    {
        $hash = $this->yourBranch();

        app(\App\Agent\Workspace::class)->prepare($this->ticket);

        $root = \App\Agent\Workspace::path($this->ticket);
        $diff = file_get_contents($root.'/systems/billing.branch-sup-1.diff.txt');
        $this->assertStringContainsString($hash, $diff, 'The commit, so "what is in commit …" can be answered.');
        $this->assertStringContainsString("+return ['pdf', 'csv-started'];", $diff);
        $this->assertStringContainsString('systems/billing.branch-sup-1.diff.txt (billing, branch SUP-1)', file_get_contents($root.'/ticket.md'));
    }

    public function test_a_build_goes_on_the_branch_already_named_after_the_ticket(): void
    {
        $this->yourBranch();
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000b9', 'model' => 'claude-opus-5']);
        $this->agentWrites("<?php\n\nreturn ['pdf', 'csv'];\n");

        $turn = RunAgentTurn::queueBuild($session, $this->system);

        $this->assertSame('SUP-1', $turn->fresh()->patch_meta['branch'], 'Yours, not a new fix/ or feature/ beside it.');
        $this->assertStringContainsString('build on it, do not start over', $turn->fresh()->prompt);
        $this->assertSame(2, TicketBranch::sole()->commits, 'Your commit and the build\'s.');
    }

    public function test_a_go_ahead_to_fix_it_is_a_build_and_talk_about_a_fix_is_not(): void
    {
        $asks = fn (string $text) => \App\Http\Controllers\TicketController::asksForBuild($text);
        $this->assertTrue($asks("ok let's get it fixed now in the code of that branch ODOSSER-898"));
        $this->assertTrue($asks('please apply that change'));
        $this->assertTrue($asks('build it'));
        $this->assertFalse($asks('The fix is in commit 1f04597'));
        $this->assertFalse($asks('First what is in the fix? Especially commit 1f04597'));
        $this->assertFalse($asks('How would you fix it'));
    }

    public function test_a_branch_pushed_while_the_chat_goes_on_reaches_the_next_question(): void
    {
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000ba', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'Hi', 'state' => AgentTurn::DONE]);
        $this->yourBranch();
        Process::fake(['*claude*' => Process::result(json_encode(['type' => 'result', 'result' => 'Seen.'])."\n"), '*' => Process::result('')]);

        $turn = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'What is in the fix?']);
        (new RunAgentTurn($turn))->handle(app(\App\Agent\Workspace::class), app(\App\Agent\ClaudeRun::class));

        $this->assertFileExists(\App\Agent\Workspace::path($this->ticket).'/systems/billing.branch-sup-1.diff.txt');
    }
}
