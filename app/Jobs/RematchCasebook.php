<?php

namespace App\Jobs;

use App\Casebook\Matcher;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After the casebook changes, every open ticket is matched again, so a new or
 * corrected entry shows on the tickets it fits without waiting for their next sync.
 */
class RematchCasebook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function handle(): void
    {
        foreach (Space::query()->where('state', Space::ACTIVE)->get() as $space) {
            $casebook = Matcher::forSpace($space);
            $rejected = Matcher::rejections();

            $space->tickets()->open()->each(function (Ticket $ticket) use ($casebook, $rejected) {
                $ticket->rememberMatch($casebook->best($ticket->matchText(), $rejected[$ticket->id] ?? []));
                if ($ticket->isDirty()) {
                    $ticket->save();
                }
            });
        }
    }
}
