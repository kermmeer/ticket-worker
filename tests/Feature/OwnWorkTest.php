<?php

namespace Tests\Feature;

use App\Agent\Workspace;
use App\Jobs\SyncSpace;
use App\Jira\JiraClient;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use App\Models\TicketBranch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process as Git;
use Tests\TestCase;

class OwnWorkTest extends TestCase
{
    private string $root;

    private Space $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/tw-own-'.uniqid();
        config([
            'services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't',
            'agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude', 'agent.chat.model' => 'claude-sonnet-5',
            'agent.workspaces_path' => $this->root.'/agent/tickets', 'agent.systems_path' => $this->root.'/systems',
        ]);
        $this->space = SpacesTest::space(['state' => Space::ACTIVE, 'type' => Space::PLAIN, 'project_key' => 'DEVBESUP']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory((string) config('agent.work_files_path'));
        parent::tearDown();
    }

    private function start(): Ticket
    {
        $this->post('/work', ['space_id' => $this->space->id, 'title' => 'Export misses CSV', 'description' => 'Users ask for CSV.', 'type' => 'Improvement'])->assertSessionHasNoErrors();

        return Ticket::sole();
    }

    private static function issue(string $id, string $key): array
    {
        return ['id' => $id, 'key' => $key, 'fields' => ['summary' => 'Export as CSV', 'status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']], 'issuetype' => ['name' => 'Improvement']]];
    }

    public function test_own_work_is_a_ticket_here_that_jira_knows_nothing_of(): void
    {
        Http::fake(['*/rest/api/3/field' => Http::response([]), '*/rest/api/3/search/jql' => Http::response(['issues' => [], 'isLast' => true])]);
        $ticket = $this->start();
        $this->assertSame(["WIP-{$ticket->id}", true, 'development', "feature/WIP-{$ticket->id}"], [$ticket->key, $ticket->local, $ticket->kind(), $ticket->branchName()]);

        $this->get("/tickets/{$ticket->id}")->assertInertia(fn (Assert $page) => $page
            ->where('ticket.local', true)->where('ticket.url', null)->where('issue.description', 'Users ask for CSV.'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tickets.0.group', 'own-work')->where('tickets.0.url', null));

        // A sync of the space does not see it in Jira, and must not make it leave.
        (new SyncSpace($this->space->fresh()))->handle(app(JiraClient::class));
        $this->assertNull($ticket->fresh()->left_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/issue/WIP-'));
    }

    public function test_screenshots_go_to_the_agent_and_can_be_removed(): void
    {
        Http::fake();
        $ticket = $this->start();
        $this->post("/work/{$ticket->id}/files", ['files' => [UploadedFile::fake()->image('Export screen.png'), UploadedFile::fake()->image('Export screen.png')]])->assertSessionHasNoErrors();
        $this->assertSame(['export-screen-2.png', 'export-screen.png'], array_column($ticket->localFiles(), 'name'));
        $this->get("/work/{$ticket->id}/files/export-screen.png")->assertOk();
        $this->get("/work/{$ticket->id}/files/..%2F..%2F.env")->assertNotFound();

        app(Workspace::class)->prepare($ticket->fresh());
        $root = Workspace::path($ticket->fresh());
        $this->assertFileExists($root.'/attachments/export-screen.png');
        $this->assertStringContainsString('Users ask for CSV.', file_get_contents($root.'/ticket.md'));
        $this->assertStringContainsString("own work: no Jira ticket yet", file_get_contents($root.'/ticket.md'));

        $this->delete("/work/{$ticket->id}/files/export-screen-2.png");
        $this->assertSame(['export-screen.png'], array_column($ticket->localFiles(), 'name'));
    }

    public function test_the_agent_drafts_its_jira_ticket_with_the_screenshots_in_it(): void
    {
        Http::fake();
        $ticket = $this->start();
        $this->post("/work/{$ticket->id}/files", ['files' => [UploadedFile::fake()->image('export.png')]]);
        $draft = ['title' => 'Offer CSV in the export menu', 'type' => 'Improvement', 'description' => "h3. Need\nCSV.\n!export.png|thumbnail!"];
        Process::fake(['*claude*' => Process::describe()->output(json_encode(['type' => 'result', 'result' => '', 'structured_output' => $draft])."\n")->runsFor(iterations: 1), '*' => Process::result('')]);

        $this->post("/tickets/{$ticket->id}/draft-ticket")->assertSessionHasNoErrors();

        $turn = AgentTurn::sole();
        $this->assertSame(['ticket', 'claude-sonnet-5'], [$turn->kind, $turn->model]);
        $this->assertStringContainsString('export.png', $turn->prompt);
        $this->get("/tickets/{$ticket->id}")->assertInertia(fn (Assert $page) => $page->where('ticket.ticket_draft.title', 'Offer CSV in the export menu'));
    }

    public function test_linking_moves_the_work_onto_the_jira_ticket_and_its_branch_takes_the_key(): void
    {
        Http::fake(['*/rest/api/3/search/jql' => Http::response(['issues' => [self::issue('9001', 'DEVBESUP-3300')], 'isLast' => true])]);
        $ticket = $this->start();
        $this->post("/work/{$ticket->id}/files", ['files' => [UploadedFile::fake()->image('export.png')]]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000e1', 'model' => 'claude-opus-5']);

        // Its branch, built before there was a ticket.
        $system = System::create(['name' => 'billing', 'branch' => 'master', 'state' => System::READY]);
        File::ensureDirectoryExists($system->path());
        File::put($system->path().'/a.txt', "a\n");
        foreach ([['init', '--quiet', '--initial-branch=master'], ['add', '.'], ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '-m', 'start'], ['branch', "feature/WIP-{$ticket->id}"]] as $args) {
            (new Git(['git', ...$args], $system->path()))->mustRun();
        }
        TicketBranch::create(['ticket_id' => $ticket->id, 'system_id' => $system->id, 'name' => "feature/WIP-{$ticket->id}", 'base' => 'master', 'commits' => 1]);

        $this->post("/tickets/{$ticket->id}/link", ['key' => 'devbesup-3300'])->assertSessionHasNoErrors();

        $real = Ticket::firstWhere('key', 'DEVBESUP-3300');
        $this->assertFalse($real->local);
        $this->assertNull(Ticket::find($ticket->id), 'The own-work item is gone: it is the ticket now.');
        $this->assertSame($real->id, $session->fresh()->ticket_id);
        $this->assertSame('closed', $session->fresh()->state, 'Its chat cannot follow to the new folder: the next one starts from its summary.');
        $this->assertSame('feature/DEVBESUP-3300', TicketBranch::sole()->name);
        $this->assertSame(0, (new Git(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/feature/DEVBESUP-3300'], $system->path()))->run());
        $this->assertSame(['export.png'], array_column($real->localFiles(), 'name'));
    }

    public function test_creating_it_in_jira_needs_writing_on_and_sends_the_screenshots_along(): void
    {
        Http::fake([
            '*/rest/api/2/issue/DEVBESUP-3301/attachments' => Http::response([['id' => '1']]),
            '*/rest/api/2/issue' => Http::response(['key' => 'DEVBESUP-3301']),
            '*/rest/api/3/search/jql' => Http::response(['issues' => [self::issue('9002', 'DEVBESUP-3301')], 'isLast' => true]),
        ]);
        $ticket = $this->start();
        $this->post("/work/{$ticket->id}/files", ['files' => [UploadedFile::fake()->image('export.png')]]);
        $form = ['create' => true, 'title' => 'Offer CSV', 'type' => 'Improvement', 'description' => '!export.png|thumbnail!'];

        config(['services.jira.write' => 'dummy']);
        $this->post("/tickets/{$ticket->id}/link", $form)->assertSessionHas('error');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/rest/api/2/issue'));

        config(['services.jira.write' => 'real']);
        $this->post("/tickets/{$ticket->id}/link", $form)->assertRedirect();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/rest/api/2/issue') && $request['fields']['project']['key'] === 'DEVBESUP' && $request['fields']['summary'] === 'Offer CSV');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/DEVBESUP-3301/attachments'));
        $this->assertNotNull(Ticket::firstWhere('key', 'DEVBESUP-3301'));
    }
}
