<?php

namespace App\Agent;

use App\Jobs\QuickScanTicket;
use App\Models\AgentTurn;
use App\Models\Ticket;
use App\Models\User;

/**
 * Starts the next quick scan of a batch you asked for ("pending"), one at a time, and only
 * when nothing else waits for an agent: your analyses go first, and a batch of hundreds
 * never queues in front of them. Runs every minute, and again whenever a scan finishes.
 */
class FeedQuickScans
{
    public function __invoke(): void
    {
        if (Ticket::whereIn('quick_scan_state', ['queued', 'running'])->exists()
            || AgentTurn::where('state', AgentTurn::QUEUED)->exists()) {
            return;
        }

        // Newest first: they are the ones still worth sorting.
        $next = Ticket::where('quick_scan_state', 'pending')->orderByDesc('jira_created_at')->orderByDesc('id')->first();
        if ($next === null) {
            return;
        }
        $asker = $next->quick_scan_user_id ? User::find($next->quick_scan_user_id) : null;
        // A spent budget ends the batch's turn for this ticket, saying why.
        if (! Budget::allows($next->space, $asker)) {
            $next->forceFill(['quick_scan_state' => 'failed', 'quick_scan_error' => Budget::check($next->space, $asker)['text']])->save();

            return;
        }
        // On the sign-in of whoever asked for the batch, or the shared one.
        if (Instructions::credentials($asker) !== []) {
            QuickScanTicket::start($next);
        }
    }
}
