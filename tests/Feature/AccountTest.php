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
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountTest extends TestCase
{
    public function test_without_an_account_you_are_sent_to_sign_in(): void
    {
        auth()->logout();

        $this->get('/')->assertRedirect('/sign-in');
        $this->get('/sign-in')->assertOk()->assertInertia(fn (Assert $page) => $page->component('SignIn', true)->where('firstAccount', false));
    }

    public function test_the_first_account_runs_the_place_and_keeps_earlier_work(): void
    {
        auth()->logout();
        User::query()->delete();
        $ticket = Ticket::create(['space_id' => SpacesTest::space()->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-00000000000f', 'model' => 'claude-opus-5']);

        $this->get('/sign-in')->assertInertia(fn (Assert $page) => $page->where('firstAccount', true));
        $this->post('/first-account', ['username' => 'Karl', 'name' => 'Karl Example', 'password' => 'long enough!', 'password_confirmation' => 'long enough!'])
            ->assertRedirect('/account');

        $karl = User::sole();
        $this->assertSame(['karl', 'admin'], [$karl->username, $karl->role]);
        $this->assertSame($karl->id, $session->fresh()->user_id);
        $this->assertAuthenticatedAs($karl);
        $this->post('/first-account', ['username' => 'b', 'name' => 'b', 'password' => 'long enough!', 'password_confirmation' => 'long enough!'])->assertStatus(409);
    }

    public function test_an_admin_invites_someone_who_sets_their_own_password(): void
    {
        auth()->user()->update(['role' => 'admin']);

        $this->post('/people', ['username' => 'stijn', 'name' => 'Stijn Example'])->assertSessionHas('invite');
        $stijn = User::firstWhere('username', 'stijn');
        $link = URL::temporarySignedRoute('account.welcome', now()->addWeek(), ['user' => $stijn->id]);

        auth()->logout();
        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome', true)->where('username', 'stijn'));
        $this->post($link, ['password' => 'stijns password', 'password_confirmation' => 'stijns password'])->assertRedirect('/account');
        $this->assertAuthenticatedAs($stijn);

        $this->get("/welcome/{$stijn->id}")->assertForbidden();
    }

    public function test_only_an_admin_adds_people(): void
    {
        auth()->user()->update(['role' => 'editor']);
        $this->post('/people', ['username' => 'stijn', 'name' => 'Stijn'])->assertForbidden();
    }

    public function test_your_own_claude_sign_in_runs_your_work_and_is_never_shown(): void
    {
        config(['agent.claude.api_key' => null, 'agent.claude.oauth_token' => 'sk-ant-oat-instance', 'agent.claude.bin' => 'claude',
            'agent.workspaces_path' => sys_get_temp_dir().'/tw-account-'.uniqid()]);
        $this->put('/account/claude', ['kind' => 'token', 'secret' => 'sk-ant-oat-mine'])->assertSessionHasNoErrors();
        $me = auth()->user()->fresh();
        $this->assertStringNotContainsString('sk-ant-oat-mine', DB::table('users')->where('id', $me->id)->value('claude_oauth_token'));
        $this->get('/account')->assertInertia(fn (Assert $page) => $page->where('account.claude', 'token'));
        $this->assertStringNotContainsString('sk-ant-oat-mine', $this->get('/account')->getContent());

        // A turn I asked for runs on mine; a turn nobody asked for, on the instance's.
        Http::fake();
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'user_id' => $me->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000010', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'x', 'state' => AgentTurn::DONE]);
        $mine = AgentTurn::create(['agent_session_id' => $session->id, 'user_id' => $me->id, 'kind' => 'message', 'prompt' => 'Mine?']);
        Process::fake(['*claude*' => Process::result(json_encode(['type' => 'result', 'result' => 'ok'])."\n"), '*' => Process::result('')]);
        auth()->logout();

        (new RunAgentTurn($mine))->handle(app(Workspace::class), app(ClaudeRun::class));

        Process::assertRan(fn ($process) => ($process->environment['CLAUDE_CODE_OAUTH_TOKEN'] ?? null) === 'sk-ant-oat-mine');
        $this->assertSame(['CLAUDE_CODE_OAUTH_TOKEN' => 'sk-ant-oat-instance'], Instructions::credentials());
    }

    public function test_a_trusted_header_names_the_person_and_makes_their_account(): void
    {
        auth()->logout();
        config(['auth.trusted_header' => 'X-authentik-username']);

        $this->get('/casebook', ['X-authentik-username' => 'stijn'])->assertOk();

        $this->assertAuthenticatedAs(User::firstWhere('username', 'stijn'));
        $this->assertFalse(User::firstWhere('username', 'stijn')->is_admin, 'Not the first account: the tester was.');
    }

    public function test_the_overview_says_who_is_on_a_ticket(): void
    {
        $other = User::factory()->create(['username' => 'stijn', 'name' => 'Stijn Example']);
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        AgentSession::create(['ticket_id' => $ticket->id, 'user_id' => $other->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000011', 'model' => 'claude-opus-5']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tickets.0.on_it', 'Stijn'));
    }
}
