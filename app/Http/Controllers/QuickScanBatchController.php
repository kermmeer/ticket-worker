<?php

namespace App\Http\Controllers;

use App\Agent\FeedQuickScans;
use App\Agent\Instructions;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Quick scans for many tickets at once, from the overview: they wait as "pending" and go
 * one at a time (FeedQuickScans), so they never hold up an analysis.
 */
class QuickScanBatchController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless(Instructions::credentials() !== [], 409, 'No Anthropic sign-in yet: see Setup.');
        $data = $request->validate(['ids' => ['required', 'array', 'max:1000'], 'ids.*' => ['integer']]);

        // Open, not hidden, and without a scan yet (or with one that failed).
        $count = Ticket::query()->visibleTo($request->user())->whereIn('id', $data['ids'])->whereNull('left_at')->whereNull('hidden_at')
            ->where(fn ($query) => $query->whereNull('quick_scan_state')->orWhere('quick_scan_state', 'failed'))
            ->update(['quick_scan_state' => 'pending', 'quick_scan_error' => null, 'quick_scan_user_id' => auth()->id()]);

        (new FeedQuickScans)();

        return back()->with('success', "{$count} tickets will get a quick scan, one at a time; your analyses go first.");
    }

    /** Stop the rest: what is waiting goes back to no scan; the one running finishes. */
    public function destroy(): RedirectResponse
    {
        $count = Ticket::where('quick_scan_state', 'pending')->update(['quick_scan_state' => null]);

        return back()->with('success', "Stopped: {$count} quick scans will not run.");
    }
}
