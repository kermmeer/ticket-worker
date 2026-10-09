<?php

use App\Agent\FeedQuickScans;
use App\Agent\StaleRuns;
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
