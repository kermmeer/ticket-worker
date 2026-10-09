<?php

namespace App\Agent;

use App\Jobs\QuickScanTicket;
use App\Models\AgentTurn;
use App\Models\Ticket;

/**
 * Starts the next quick scan of a batch you asked for ("pending"), one at a time, and only
 * when nothing else waits for an agent: your analyses go first, and a batch of hundreds
 * never queues in front of them. Runs every minute, and again whenever a scan finishes.
 */
class FeedQuickScans
{
    public function __invoke(): void
    {
        if (Instructions::credentials() === []
            || Ticket::whereIn('quick_scan_state', ['queued', 'running'])->exists()
            || AgentTurn::where('state', AgentTurn::QUEUED)->exists()) {
            return;
        }

        // Newest first: they are the ones still worth sorting.
        $next = Ticket::where('quick_scan_state', 'pending')->orderByDesc('jira_created_at')->orderByDesc('id')->first();
        if ($next !== null) {
            QuickScanTicket::start($next);
        }
    }
}
