<?php

namespace App\Sync;

use App\Models\AuditEvent;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Hyper mode by the clock: on at the start of the working day, off at its end, on the days
 * chosen. Only at those two moments: a switch by hand in between holds until the next one.
 */
class HyperSchedule
{
    public const SETTING = 'hyper_schedule';

    /** What the schedule last asked for: a change of it is a moment to switch. */
    public const STATE = 'hyper_schedule_state';

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** @return array{on: bool, days: list<int>, from: string, to: string, timezone: string} */
    public static function config(): array
    {
        $saved = json_decode((string) Setting::get(self::SETTING), true) ?: [];

        return [
            'on' => (bool) ($saved['on'] ?? false),
            'days' => array_values(array_map('intval', $saved['days'] ?? [1, 2, 3, 4, 5])),
            'from' => $saved['from'] ?? '08:00',
            'to' => $saved['to'] ?? '17:00',
            'timezone' => $saved['timezone'] ?? 'Europe/Brussels',
        ];
    }

    public static function save(array $config): void
    {
        Setting::put(self::SETTING, json_encode($config));
        // Applied at the next minute, as if a boundary had just passed.
        Setting::put(self::STATE, null);
    }

    /** What the schedule wants now: null when there is no schedule. */
    public static function wanted(?CarbonImmutable $now = null): ?bool
    {
        $config = self::config();
        if (! $config['on']) {
            return null;
        }
        $now = ($now ?? CarbonImmutable::now())->setTimezone($config['timezone']);
        $time = $now->format('H:i');

        return in_array($now->dayOfWeekIso, $config['days'], true) && $time >= $config['from'] && $time < $config['to'];
    }

    /** Every minute: at a boundary, switch hyper mode to what the schedule wants. */
    public function __invoke(): void
    {
        $wanted = self::wanted();
        if ($wanted === null || Setting::get(self::STATE) === ($wanted ? '1' : '0')) {
            return;
        }
        Setting::put(self::STATE, $wanted ? '1' : '0');
        if (Setting::hyper() === $wanted) {
            return;
        }
        Setting::put(Setting::HYPER, $wanted ? '1' : '0');
        AuditEvent::record('hyper', null, $wanted ? 'hyper mode on by its schedule' : 'hyper mode off by its schedule');
        if ($wanted) {
            (new DispatchDueSyncs)();
        }
    }

    /** "until 17:00", "on at 08:00", "on Mon 08:00": the next switch, for the header; null without a schedule. */
    public static function next(?CarbonImmutable $now = null): ?string
    {
        $config = self::config();
        if (! $config['on'] || $config['days'] === []) {
            return null;
        }
        $now = ($now ?? CarbonImmutable::now())->setTimezone($config['timezone']);
        if (self::wanted($now)) {
            return "until {$config['to']}";
        }
        for ($day = 0; $day < 8; $day++) {
            $date = $now->addDays($day);
            if (in_array($date->dayOfWeekIso, $config['days'], true) && ($day > 0 || $now->format('H:i') < $config['from'])) {
                return $day === 0 ? "on at {$config['from']}" : 'on '.self::DAYS[$date->dayOfWeekIso]." {$config['from']}";
            }
        }

        return null;
    }
}
