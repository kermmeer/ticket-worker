<?php

namespace Tests\Feature;

use App\Agent\Instructions;
use App\Agent\Workspace;
use App\Models\AgentSession;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Tests\TestCase;

class SpaceWithoutSystemsTest extends TestCase
{
    public function test_a_space_with_no_systems_ticked_gets_none_not_all(): void
    {
        $billing = System::create(['name' => 'billing', 'branch' => 'main', 'state' => System::READY]);
        $with = SpacesTest::space(['state' => Space::ACTIVE]);
        $with->systems()->attach($billing);
        $without = SpacesTest::space(['state' => Space::ACTIVE, 'project_key' => 'OPS', 'label' => 'OPS']);
        $ticket = fn (Space $space, string $key) => Ticket::create(['space_id' => $space->id, 'jira_id' => $key, 'key' => $key, 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->assertSame(['billing'], app(Workspace::class)->systems($ticket($with, 'SUP-1'))->pluck('name')->all());
        $none = app(Workspace::class)->systems($ticket($without, 'OPS-1'));
        $this->assertCount(0, $none, 'Ticked nothing: the agent sees no code.');

        $session = new AgentSession(['reply_language' => 'auto']);
        $this->assertStringContainsString('this space has no systems', Instructions::system($session, $none));
    }
}
