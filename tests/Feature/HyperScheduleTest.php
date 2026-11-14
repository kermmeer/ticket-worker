<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Sync\HyperSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HyperScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        HyperSchedule::save(['on' => true, 'days' => [1, 2, 3, 4, 5], 'from' => '08:00', 'to' => '17:00', 'timezone' => 'Europe/Brussels']);
    }

    private function at(string $brussels): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($brussels, 'Europe/Brussels'));
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse($brussels, 'Europe/Brussels'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_switches_at_the_start_and_end_of_the_day_and_a_switch_by_hand_holds_in_between(): void
    {
        $this->at('2026-11-16 07:59'); // a Monday
        (new HyperSchedule)();
        $this->assertFalse(Setting::hyper());
        $this->assertSame('on at 08:00', HyperSchedule::next());

        $this->at('2026-11-16 08:00');
        (new HyperSchedule)();
        $this->assertTrue(Setting::hyper(), 'On at the start.');
        $this->assertSame('until 17:00', HyperSchedule::next());

        // Off by hand at noon: it stays off until the next boundary.
        Setting::put(Setting::HYPER, '0');
        $this->at('2026-11-16 12:00');
        (new HyperSchedule)();
        $this->assertFalse(Setting::hyper());

        // On by hand in the evening: off again only at the next end, the day after.
        $this->at('2026-11-16 17:00');
        (new HyperSchedule)();
        Setting::put(Setting::HYPER, '1');
        $this->at('2026-11-16 20:00');
        (new HyperSchedule)();
        $this->assertTrue(Setting::hyper());
        $this->at('2026-11-17 08:00');
        (new HyperSchedule)();
        $this->at('2026-11-17 17:00');
        (new HyperSchedule)();
        $this->assertFalse(Setting::hyper(), 'Off at the end.');
    }

    public function test_weekends_stay_off_and_the_next_start_names_its_day(): void
    {
        $this->at('2026-11-14 10:00'); // a Saturday
        $this->assertFalse(HyperSchedule::wanted());
        $this->assertSame('on Mon 08:00', HyperSchedule::next());
    }

    public function test_the_clock_is_brussels_time_not_the_servers(): void
    {
        // 07:30 UTC is 08:30 in Brussels in November.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 07:30', 'UTC'));
        $this->assertTrue(HyperSchedule::wanted());
    }

    public function test_it_is_set_on_setup_and_no_schedule_leaves_the_switch_alone(): void
    {
        $this->put('/setup/hyper-schedule', ['on' => true, 'days' => [1, 3], 'from' => '09:00', 'to' => '18:00', 'timezone' => 'Europe/Brussels'])->assertSessionHasNoErrors();
        $this->assertSame([1, 3], HyperSchedule::config()['days']);
        $this->put('/setup/hyper-schedule', ['on' => true, 'days' => [1], 'from' => '18:00', 'to' => '09:00', 'timezone' => 'Europe/Brussels'])->assertSessionHasErrors('to');

        $this->put('/setup/hyper-schedule', ['on' => false, 'days' => [], 'from' => '08:00', 'to' => '17:00', 'timezone' => 'UTC']);
        Setting::put(Setting::HYPER, '1');
        (new HyperSchedule)();
        $this->assertTrue(Setting::hyper());
        $this->assertNull(HyperSchedule::next());
    }
}
