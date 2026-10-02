<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Sync\DispatchDueSyncs;
use Illuminate\Http\RedirectResponse;

/** Hyper mode on or off: every minute instead of every SYNC_EVERY_MINUTES (CONCEPT.md §6). */
class HyperModeController extends Controller
{
    public function __invoke(DispatchDueSyncs $dispatch): RedirectResponse
    {
        $on = ! Setting::hyper();
        Setting::put(Setting::HYPER, $on ? '1' : '0');

        // Switching it on means "now": whatever is due by the new interval goes at once.
        if ($on) {
            $dispatch();
        }

        return back();
    }
}
