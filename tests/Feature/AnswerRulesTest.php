<?php

namespace Tests\Feature;

use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Agent\Workspace;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Setting;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AnswerRulesTest extends TestCase
{
    public function test_every_question_goes_with_the_answer_rules_and_the_log_shows_only_yours(): void
    {
        config(['agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude', 'agent.workspaces_path' => sys_get_temp_dir().'/tw-answers-'.uniqid()]);
        Http::fake();
        Setting::put(Setting::ANSWER_RULES, '- Give the exact SQL.');
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-00000000000c', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => AgentTurn::DONE]);
        $turn = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'Why 600?']);
        Process::fake(['*claude*' => Process::result(json_encode(['type' => 'result', 'result' => 'ok'])."\n"), '*' => Process::result('')]);

        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));

        Process::assertRan(fn ($process) => str_starts_with((string) $process->input, 'Why 600?') && str_contains((string) $process->input, '- Give the exact SQL.'));
        $this->assertSame('Why 600?', $turn->fresh()->prompt);
    }

    public function test_proposals_ask_for_commands_ready_to_run(): void
    {
        $commands = Instructions::proposalSchema()['properties']['commands'];

        $this->assertSame(['purpose', 'where', 'kind', 'command'], $commands['items']['required']);
        $this->assertStringContainsString('exact command', Setting::DEFAULT_ANSWER_RULES);
    }
}
