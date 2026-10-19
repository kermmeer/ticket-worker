<?php

namespace Tests\Feature;

use App\Agent\ClaudeRun;
use App\Agent\Workspace;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The ticket chat: a question starts it, a quiet one starts afresh with what it found. */
class ChatTest extends TestCase
{
    private Ticket $ticket;

    private string $workspaces;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspaces = sys_get_temp_dir().'/tw-chat-'.uniqid();
        config([
            'services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't',
            'agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude', 'agent.workspaces_path' => $this->workspaces,
            'agent.claude.model' => 'claude-opus-5', 'agent.chat.model' => 'claude-sonnet-5', 'agent.chat.fresh_after_minutes' => 30,
        ]);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'Billed twice', 'description' => 'Twice.', 'status' => ['name' => 'To Do'], 'attachment' => []], 'comments' => [], 'total' => 0])]);
        Queue::fake();
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $this->ticket = Ticket::create([
            'space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Billed twice', 'first_seen_at' => now(), 'last_seen_at' => now(),
            'quick_scan_state' => 'done', 'quick_scan' => ['summary' => 'Invoice line doubled', 'system' => 'billing', 'likely_cause' => 'A second run adds to the line', 'next_step' => 'SELECT 1', 'confidence' => 'medium'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspaces);

        parent::tearDown();
    }

    public function test_the_page_takes_the_status_jira_has_now_also_on_a_refresh(): void
    {
        $this->ticket->update(['status' => 'In Progress', 'status_category' => 'indeterminate']);
        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page->where('ticket.left', false));

        // Resolved in Jira since: the sync no longer reads it, the page does.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'Billed twice', 'status' => ['name' => 'Resolved', 'statusCategory' => ['key' => 'done']], 'attachment' => []], 'comments' => [], 'total' => 0])]);

        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page
            ->where('ticket.status', 'Resolved')
            ->where('ticket.left', true));
        $this->assertSame(['Resolved', 'done'], [$this->ticket->fresh()->status, $this->ticket->fresh()->status_category]);
    }

    public function test_a_question_starts_a_chat_with_the_ticket_and_the_quick_scan(): void
    {
        $this->post("/tickets/{$this->ticket->id}/messages", ['text' => 'Is the data still wrong?', 'language' => 'auto'])->assertSessionHasNoErrors();

        $turn = AgentTurn::sole();
        $this->assertSame('message', $turn->kind);
        $this->assertSame('Is the data still wrong?', $turn->note, 'Your words, for the log.');
        $this->assertStringContainsString('chat about ticket SUP-1', $turn->prompt);
        $this->assertStringContainsString('A second run adds to the line', $turn->prompt, 'The quick scan goes along.');
        $this->assertStringEndsWith('Is the data still wrong?', $turn->prompt);
        Queue::assertPushed(RunAgentTurn::class);

        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page
            ->where('session.turns.0.prompt', 'Is the data still wrong?')
            ->where('chatModel', 'claude-sonnet-5'));
    }

    public function test_a_question_runs_on_the_chat_model_and_digging_deeper_on_the_full_one(): void
    {
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000c1', 'model' => 'claude-opus-5']);
        Process::fake(['*claude*' => Process::describe()->output(json_encode(['type' => 'result', 'result' => 'Yes.'])."\n")->runsFor(iterations: 1), '*' => Process::result('')]);

        foreach (['message' => 'claude-sonnet-5', 'analysis' => 'claude-opus-5'] as $kind => $model) {
            $turn = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => $kind, 'prompt' => 'Go.']);
            (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));
            $this->assertSame($model, $turn->fresh()->model);
            Process::assertRan(fn ($process) => in_array('claude', $process->command, true)
                && $process->command[array_search('--model', $process->command, true) + 1] === $model);
        }
    }

    public function test_a_chat_quiet_too_long_starts_afresh_with_what_it_found(): void
    {
        $old = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000c2', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $old->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => AgentTurn::DONE, 'finished_at' => now()->subHour(),
            'proposal' => ['headline' => 'The line is written twice', 'cause' => 'value += price', 'fix' => 'Lock the status update']]);
        AgentTurn::create(['agent_session_id' => $old->id, 'kind' => 'message', 'prompt' => 'Which rows?', 'note' => 'Which rows?', 'answer' => 'Rows 442070 and 443707.', 'state' => AgentTurn::DONE, 'finished_at' => now()->subMinutes(40)]);

        $this->post("/tickets/{$this->ticket->id}/messages", ['text' => 'Draft the reply.']);

        $this->assertSame('closed', $old->fresh()->state, 'Quiet for 40 minutes, past the 30.');
        $new = $this->ticket->openSession();
        $this->assertNotSame($old->id, $new->id);
        $prompt = $new->turns()->sole()->prompt;
        $this->assertStringContainsString('The line is written twice', $prompt);
        $this->assertStringContainsString('Rows 442070 and 443707.', $prompt);
        $this->assertStringEndsWith('Draft the reply.', $prompt);

        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page->where('earlier.id', $old->id)->has('earlier.turns', 2));
    }

    public function test_a_chat_still_going_carries_on(): void
    {
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000c3', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'Hi', 'state' => AgentTurn::DONE, 'finished_at' => now()->subMinutes(5)]);

        $this->post("/tickets/{$this->ticket->id}/messages", ['text' => 'And now?']);

        $this->assertSame($session->id, $this->ticket->openSession()->id);
        $this->assertSame('And now?', AgentTurn::query()->latest('id')->value('prompt'), 'A follow-up is only your words: the chat has the rest.');
    }
}
