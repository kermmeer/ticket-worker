<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Proof of life from a queue. The scheduler sends one to each queue every minute, and
 * whichever container serves that queue answers by leaving a report in the cache.
 * php-fpm cannot look into the other containers; this is how the Setup page learns
 * that they run, and which Claude Code the agent container has.
 */
class ReportHealth implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $for)
    {
        $this->onQueue($for);
    }

    public function handle(): void
    {
        $report = [
            'at' => now()->toIso8601String(),
            'host' => gethostname(),
        ];

        if ($this->for === 'agents') {
            $report['claude'] = $this->claudeVersion();
            $report['workspaces_writable'] = is_writable(config('agent.workspaces_path'));
            $report['systems_readable'] = is_readable(config('agent.systems_path'));
        }

        Cache::put("health.{$this->for}", $report, now()->addDay());
    }

    private function claudeVersion(): ?string
    {
        try {
            $result = Process::timeout(30)->run([config('agent.claude.bin'), '--version']);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? trim($result->output()) : null;
    }
}
