<?php

namespace App\Jobs;

use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Models\System;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes a system's context (CONCEPT.md §5): an agent reads the system once, read-only,
 * and writes the short map every later analysis starts from. A rescan hands it the old
 * context and what changed since, and asks for an update rather than a fresh start.
 */
class ScanSystem implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 2400;

    public function __construct(public System $system)
    {
        $this->onQueue('agents');
    }

    public function handle(ClaudeRun $runner): void
    {
        $system = $this->system->fresh();
        if ($system === null || $system->scan_state !== 'queued') {
            return;
        }

        $system->update(['scan_state' => 'running', 'scan_log' => '', 'scan_error' => null, 'scan_stop_requested' => false]);
        // Its own working folder next to the tickets' ones; the code itself is passed read-only.
        $cwd = dirname(config('agent.workspaces_path')).'/scans/'.$system->name;
        File::ensureDirectoryExists($cwd);
        $log = [];

        try {
            $head = $system->headCommit();
            if ($head === null) {
                throw new \RuntimeException('Nothing has been pushed to this system yet.');
            }

            $command = [...ClaudeRun::command((float) config('agent.scan_budget_usd')),
                '--no-session-persistence',
                '--append-system-prompt', Instructions::scanRules(),
                '--add-dir', $system->path(),
            ];

            $run = $runner->run(
                $command, $cwd, Instructions::scan($system, $this->changesSince($system)), [$system->path() => $system->name.'/'],
                function (string $type, string $summary) use ($system, &$log) {
                    if ($type === 'tool') {
                        $log[] = $summary;
                        $system->update(['scan_log' => implode("\n", array_slice($log, -40))]);
                    }
                },
                fn () => (bool) $system->fresh()->scan_stop_requested,
                $this->timeout,
            );

            $result = $run['result'];
            $body = trim((string) ($result['result'] ?? ''));

            if ($run['stopped']) {
                $system->update(['scan_state' => 'idle', 'scan_error' => 'Stopped.']);
            } elseif ($result === null || ($result['is_error'] ?? false) || $body === '') {
                $system->update(['scan_state' => 'failed', 'scan_error' => Str::limit($body ?: trim($run['errors']) ?: 'The scan ended without a context.', 2000)]);
            } else {
                $system->writeContext($body, $head, 'agent');
                $system->update(['scan_state' => 'idle', 'scan_cost_usd' => $result['total_cost_usd'] ?? null]);
            }
        } catch (Throwable $e) {
            $system->update(['scan_state' => 'failed', 'scan_error' => Str::limit($e->getMessage(), 2000)]);
        }
    }

    /** The queue gave up on it (a worker died, say): the page must not wait forever. */
    public function failed(?Throwable $e): void
    {
        $system = $this->system->fresh();
        if ($system !== null && in_array($system->scan_state, ['queued', 'running'], true)) {
            $system->update(['scan_state' => 'failed', 'scan_error' => 'Interrupted: the agent container stopped. Scan again.']);
        }
    }

    /** For a rescan: the commits since the context was written, and the files they touched. */
    private function changesSince(System $system): ?string
    {
        if ($system->context === null || $system->context_commit === null) {
            return null;
        }

        $result = Process::path($system->path())->run([
            'git', '-c', 'safe.directory=*', 'log', '--name-only', '--format=%n%h %ad %s', '--date=short',
            $system->context_commit.'..HEAD',
        ]);

        return $result->successful() ? Str::limit(trim($result->output()), 20000) : null;
    }
}
