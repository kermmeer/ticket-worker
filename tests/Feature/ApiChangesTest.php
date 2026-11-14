<?php

namespace Tests\Feature;

use App\Agent\ApiToolServer;
use App\Agent\Instructions;
use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\ApiCall;
use App\Models\ApiConnection;
use App\Models\AuditEvent;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** APIs with one sign-in for the system, and changes the agent may only propose. */
class ApiChangesTest extends TestCase
{
    private System $system;

    private Ticket $ticket;

    private AgentTurn $turn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.systems_path' => sys_get_temp_dir().'/tw-api-changes']);
        $this->system = System::create(['name' => 'boss', 'branch' => 'master', 'state' => System::READY, 'api_auth' => 'bearer', 'api_secret' => 'system-token-123']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $space->systems()->attach($this->system);
        $this->ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $this->ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000f1', 'model' => 'claude-opus-5']);
        $this->turn = AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'x']);
    }

    private function api(string $access, array $attributes = []): ApiConnection
    {
        return $this->system->apis()->create($attributes + ['name' => "orders-{$access}", 'base_url' => 'https://boss.example.com/api', 'auth' => 'system', 'access' => $access]);
    }

    private function callTool(array $arguments): array
    {
        $server = ApiToolServer::forTurn($this->turn->fresh(), collect([$this->system]));

        return $server->handle(['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'call_api', 'arguments' => $arguments]])['result'];
    }

    public function test_an_api_as_the_system_signs_in_with_its_token_and_never_shows_it(): void
    {
        Http::fake(['boss.example.com/*' => Http::response(['echo' => 'Bearer system-token-123'])]);
        $api = $this->api('read');

        $answer = $api->call('GET', '/orders/12');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer system-token-123'));
        $this->assertStringNotContainsString('system-token-123', $answer['body']);

        $own = $this->api('query', ['name' => 'other', 'auth' => 'bearer', 'secret' => 'own-token-456']);
        $own->call('GET', '/x');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer own-token-456'), 'Its own sign-in wins.');
    }

    public function test_what_each_access_level_lets_run_at_once(): void
    {
        Http::fake(['*' => Http::response([])]);
        $this->assertSame(['GET'], $this->api('read')->methods());
        $this->expectExceptionMessage('read-only');
        $this->api('read', ['name' => 'r2'])->call('POST', '/search');
    }

    public function test_a_change_on_an_api_that_allows_them_waits_instead_of_being_sent(): void
    {
        Http::fake();
        $api = $this->api('write');

        $answer = $this->callTool(['api' => 'boss/orders-write', 'method' => 'PUT', 'path' => '/orders/12', 'body' => ['status' => 'active'], 'reason' => 'The order is stuck in pending.']);

        Http::assertNothingSent();
        $call = ApiCall::sole();
        $this->assertSame(['waiting', 'PUT', '/orders/12', ['status' => 'active']], [$call->state, $call->method, $call->path, $call->body]);
        $this->assertStringContainsString('Not sent', $answer['content'][0]['text']);
        $this->assertTrue($api->needsApproval('DELETE'));
        $this->assertFalse($api->needsApproval('GET'));

        $this->get("/tickets/{$this->ticket->id}")->assertInertia(fn (Assert $page) => $page->where('ticket.changes.0.state', 'waiting')->where('ticket.changes.0.reason', 'The order is stuck in pending.'));
    }

    public function test_an_editor_runs_it_once_and_the_agent_hears_it_once(): void
    {
        Http::fake(['boss.example.com/*' => Http::response(['id' => 12, 'status' => 'active'])]);
        $api = $this->api('write');
        $call = ApiCall::create(['ticket_id' => $this->ticket->id, 'agent_turn_id' => $this->turn->id, 'api_connection_id' => $api->id, 'method' => 'PUT', 'path' => '/orders/12', 'body' => ['status' => 'active']]);

        $this->post("/tickets/{$this->ticket->id}/changes/{$call->id}/run")->assertSessionHas('success');
        $this->post("/tickets/{$this->ticket->id}/changes/{$call->id}/run")->assertSessionHas('error', 'Already decided.');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['status'] === 'active' && $request->hasHeader('Authorization', 'Bearer system-token-123'));
        $this->assertSame(['done', 200], [$call->fresh()->state, $call->fresh()->status]);
        $this->assertTrue(AuditEvent::where('action', 'api.change')->exists());

        $first = Instructions::decidedChanges($this->ticket);
        $this->assertStringContainsString('run by the engineer, HTTP 200', $first);
        $this->assertSame('', Instructions::decidedChanges($this->ticket), 'Told once.');
    }

    public function test_a_member_cannot_run_one_but_may_refuse_it(): void
    {
        Http::fake();
        $api = $this->api('write');
        $call = ApiCall::create(['ticket_id' => $this->ticket->id, 'api_connection_id' => $api->id, 'method' => 'DELETE', 'path' => '/orders/12']);
        auth()->user()->update(['role' => 'member']);

        $this->post("/tickets/{$this->ticket->id}/changes/{$call->id}/run")->assertForbidden();
        $this->post("/tickets/{$this->ticket->id}/changes/{$call->id}/refuse")->assertSessionHas('success');

        Http::assertNothingSent();
        $this->assertSame('refused', $call->fresh()->state);
        $this->assertStringContainsString('refused by the engineer', Instructions::decidedChanges($this->ticket));
    }

    public function test_the_systems_sign_in_is_set_once_and_its_secret_dropped_when_the_kind_changes(): void
    {
        $this->put("/systems/{$this->system->id}/api-sign-in", ['auth' => 'bearer', 'secret' => 'new-token'])->assertSessionHasNoErrors();
        $this->assertSame('new-token', $this->system->fresh()->api_secret);

        $this->put("/systems/{$this->system->id}/api-sign-in", ['auth' => 'header', 'field' => 'X-Api-Key'])->assertSessionHasNoErrors();
        $this->assertNull($this->system->fresh()->api_secret, 'Never carried to another kind of sign-in.');

        $this->get('/systems')->assertInertia(fn (Assert $page) => $page->where('systems.0.api_sign_in.auth', 'header')
            ->where('systems.0.api_sign_in.has_secret', false)->missing('systems.0.api_sign_in.secret'));
    }
}
