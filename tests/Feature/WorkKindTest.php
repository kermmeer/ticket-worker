<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Space;
use App\Models\Ticket;
use App\Settings\SpaceSettings;
use Tests\TestCase;

class WorkKindTest extends TestCase
{
    private function ticket(string $type, array $settings = []): Ticket
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE, 'settings' => $settings, 'project_key' => 'DEV'.uniqid()]);

        return Ticket::create(['space_id' => $space->id, 'jira_id' => uniqid(), 'key' => 'DEVBESUP-3211', 'summary' => 'x', 'issue_type' => $type, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    public function test_a_story_is_development_and_an_incident_support(): void
    {
        $this->assertSame('development', $this->ticket('Story')->kind());
        $this->assertSame('development', $this->ticket('bug')->kind(), 'Whatever the case of the name.');
        $this->assertSame('support', $this->ticket('Incident')->kind());
        $this->assertSame('development', $this->ticket('Incident', ['development_types' => 'Incident, Story'])->kind(), 'A space moves a type.');
    }

    public function test_the_overview_groups_development_by_its_jira_status_and_shows_its_code(): void
    {
        $story = $this->ticket('Story');
        $story->update(['status_category' => 'indeterminate']);
        $incident = Ticket::create(['space_id' => $story->space_id, 'jira_id' => 'i1', 'key' => 'DEVBESUP-1', 'summary' => 'x', 'issue_type' => 'Incident', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $system = \App\Models\System::create(['name' => 'boss', 'branch' => 'master', 'state' => \App\Models\System::READY]);
        \App\Models\TicketBranch::create(['ticket_id' => $story->id, 'system_id' => $system->id, 'name' => 'feature/DEVBESUP-3211', 'base' => 'master', 'commits' => 3, 'added' => 120, 'removed' => 14, 'files' => 4]);

        $this->get('/')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('tickets', fn ($tickets) => collect($tickets)->firstWhere('id', $story->id)['group'] === 'dev-in-progress'
                && collect($tickets)->firstWhere('id', $story->id)['branches'][0]['commits'] === 3
                && collect($tickets)->firstWhere('id', $incident->id)['kind'] === 'support'
                && collect($tickets)->firstWhere('id', $incident->id)['group'] === 'not-analysed'));
    }

    public function test_branches_are_named_feature_or_fix_and_prefixes_can_go(): void
    {
        $this->assertSame('feature/DEVBESUP-3211', $this->ticket('Story')->branchName());
        $this->assertSame('fix/DEVBESUP-3211', $this->ticket('Bug')->branchName());
        $this->assertSame('bugfix/DEVBESUP-3211', $this->ticket('Bug', ['fix_prefix' => 'bugfix/'])->branchName());
        $this->assertSame('DEVBESUP-3211', $this->ticket('Story', ['branch_prefixes' => false])->branchName());

        // The default for every space, on Setup.
        Setting::put(SpaceSettings::DEFAULTS, json_encode(['feature_prefix' => 'feat/']));
        $this->assertSame('feat/DEVBESUP-3211', $this->ticket('Story')->branchName());
    }
}
