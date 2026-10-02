<?php

namespace Tests\Feature;

use App\Jobs\SyncSpace;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SpacesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jira.base' => 'https://example.atlassian.net',
            'services.jira.email' => 'me@example.com',
            'services.jira.token' => 'secret-token',
        ]);
    }

    public static function space(array $attributes = []): Space
    {
        return Space::create($attributes + [
            'project_key' => 'SUP',
            'project_id' => '10',
            'name' => 'Support',
            'label' => 'Support',
            'colour' => 'teal',
            'jira_type' => 'service_desk',
            'type' => Space::SERVICE,
            'done_rule' => Space::DEFAULT_DONE_RULE,
        ]);
    }

    public function test_adding_offers_only_the_spaces_not_set_up_yet(): void
    {
        self::space(['project_key' => 'SUP']);
        Http::fake(['*/rest/api/3/project/search*' => Http::response([
            'values' => [
                ['id' => 10, 'key' => 'SUP', 'name' => 'Support', 'projectTypeKey' => 'service_desk'],
                ['id' => 11, 'key' => 'DEV', 'name' => 'Development', 'projectTypeKey' => 'software'],
            ],
            'isLast' => true,
        ])]);

        $this->get('/spaces/create')->assertInertia(fn (Assert $page) => $page
            ->component('Spaces/Create', true)
            ->has('projects', 1)
            ->where('projects.0.key', 'DEV')
            ->where('projects.0.service', false));
    }

    public function test_a_new_space_is_a_draft_with_its_kind_from_jira(): void
    {
        Http::fake(['*/rest/api/3/project/SUP' => Http::response([
            'id' => 10, 'key' => 'SUP', 'name' => 'Support', 'projectTypeKey' => 'service_desk', 'issueTypes' => [],
        ])]);

        $this->post('/spaces', ['project_key' => 'SUP'])->assertRedirect();

        $space = Space::firstWhere('project_key', 'SUP');
        $this->assertSame(Space::DRAFT, $space->state);
        $this->assertSame(Space::SERVICE, $space->type);
        $this->assertSame('statusCategory = Done', $space->done_rule);
    }

    public function test_saving_keeps_the_rules_the_kind_and_the_systems(): void
    {
        $space = self::space();
        $system = System::create(['name' => 'billing', 'branch' => 'main', 'state' => System::READY]);

        $this->put("/spaces/{$space->id}", [
            'label' => 'Last line',
            'colour' => 'rose',
            'type' => Space::PLAIN,
            'rules' => 'labels = "last-line"',
            'done_rule' => 'statusCategory = Done',
            'system_ids' => [$system->id],
        ])->assertSessionHasNoErrors();

        $space->refresh();
        $this->assertSame(['Last line', 'rose', Space::PLAIN], [$space->label, $space->colour, $space->type]);
        $this->assertSame([$system->id], $space->systems()->pluck('systems.id')->all());
        $this->assertSame('project = "SUP" AND (labels = "last-line") AND NOT (statusCategory = Done) ORDER BY updated DESC', $space->jql());
    }

    public function test_saving_keeps_status_colours_and_which_statuses_sleep(): void
    {
        $space = self::space();

        $this->put("/spaces/{$space->id}", [
            'label' => 'Support', 'colour' => 'teal', 'type' => Space::SERVICE, 'done_rule' => 'statusCategory = Done',
            'status_colours' => ['On Hold' => 'teal'],
            'sleep_statuses' => ['Waiting for customer', 'On Hold'],
        ])->assertSessionHasNoErrors();

        $space->refresh();
        $this->assertSame('teal', $space->statusTone('On Hold', 'new'));
        $this->assertTrue($space->sleeps('On Hold'));

        $this->put("/spaces/{$space->id}", [
            'label' => 'Support', 'colour' => 'teal', 'type' => Space::SERVICE, 'done_rule' => 'statusCategory = Done',
            'status_colours' => ['On Hold' => 'fuchsia'],
        ])->assertSessionHasErrors('status_colours.On Hold');
    }

    public function test_rules_cannot_bring_their_own_order(): void
    {
        $space = self::space();

        $this->put("/spaces/{$space->id}", [
            'label' => 'Support', 'colour' => 'teal', 'type' => Space::SERVICE,
            'rules' => 'labels = x order by created', 'done_rule' => 'statusCategory = Done',
        ])->assertSessionHasErrors('rules');
    }

    public function test_the_preview_asks_jira_with_the_whole_query(): void
    {
        $space = self::space();
        Http::fake([
            '*/rest/api/3/search/jql' => Http::response([
                'issues' => [['id' => '1', 'key' => 'SUP-1', 'fields' => ['summary' => 'Invoices not sent', 'status' => ['name' => 'Open']]]],
                'isLast' => true,
            ]),
            '*/rest/api/3/search/approximate-count' => Http::response(['count' => 1]),
        ]);

        $this->getJson("/spaces/{$space->id}/preview?".http_build_query(['rules' => 'labels = "last-line"', 'done_rule' => 'statusCategory = Done']))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('tickets.0.key', 'SUP-1')
            ->assertJsonPath('jql', 'project = "SUP" AND (labels = "last-line") AND NOT (statusCategory = Done) ORDER BY updated DESC');

        // Counting needs no order, and Jira may refuse one there.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/approximate-count') && ! str_contains($request['jql'], 'ORDER BY'));
    }

    public function test_the_preview_passes_jiras_objection_on(): void
    {
        $space = self::space();
        Http::fake(['*/rest/api/3/search/jql' => Http::response(['errorMessages' => ["Field 'labelz' does not exist."]], 400)]);

        $response = $this->getJson("/spaces/{$space->id}/preview?rules=labelz%20%3D%20x")->assertOk();

        $this->assertStringContainsString("Field 'labelz' does not exist.", $response->json('error'));
    }

    public function test_the_setup_page_says_whether_the_account_is_an_agent_in_a_service_space(): void
    {
        $space = self::space();
        Http::fake([
            '*/rest/api/3/project/SUP' => Http::response(['id' => 10, 'key' => 'SUP', 'name' => 'Support', 'projectTypeKey' => 'service_desk', 'issueTypes' => [['name' => 'Incident']]]),
            '*/rest/api/3/project/SUP/statuses' => Http::response([['name' => 'Incident', 'statuses' => [
                ['name' => 'Waiting for support', 'statusCategory' => ['key' => 'indeterminate']],
                ['name' => 'Waiting for customer', 'statusCategory' => ['key' => 'undefined']],
            ]]]),
            '*/rest/api/3/project/SUP/components' => Http::response([['name' => 'Billing']]),
            '*/rest/api/3/mypermissions*' => Http::response(['permissions' => [
                'BROWSE_PROJECTS' => ['havePermission' => true],
                'ADD_COMMENTS' => ['havePermission' => true],
                'SERVICEDESK_AGENT' => ['havePermission' => false],
            ]]),
        ]);

        $this->get("/spaces/{$space->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->component('Spaces/Edit', true)
            ->where('permissions.data.SERVICEDESK_AGENT', false)
            ->where('vocabulary.data.statuses.0', [
                'name' => 'Waiting for customer', 'category' => 'undefined', 'default_tone' => 'amber', 'asleep_by_default' => true,
            ])
            ->where('vocabulary.data.statuses.1.default_tone', 'rose')
            ->where('vocabulary.data.statuses.1.asleep_by_default', false)
            ->where('vocabulary.data.components', ['Billing']));

        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'permissions=BROWSE_PROJECTS,ADD_COMMENTS,SERVICEDESK_AGENT'));
    }

    public function test_the_setup_page_still_opens_when_jira_does_not_answer(): void
    {
        $space = self::space();
        Http::fake(['*' => Http::response(['errorMessages' => ['Down for maintenance']], 400)]);

        $this->get("/spaces/{$space->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('vocabulary.data', null)
            ->where('permissions.error', fn (string $error) => str_contains($error, 'Down for maintenance')));
    }

    public function test_activating_starts_the_first_sync(): void
    {
        Queue::fake();
        $space = self::space();

        $this->post("/spaces/{$space->id}/activate")->assertRedirect();

        $this->assertSame(Space::ACTIVE, $space->fresh()->state);
        Queue::assertPushed(SyncSpace::class, fn (SyncSpace $job) => $job->space->is($space));
    }

    public function test_removing_a_space_removes_its_tickets_and_nothing_in_jira(): void
    {
        Http::fake();
        $space = self::space();
        Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->delete("/spaces/{$space->id}")->assertRedirect('/spaces');

        $this->assertSame(0, Ticket::count());
        Http::assertNothingSent();
    }
}
