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
