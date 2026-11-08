<?php

namespace App\Jobs;

use App\Agent\ScratchCopy;
use App\Models\AgentTurn;
use App\Models\System;
use App\Models\TicketBranch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A build's branch into the system's repository on the server, where your clones reach it
 * as the "ticket-worker" remote. On the default queue: the agent container only reads the
 * systems, the worker may write them.
 */
class PublishBranch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public AgentTurn $turn) {}

    public function handle(ScratchCopy $git): void
    {
        $turn = $this->turn->fresh();
        $meta = $turn->patch_meta ?? [];
        $system = System::find($meta['system_id'] ?? null);
        $ticket = $turn->session->ticket;
        if ($system === null || empty($meta['branch'])) {
            return;
        }

        try {
            $git->publish(ScratchCopy::buildPath($ticket, $system), $system, $meta['branch']);
            $stats = $git->stats($system->path(), $meta['branch'], $meta['base']);
        } catch (Throwable $e) {
            $turn->events()->create(['type' => 'error', 'summary' => 'The branch could not be put on the server: '.$e->getMessage()]);

            return;
        }

        TicketBranch::updateOrCreate(
            ['ticket_id' => $ticket->id, 'system_id' => $system->id],
            ['name' => $meta['branch'], 'base' => $meta['base']] + $stats,
        );
        $turn->update(['patch_meta' => [...$meta, 'published' => true, 'head' => $stats['head']]]);
    }
}
