<?php

namespace App\Sync;

use App\Jobs\SyncSpace;
use App\Models\Setting;
use App\Models\Space;

/**
 * Run by the scheduler every minute: queues a sync for every active space that is due.
 * Due means every SYNC_EVERY_MINUTES, or every minute in hyper mode (CONCEPT.md §6).
 */
class DispatchDueSyncs
{
    public function __invoke(): void
    {
        $every = Setting::hyper() ? 1 : max(1, (int) config('agent.sync_every_minutes'));
        // A little slack, so a sync that started a few seconds into the minute is not
        // skipped the next time round.
        $before = now()->subMinutes($every)->addSeconds(10);

        Space::query()
            ->where('state', Space::ACTIVE)
            ->where(fn ($query) => $query->whereNull('sync_attempted_at')->orWhere('sync_attempted_at', '<=', $before))
            ->each(fn (Space $space) => SyncSpace::dispatch($space));
    }
}
