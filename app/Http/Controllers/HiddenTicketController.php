<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;

/** Tickets you will not take on: off the board, not out of Jira. */
class HiddenTicketController extends Controller
{
    public function store(Ticket $ticket): RedirectResponse
    {
        $ticket->update(['hidden_at' => now()]);

        return back()->with('success', "{$ticket->key} is hidden. It waits at the bottom of the overview.");
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $ticket->update(['hidden_at' => null]);

        return back()->with('success', "{$ticket->key} is back on the board.");
    }
}
