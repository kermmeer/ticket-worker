<?php

namespace Tests\Feature;

use App\Agent\Workspace;
use App\Jira\JiraClient;
use App\Jobs\SyncSpace;
use App\Models\JiraSite;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraSitesTest extends TestCase
{
    private function issue(string $id, string $key): array
    {
        return ['id' => $id, 'key' => $key, 'fields' => ['summary' => "{$key} on its site", 'status' => ['name' => 'Open', 'statusCategory' => ['key' => 'new']]]];
    }

    public function test_each_space_reads_its_own_site_and_tickets_stay_apart(): void
    {
        config(['services.jira.base' => 'https://default.atlassian.net', 'services.jira.email' => 'a@b.c', 'services.jira.token' => 't']);
        $this->app->forgetInstance(JiraClient::class);
        $other = JiraSite::create(['name' => 'Customer X', 'base_url' => 'https://x.atlassian.net', 'email' => 'me@x.com', 'token' => 'x-s3cret']);
        $this->assertStringNotContainsString('x-s3cret', (string) DB::table('jira_sites')->value('token'));

        $home = SpacesTest::space(['state' => Space::ACTIVE, 'project_key' => 'SUP']);
        $away = SpacesTest::space(['state' => Space::ACTIVE, 'project_key' => 'SUP', 'jira_site_id' => $other->id]);

        // Both sites have a SUP-1 with issue id 1.
        Http::fake([
            'default.atlassian.net/rest/api/3/field' => Http::response([]),
            'x.atlassian.net/rest/api/3/field' => Http::response([]),
            'default.atlassian.net/rest/api/3/search/jql' => Http::response(['issues' => [$this->issue('1', 'SUP-1')], 'isLast' => true]),
            'x.atlassian.net/rest/api/3/search/jql' => Http::response(['issues' => [$this->issue('1', 'SUP-1')], 'isLast' => true]),
        ]);
        (new SyncSpace($home))->handle(app(JiraClient::class));
        (new SyncSpace($away))->handle(app(JiraClient::class));

        $this->assertSame(2, Ticket::count());
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://x.atlassian.net/') && $request->hasHeader('Authorization', 'Basic '.base64_encode('me@x.com:x-s3cret')));
        $tickets = Ticket::orderBy('site')->get();
        $this->assertNotSame(Workspace::path($tickets[0]), Workspace::path($tickets[1]), 'Two SUP-1s, two workspaces.');
        $this->assertSame('https://x.atlassian.net/browse/SUP-1', $away->fresh()->jira()->browseUrl('SUP-1'));
    }

    public function test_an_admin_adds_a_site_and_cannot_remove_one_in_use(): void
    {
        Http::fake(['x.atlassian.net/rest/api/3/myself' => Http::response(['displayName' => 'Me at X'])]);

        $this->post('/setup/jira-sites', ['name' => 'Customer X', 'base_url' => 'https://x.atlassian.net', 'email' => 'me@x.com', 'token' => 'x-s3cret'])
            ->assertSessionHas('success', 'Customer X: Connected as Me at X.');
        $site = JiraSite::sole();
        SpacesTest::space(['jira_site_id' => $site->id]);

        $this->delete("/setup/jira-sites/{$site->id}")->assertStatus(409);
        $this->assertFalse(str_contains($this->get('/setup')->getContent(), 'x-s3cret'));
    }
}
