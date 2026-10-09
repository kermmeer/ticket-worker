<?php

namespace Tests\Feature;

use App\Jira\JiraClient;
use App\Jobs\SyncSpace;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'a@b.c', 'services.jira.token' => 't']);
        $this->app->forgetInstance(JiraClient::class);
    }

    public function test_a_sync_keeps_the_assignees_jira_account_and_an_account_links_by_its_name(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        Http::fake([
            '*/rest/api/3/field' => Http::response([]),
            '*/rest/api/3/search/jql' => Http::response(['issues' => [['id' => '1', 'key' => 'SUP-1', 'fields' => [
                'summary' => 'x', 'status' => ['name' => 'Open', 'statusCategory' => ['key' => 'new']],
                'assignee' => ['accountId' => '5f-test', 'displayName' => 'Test Person'],
            ]]], 'isLast' => true]),
        ]);
        (new SyncSpace($space))->handle(app(JiraClient::class));
        $this->assertSame('5f-test', Ticket::sole()->assignee_account_id);

        // The account's name is the assignee's: linked on sight.
        $this->get('/account')->assertInertia(fn (Assert $page) => $page->where('account.jira.account_id', '5f-test'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tickets.0.assignee_account_id', '5f-test')->where('me.jira_account_id', '5f-test'));
    }

    public function test_assign_to_me_sends_nothing_in_dummy_mode_and_assigns_in_real(): void
    {
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        auth()->user()->update(['jira_account_id' => '5f-me', 'jira_name' => 'Test Person']);
        Http::fake(['*/rest/api/3/issue/SUP-1/assignee' => Http::response(null, 204)]);

        config(['services.jira.write' => 'dummy']);
        $this->post("/tickets/{$ticket->id}/assign-to-me")->assertSessionHas('error');
        Http::assertNothingSent();

        config(['services.jira.write' => 'real']);
        $this->post("/tickets/{$ticket->id}/assign-to-me")->assertSessionHas('success');
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['accountId'] === '5f-me');
        $this->assertSame('5f-me', $ticket->fresh()->assignee_account_id);
    }
}
