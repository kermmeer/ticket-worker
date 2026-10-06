<?php

namespace App\Agent;

use App\Jobs\RunAgentTurn;
use App\Jobs\ScanSystem;
use App\Models\AgentTurn;
use App\Models\System;

/**
 * A turn or scan whose worker was killed outright (a container restart, say) never gets
 * to say so, and the page would show it running forever. Past its own time limit it can
 * no longer be running: mark it, so you can start it again.
 */
class StaleRuns
{
    /** The jobs' time limits, plus a margin for the worker to notice. */
    private const MARGIN_SECONDS = 300;

    public function __invoke(): void
    {
        AgentTurn::query()
            ->where('state', AgentTurn::RUNNING)
            ->where('started_at', '<', now()->subSeconds((new \ReflectionClass(RunAgentTurn::class))->getDefaultProperties()['timeout'] + self::MARGIN_SECONDS))
            ->update(['state' => AgentTurn::FAILED, 'error' => 'Interrupted: the agent stopped without finishing. Ask again.', 'finished_at' => now()]);

        // A running scan writes its log as it goes, so a quiet one is a dead one.
        System::query()
            ->where('scan_state', 'running')
            ->where('updated_at', '<', now()->subSeconds((new \ReflectionClass(ScanSystem::class))->getDefaultProperties()['timeout'] + self::MARGIN_SECONDS))
            ->update(['scan_state' => 'failed', 'scan_error' => 'Interrupted: the scan stopped without finishing. Scan again.']);
    }
}
