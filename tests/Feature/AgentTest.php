<?php

namespace Tests\Feature;

use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Agent\StreamReader;
use App\Agent\Workspace;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\CasebookEntry;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentTest extends TestCase
{
    private string $workspaces;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspaces = sys_get_temp_dir().'/tw-agent-'.uniqid();
        config([
            'services.jira.base' => 'https://example.atlassian.net',
            'services.jira.email' => 'me@example.com',
            'services.jira.token' => 'secret-token',
            'services.jira.open_url' => null,
            'agent.claude.api_key' => 'sk-ant-test',
            'agent.claude.bin' => 'claude',
            'agent.workspaces_path' => $this->workspaces,
        ]);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $this->ticket = Ticket::create([
            'space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Invoices not sent after a credit note',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        Http::fake([
            '*/rest/api/2/issue/SUP-1/comment*' => Http::response(['comments' => [
                ['author' => ['displayName' => 'Finance team'], 'created' => '2026-10-01T10:00:00.000+0200', 'body' => 'Still no invoice.', 'properties' => []],
                ['author' => ['displayName' => 'Second line'], 'created' => '2026-10-01T11:00:00.000+0200', 'body' => 'Reproduced.', 'properties' => [['key' => 'sd.public.comment', 'value' => ['internal' => true]]]],
            ], 'total' => 2]),
            '*/rest/api/2/issue/SUP-1*' => Http::response(['fields' => [
                'summary' => 'Invoices not sent after a credit note', 'description' => 'Since Monday no invoice mail.',
                'status' => ['name' => 'To Do'], 'reporter' => ['displayName' => 'Finance team'], 'attachment' => [],
            ]]),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspaces);

        parent::tearDown();
    }

    private static function line(array $message): string
    {
        return json_encode($message)."\n";
    }

    public function test_the_stream_reads_as_steps_and_a_result(): void
    {
        $reader = new StreamReader(['/app/shared/systems/boss' => 'boss/', '/ws/' => '']);

        $events = $reader->feed(
            self::line(['type' => 'system', 'subtype' => 'init'])
            .self::line(['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'text', 'text' => 'Looking at the credit note path.'],
                ['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => '/app/shared/systems/boss/app/Credit.php']],
                ['type' => 'tool_use', 'name' => 'Grep', 'input' => ['pattern' => 'sendInvoice', 'path' => '/ws/attachments']],
            ]]])
            .'{"type":"result","total_cost_'
        );
        $rest = $reader->feed('usd":0.42,"result":"done"}'."\n");

        $this->assertSame(['text', 'tool', 'tool'], array_column($events, 'type'));
        $this->assertSame('reading boss/app/Credit.php', $events[1]['summary']);
        $this->assertSame('searching "sendInvoice" in attachments', $events[2]['summary']);
        $this->assertSame(0.42, $rest[0]['result']['total_cost_usd'], 'A line split across chunks is read whole.');
    }

    public function test_analyse_starts_a_conversation_and_queues_the_turn_for_the_agent_container(): void
    {
        Queue::fake();

        $this->post("/tickets/{$this->ticket->id}/analyse", ['language' => 'Nederlands'])->assertRedirect();

        $session = AgentSession::sole();
        $this->assertSame('Nederlands', $session->reply_language);
        Queue::assertPushedOn('agents', RunAgentTurn::class);

        // One turn at a time.
        $this->post("/tickets/{$this->ticket->id}/messages", ['text' => 'And the batch job?'])->assertSessionHas('error');
        $this->assertSame(1, AgentTurn::count());
    }

    public function test_without_an_api_key_nothing_starts(): void
    {
        config(['agent.claude.api_key' => null]);

        $this->post("/tickets/{$this->ticket->id}/analyse", ['language' => 'auto'])->assertStatus(409);
        $this->assertSame(0, AgentSession::count());
    }

    public function test_a_turn_prepares_the_workspace_runs_read_only_and_keeps_the_proposal(): void
    {
        CasebookEntry::create(['title' => 'Invoice mail missing after a credit note', 'symptoms' => 'No invoice mail after a credit note.', 'solution' => 'Resend.', 'state' => CasebookEntry::APPROVED]);
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000001', 'model' => 'claude-opus-5']);
        $turn = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'Analyse.']);
        $proposal = ['problem' => 'No invoice mail', 'system' => 'billing', 'cause' => 'Early return', 'evidence' => [], 'fix' => 'Queue first', 'reply_draft' => 'Beste…', 'confidence' => 'high', 'confidence_reason' => 'Seen in code'];

        Process::fake([
            '*claude*' => Process::describe()
                ->output(self::line(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Checking the casebook first.']]]]))
                ->output(self::line(['type' => 'result', 'total_cost_usd' => 0.81, 'duration_ms' => 42000, 'result' => '', 'structured_output' => $proposal]))
                ->runsFor(iterations: 2),
            '*' => Process::result(''),
        ]);

        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));

        $turn->refresh();
        $this->assertSame(AgentTurn::DONE, $turn->state, (string) $turn->error);
        $this->assertSame('Early return', $turn->proposal['cause']);
        $this->assertSame(0.81, $session->fresh()->cost_usd);
        $this->assertContains('Checking the casebook first.', $turn->events()->pluck('summary')->all());

        $root = Workspace::path($this->ticket);
        $this->assertStringContainsString('Reproduced.', file_get_contents("{$root}/ticket.md"));
        $this->assertStringContainsString('(internal note)', file_get_contents("{$root}/ticket.md"));
        $this->assertStringContainsString('Invoice mail missing', file_get_contents("{$root}/casebook/INDEX.md"));

        Process::assertRan(function ($process) use ($session) {
            $command = $process->command;
            if (! in_array('claude', $command, true)) {
                return false;
            }
            $tools = $command[array_search('--tools', $command, true) + 1];

            return $tools === 'Read,Grep,Glob'
                && in_array('--restricted', $command, true)
                && in_array('--json-schema', $command, true)
                && $command[array_search('--session-id', $command, true) + 1] === $session->claude_session_id
                && $process->environment['ANTHROPIC_API_KEY'] === 'sk-ant-test';
        });
    }

    public function test_a_follow_up_resumes_the_conversation(): void
    {
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000002', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'Analyse.', 'state' => AgentTurn::DONE]);
        $turn = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'And the batch job?']);
        Process::fake(['*claude*' => Process::describe()->output(self::line(['type' => 'result', 'result' => 'The batch job is fine.']))->runsFor(iterations: 1), '*' => Process::result('')]);

        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));

        $this->assertSame('The batch job is fine.', $turn->fresh()->answer);
        Process::assertRan(fn ($process) => in_array('--resume', $process->command, true) && ! in_array('--json-schema', $process->command, true));
    }

    public function test_the_page_shows_the_ticket_and_hands_the_reply_to_the_outbox(): void
    {
        config(['services.outbox.url' => 'http://jira-outbox:5173', 'services.outbox.token' => 'outbox-token']);
        Http::fake(['jira-outbox:5173/api/v1/drafts' => Http::response(['id' => 'd1', 'state' => 'draft', 'url' => 'https://jira.techfactory.dev/SUP-1?draft=d1'], 201)]);

        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Tickets/Show', true)
            ->where('issue.description', 'Since Monday no invoice mail.')
            ->where('issue.comments.1.public', false)
            ->where('session', null));

        $this->post("/tickets/{$this->ticket->id}/draft", ['body' => 'Beste, we hebben het gevonden.', 'visibility' => 'internal'])->assertSessionHas('success');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/drafts') && $request['visibility'] === 'internal' && $request['issueKey'] === 'SUP-1');
    }

    public function test_a_subscription_token_signs_in_when_there_is_no_key(): void
    {
        config(['agent.claude.api_key' => null, 'agent.claude.oauth_token' => 'sk-ant-oat-test']);
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000003', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'Analyse.', 'state' => AgentTurn::DONE]);
        $turn = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'Hi.']);
        Process::fake(['*claude*' => Process::describe()->output(self::line(['type' => 'result', 'result' => 'Hello.']))->runsFor(iterations: 1), '*' => Process::result('')]);

        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));

        Process::assertRan(fn ($process) => ($process->environment['CLAUDE_CODE_OAUTH_TOKEN'] ?? null) === 'sk-ant-oat-test'
            && ! isset($process->environment['ANTHROPIC_API_KEY']));
    }

    public function test_a_turn_the_queue_gave_up_on_does_not_keep_the_page_waiting(): void
    {
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000004', 'model' => 'claude-opus-5']);
        $running = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'Analyse.', 'state' => AgentTurn::RUNNING]);

        (new RunAgentTurn($running))->failed(null);

        $this->assertSame(AgentTurn::FAILED, $running->fresh()->state);
        $this->assertFalse($session->busy());
        $this->assertSame(2700, config('queue.connections.database.retry_after'), 'Longer than any agent job runs.');
    }

    public function test_reply_rules_go_with_every_draft_and_can_be_changed(): void
    {
        $this->assertStringContainsString('friendly line', Instructions::analysis($this->ticket));

        $this->put('/setup/reply-rules', ['rules' => '- Two sentences at most.'])->assertSessionHas('success');

        $this->assertStringContainsString('Two sentences at most.', Instructions::analysis($this->ticket));
        $this->assertStringContainsString('Two sentences at most.', Instructions::restate());

        $this->put('/setup/reply-rules', ['rules' => '']);
        $this->assertStringContainsString('friendly line', Instructions::restate(), 'Empty means the default.');
    }

    public function test_the_agent_drafts_a_case_that_only_exists_once_you_submit_it(): void
    {
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000005', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'Analyse.', 'state' => AgentTurn::DONE]);
        $system = System::create(['name' => 'billing', 'branch' => 'main', 'state' => System::READY]);
        Queue::fake();

        $this->post("/tickets/{$this->ticket->id}/case")->assertRedirect();
        $turn = AgentTurn::where('kind', 'case')->sole();
        Queue::assertPushed(RunAgentTurn::class);

        $draft = ['title' => 'Invoice mail missing after a credit note', 'symptoms' => 'No invoice mail.', 'cause' => 'apply() returns early.', 'solution' => 'Resend; fix the return.', 'keywords' => 'factuur, facture', 'system' => 'billing'];
        Process::fake(['*claude*' => Process::describe()->output(self::line(['type' => 'result', 'result' => '', 'structured_output' => $draft]))->runsFor(iterations: 1), '*' => Process::result('')]);
        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));

        Process::assertRan(fn ($process) => in_array('--json-schema', $process->command, true) && str_contains($process->command[array_search('--json-schema', $process->command, true) + 1], '"symptoms"'));
        $this->assertSame(0, CasebookEntry::count(), 'Nothing is in the casebook yet.');

        $this->get("/casebook/create?turn={$turn->id}")->assertInertia(fn (Assert $page) => $page
            ->component('Casebook/Form', true)
            ->where('fromAgent', true)
            ->where('entry.title', 'Invoice mail missing after a credit note')
            ->where('entry.system_id', $system->id)
            ->where('entry.source_tickets', 'SUP-1'));
    }
}
