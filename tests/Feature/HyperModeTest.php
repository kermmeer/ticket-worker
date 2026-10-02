<?php

namespace Tests\Feature;

use App\Jobs\SyncSpace;
use App\Models\Setting;
use App\Models\Space;
use App\Sync\DispatchDueSyncs;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HyperModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['agent.sync_every_minutes' => 10]);
        Queue::fake();
    }

    private function spaceLastTried(?int $minutesAgo, string $key, string $state = Space::ACTIVE): Space
    {
        return SpacesTest::space([
            'project_key' => $key,
            'state' => $state,
            'sync_attempted_at' => $minutesAgo === null ? null : now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_a_space_is_due_every_ten_minutes(): void
    {
        $never = $this->spaceLastTried(null, 'NEW');
        $old = $this->spaceLastTried(11, 'OLD');
        $this->spaceLastTried(5, 'RECENT');
        $this->spaceLastTried(null, 'DRAFT', Space::DRAFT);

        (new DispatchDueSyncs)();

        Queue::assertPushed(SyncSpace::class, 2);
        Queue::assertPushed(SyncSpace::class, fn (SyncSpace $job) => $job->space->is($never));
        Queue::assertPushed(SyncSpace::class, fn (SyncSpace $job) => $job->space->is($old));
    }

    public function test_hyper_mode_makes_it_every_minute(): void
    {
        Setting::put(Setting::HYPER, '1');
        $this->spaceLastTried(2, 'SUP');

        (new DispatchDueSyncs)();

        Queue::assertPushed(SyncSpace::class, 1);
    }

    public function test_the_switch_turns_it_on_and_off_and_shows_on_every_page(): void
    {
        $this->post('/hyper')->assertRedirect();
        $this->assertTrue(Setting::hyper());
        $this->get('/design')->assertInertia(fn ($page) => $page->where('hyper', true));

        $this->post('/hyper');
        $this->assertFalse(Setting::hyper());
    }
}
