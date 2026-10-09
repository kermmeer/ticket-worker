<?php

namespace App\Notify;

use App\Models\Space;
use App\Models\Ticket;

/**
 * An SLA less than an hour from breaking says so in its space's channel, once per deadline.
 * Runs every five minutes, from the SLA times of the last sync.
 */
class WarnSlas
{
    public const WITHIN_MINUTES = 60;

    public function __invoke(): void
    {
        $spaces = Space::query()->where('state', Space::ACTIVE)->whereNotNull('notify_webhook')->get();

        foreach ($spaces as $space) {
            $tickets = Ticket::query()->where('space_id', $space->id)->open()->whereNull('hidden_at')->whereNotNull('slas')->get();
            foreach ($tickets as $ticket) {
                $warned = $ticket->sla_warned ?? [];
                foreach ($space->visibleSlas($ticket->slas) as $sla) {
                    if (($sla['state'] ?? null) !== 'running' || empty($sla['due_at'])) {
                        continue;
                    }
                    $due = \Illuminate\Support\Carbon::parse($sla['due_at']);
                    $key = $sla['name'].'@'.$due->toIso8601String();
                    if ($due->isPast() || $due->gt(now()->addMinutes(self::WITHIN_MINUTES)) || in_array($key, $warned, true)) {
                        continue;
                    }
                    $minutes = max(1, (int) now()->diffInMinutes($due));
                    if (SpaceWebhook::send($space, "\u{23F0} {$ticket->key}: {$sla['name']} breaks in {$minutes} min. {$ticket->summary}", route('tickets.show', $ticket), 'Open in Ticket Worker') === null) {
                        $warned[] = $key;
                    }
                }
                if ($warned !== ($ticket->sla_warned ?? [])) {
                    $ticket->forceFill(['sla_warned' => array_slice($warned, -20)])->saveQuietly();
                }
            }
        }
    }
}
