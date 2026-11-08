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

    public function test_build_it_in_the_chat_of_a_development_ticket_builds(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->post("/tickets/{$this->ticket->id}/messages", ['text' => 'Looks right, build it'])->assertSessionHasNoErrors();
        $turn = AgentTurn::sole();
        $this->assertSame(['build', 'feature/SUP-1', 'master'], [$turn->kind, $turn->patch_meta['branch'], $turn->patch_meta['base']]);

        $this->assertFalse(\App\Http\Controllers\TicketController::asksForBuild('How would you build it?'));
    }
}
