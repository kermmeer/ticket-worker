<?php

namespace App\Http\Controllers;

use App\Models\ApiCall;
use App\Models\AuditEvent;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Throwable;

/**
 * A change the agent proposed: run by a person, or refused. The only place that sends a
 * call that changes data, and only after someone pressed Run it. Editors and admins.
 */
class ApiCallController extends Controller
{
    public function run(Ticket $ticket, ApiCall $call): RedirectResponse
    {
        abort_unless($call->ticket_id === $ticket->id, 404);
        abort_unless(auth()->user()?->atLeast('editor'), 403, 'Running a change is for editors and admins.');
        if ($call->state !== ApiCall::WAITING) {
            return back()->with('error', 'Already decided.');
        }
        // Taken before it is sent: a second press does not send it twice.
        if (ApiCall::query()->whereKey($call->id)->where('state', ApiCall::WAITING)->update(['state' => 'running']) === 0) {
            return back()->with('error', 'Already decided.');
        }

        try {
            $answer = $call->api->send($call->method, $call->path, $call->query ?? [], $call->body);
            $state = $answer['status'] >= 200 && $answer['status'] < 300 ? 'done' : 'failed';
            $call->update(['state' => $state, 'status' => $answer['status'], 'response' => Str::limit($answer['body'], 60000), 'decided_by' => auth()->id(), 'decided_at' => now()]);
        } catch (Throwable $e) {
            $call->update(['state' => 'failed', 'response' => Str::limit($call->api->redact($e->getMessage()), 2000), 'decided_by' => auth()->id(), 'decided_at' => now()]);
        }
        AuditEvent::record('api.change', $call->label(), "run on {$ticket->key}: ".($call->status ? "HTTP {$call->status}" : 'no answer'));

        return back()->with($call->state === 'done' ? 'success' : 'error', $call->state === 'done'
            ? "Run: HTTP {$call->status}. The agent hears it with your next question."
            : 'It did not go through'.($call->status ? " (HTTP {$call->status})" : '').': see the answer below the change.');
    }

    public function refuse(Ticket $ticket, ApiCall $call): RedirectResponse
    {
        abort_unless($call->ticket_id === $ticket->id, 404);
        if ($call->state !== ApiCall::WAITING) {
            return back()->with('error', 'Already decided.');
        }
        $call->update(['state' => 'refused', 'decided_by' => auth()->id(), 'decided_at' => now()]);
        AuditEvent::record('api.change', $call->label(), "refused on {$ticket->key}");

        return back()->with('success', 'Refused. The agent hears it with your next question.');
    }
}
