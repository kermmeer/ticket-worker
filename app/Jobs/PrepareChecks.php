<?php

namespace App\Jobs;

use App\Agent\Checks;
use App\Models\System;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * A system's checks workspace made ready: its runtime downloaded, its setup command run.
 * On the agents queue, in the agent container, where the checks run too.
 */
class PrepareChecks implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 2400;

    public function __construct(public System $system)
    {
        $this->onQueue('agents');
    }

    public function handle(Checks $checks): void
    {
        $system = $this->system->fresh();
        $system->update(['checks_state' => 'preparing', 'checks_log' => null]);

        try {
            $log = $checks->prepare($system);
            $system->update(['checks_state' => 'ready', 'checks_log' => $log, 'checks_prepared_at' => now()]);
        } catch (Throwable $e) {
            $system->update(['checks_state' => 'failed', 'checks_log' => Str::substr($e->getMessage(), -30000)]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->system->fresh()?->update(['checks_state' => 'failed', 'checks_log' => 'Interrupted: '.($e?->getMessage() ?? 'the agent container stopped.')]);
    }
}
