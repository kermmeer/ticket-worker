<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * The agent container's main process: up to N agents at once, N from the Setup page.
 *
 * Each agent is a `queue:work --once` of its own, started only when a turn waits and ended
 * when that turn is done. So a lower N never cuts an agent off: it only starts fewer; and
 * every agent runs the code as it is now, without a restart. The image has no pcntl, so
 * nothing here relies on catching signals.
 */
class AgentPool extends Command
{
    protected $signature = 'agent:pool {--sleep=3 : seconds between looks at the queue}';

    protected $description = 'Run up to the configured number of agents at once';

    public function handle(): int
    {
        /** @var list<Process> $running */
        $running = [];
        $restartAt = Cache::get('illuminate:queue:restart');
        $this->info('Agent pool started: up to '.Setting::agents().' at once.');

        while (true) {
            $running = array_values(array_filter($running, function (Process $agent) {
                if ($agent->isRunning()) {
                    return true;
                }
                $this->output->write($agent->getIncrementalOutput());

                return false;
            }));
            foreach ($running as $agent) {
                $this->output->write($agent->getIncrementalOutput());
            }

            // queue:restart (after a deploy): no new agents; leave once the running ones are done,
            // and the container's restart policy brings the pool back on the new code.
            if (Cache::get('illuminate:queue:restart') !== $restartAt) {
                if ($running === []) {
                    $this->info('Restarting for new code.');

                    return self::SUCCESS;
                }
                sleep((int) $this->option('sleep'));

                continue;
            }

            $room = Setting::agents() - count($running);
            $waiting = $room > 0 ? $this->waiting() : 0;
            for ($i = 0; $i < min($room, $waiting); $i++) {
                $agent = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', '--queue=agents', '--once', '--tries=1', '--timeout=1800'], base_path(), null, null, null);
                $agent->start();
                $running[] = $agent;
            }

            sleep((int) $this->option('sleep'));
        }
    }

    /** Turns waiting on the agents queue, ready to start now. */
    private function waiting(): int
    {
        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->where('queue', 'agents')
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->count();
    }
}
