<?php

namespace Tests\Feature;

use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Agent\Workspace;
use App\Jobs\RunAgentTurn;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use App\Settings\SpaceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SpaceSettingsTest extends TestCase
{
    public function test_a_space_overrides_the_defaults_and_the_defaults_override_env(): void
    {
        config(['agent.claude.model' => 'claude-opus-5', 'agent.turn_budget_usd' => 3]);
        $plain = SpacesTest::space(['label' => 'Plain', 'project_key' => 'PLN']);
        $own = SpacesTest::space(['label' => 'Own', 'project_key' => 'OWN', 'settings' => ['model' => 'claude-sonnet-5', 'reply_rules' => '- Sign off as {first_name} from Finance.']]);

        $this->assertSame('claude-opus-5', SpaceSettings::for($plain)->get('model'));
        $this->put('/setup/settings', ['model' => 'claude-fable-5-1', 'turn_budget_usd' => 5])->assertSessionHasNoErrors();

        $this->assertSame('claude-fable-5-1', SpaceSettings::for($plain)->get('model'));
        $this->assertSame(5.0, SpaceSettings::for($plain)->get('turn_budget_usd'));
        $this->assertSame('claude-sonnet-5', SpaceSettings::for($own)->get('model'));
        $this->assertSame(5.0, SpaceSettings::for($own)->get('turn_budget_usd'), 'What a space does not set comes from the defaults.');
        $this->assertNull(SpaceSettings::for($plain)->get('monthly_budget_usd'), 'No budget unless someone sets one.');
        $this->assertStringContainsString('Sign off as Test from Finance.', Instructions::replyRules($own));
    }

    public function test_a_turn_runs_with_its_spaces_model_and_budget(): void
    {
        config(['agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude', 'agent.workspaces_path' => sys_get_temp_dir().'/tw-settings-'.uniqid()]);
        Http::fake();
        $space = SpacesTest::space(['state' => Space::ACTIVE, 'settings' => ['model' => 'claude-sonnet-5', 'turn_budget_usd' => 1.5, 'effort' => 'medium']]);
        $ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000013', 'model' => 'claude-sonnet-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => AgentTurn::DONE]);
        $turn = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'y']);
        Process::fake(['*claude*' => Process::result(json_encode(['type' => 'result', 'result' => 'ok'])."\n"), '*' => Process::result('')]);

        (new RunAgentTurn($turn))->handle(app(Workspace::class), app(ClaudeRun::class));

        Process::assertRan(fn ($process) => $process->command[array_search('--model', $process->command, true) + 1] === 'claude-sonnet-5'
            && $process->command[array_search('--effort', $process->command, true) + 1] === 'medium'
            && $process->command[array_search('--max-budget-usd', $process->command, true) + 1] === '1.5');
    }

    public function test_an_editor_sets_a_spaces_own_and_empty_means_default(): void
    {
        auth()->user()->update(['role' => 'editor']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);

        $this->put("/spaces/{$space->id}", [
            'label' => $space->label, 'colour' => $space->colour, 'type' => $space->type, 'done_rule' => $space->done_rule,
            'settings' => ['model' => 'claude-haiku-4-5', 'effort' => '', 'reply_rules' => null],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['model' => 'claude-haiku-4-5'], $space->fresh()->settings);
    }
}
