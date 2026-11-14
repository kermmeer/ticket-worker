<?php

namespace App\Agent;

use App\Jobs\ScanSystem;
use App\Models\AuditEvent;
use App\Models\System;

/**
 * Systems set to rescan by themselves: when a push has moved the code past the context, the
 * context is updated (a rescan reads only what changed). Not in the middle of a series of
 * pushes: the last one must be ten minutes old; and not more than once an hour per system.
 * On the shared Claude sign-in, like an automatic quick scan.
 */
class AutoScanSystems
{
    public const QUIET_MINUTES = 10;

    public const AT_MOST_EVERY_MINUTES = 60;

    public function __invoke(): void
    {
        if (Instructions::instanceCredentials() === []) {
            return;
        }

        foreach (System::query()->where('auto_scan', true)->where('kind', System::CODE)->where('state', System::READY)->get() as $system) {
            if (! $this->due($system)) {
                continue;
            }
            $system->update(['scan_state' => 'queued', 'scan_error' => null, 'scan_log' => null, 'scan_user_id' => null]);
            ScanSystem::dispatch($system);
            AuditEvent::record('system.scan', $system->name, $system->context === null ? 'first scan, by itself after a push' : "rescan by itself: {$system->commitsBehind()} commits since the context");
        }
    }

    public function due(System $system): bool
    {
        if (in_array($system->scan_state, ['queued', 'running'], true) || $system->head() === null) {
            return false;
        }
        $behind = $system->context === null ? 1 : (int) $system->commitsBehind();
        if ($behind < 1) {
            return false;
        }
        if ($system->context_written_at && $system->context_written_at->gt(now()->subMinutes(self::AT_MOST_EVERY_MINUTES))) {
            return false;
        }
        // The last push: when the branch's ref last moved.
        $pushed = @filemtime($system->path().'/.git/logs/HEAD') ?: @filemtime($system->path().'/.git/refs/heads/'.$system->branch);

        return ! $pushed || $pushed < now()->subMinutes(self::QUIET_MINUTES)->getTimestamp();
    }
}
