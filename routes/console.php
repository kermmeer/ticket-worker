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

// Hyper mode on and off by its schedule, at the start and end of the working day (Setup).
Schedule::call(new \App\Sync\HyperSchedule)->everyMinute()->name('hyper-schedule');

// Spaces sync every SYNC_EVERY_MINUTES, or every minute in hyper mode.
Schedule::call(new DispatchDueSyncs)->everyMinute()->name('dispatch-due-syncs')->withoutOverlapping();

// A turn or scan cut off by a restart is marked as such, rather than running forever.
Schedule::call(new StaleRuns)->everyFiveMinutes()->name('stale-runs');

// A batch of quick scans, one at a time, after whatever else waits for an agent.
Schedule::call(new FeedQuickScans)->everyMinute()->name('feed-quick-scans')->withoutOverlapping();

// Systems set to rescan by themselves: after a push that moved the code past their context.
Schedule::call(new \App\Agent\AutoScanSystems)->everyFiveMinutes()->name('auto-scan-systems')->withoutOverlapping();

// Chats that are over close by themselves: ticket resolved, or 14 days without a question.
Schedule::call(new \App\Agent\CloseIdleChats)->hourly()->name('close-idle-chats');

// Tickets' workspaces and transcripts go 30 days after their work ended (CONCEPT.md §11).
Schedule::call(new ForgetOldWork)->dailyAt('03:17')->name('forget-old-work');

// An SLA under an hour from breaking, in its space's channel, once per deadline.
Schedule::call(new WarnSlas)->everyFiveMinutes()->name('warn-slas')->withoutOverlapping();

// Projects with a reachable Git host are fetched every night; pushing stays the way in for the rest.
Schedule::call(function () {
    \App\Models\System::query()->whereNotNull('pull_url')->where('kind', \App\Models\System::CODE)->each(fn ($system) => \App\Jobs\PullSystem::dispatch($system));
})->dailyAt('02:43')->name('pull-systems');
