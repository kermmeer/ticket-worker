<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Inertia\Inertia;
use Inertia\Response;

/** The audit trail, for admins: who changed what in the setup, newest first. */
class AuditController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Audit', [
            'events' => AuditEvent::query()->with('user:id,name')->latest('id')->limit(500)->get()->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'at' => $event->created_at?->toIso8601String(),
                'who' => $event->user?->name ?? 'the system',
                'action' => $event->action,
                'subject' => $event->subject,
                'summary' => $event->summary,
            ]),
        ]);
    }
}
