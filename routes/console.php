<?php

use App\Agent\FeedQuickScans;
use App\Agent\ForgetOldWork;
use App\Agent\StaleRuns;
use App\Notify\WarnSlas;
use App\Jobs\ReportHealth;
use App\Sync\DispatchDueSyncs;
use Illuminate\Support\Facades\Schedule;

// Each queue proves it is being served; the Setup page reads the answers.
Schedule::job(new ReportHealth('default'))->everyMinute();
Schedule::job(new ReportHealth('agents'))->everyMinute();

// Spaces sync every SYNC_EVERY_MINUTES, or every minute in hyper mode.
Schedule::call(new DispatchDueSyncs)->everyMinute()->name('dispatch-due-syncs')->withoutOverlapping();

// A turn or scan cut off by a restart is marked as such, rather than running forever.
Schedule::call(new StaleRuns)->everyFiveMinutes()->name('stale-runs');

// A batch of quick scans, one at a time, after whatever else waits for an agent.
Schedule::call(new FeedQuickScans)->everyMinute()->name('feed-quick-scans')->withoutOverlapping();

// Tickets' workspaces and transcripts go 30 days after their work ended (CONCEPT.md §11).
Schedule::call(new ForgetOldWork)->dailyAt('03:17')->name('forget-old-work');

// An SLA under an hour from breaking, in its space's channel, once per deadline.
Schedule::call(new WarnSlas)->everyFiveMinutes()->name('warn-slas')->withoutOverlapping();

// Projects with a reachable Git host are fetched every night; pushing stays the way in for the rest.
Schedule::call(function () {
    \App\Models\System::query()->whereNotNull('pull_url')->where('kind', \App\Models\System::CODE)->each(fn ($system) => \App\Jobs\PullSystem::dispatch($system));
})->dailyAt('02:43')->name('pull-systems');
