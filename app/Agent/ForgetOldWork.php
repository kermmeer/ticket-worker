<?php

namespace App\Agent;

use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Keeps the promise in CONCEPT.md §11: a ticket's workspace (its text, attachments, patch
 * copies) and its Claude transcripts are deleted 30 days after its work ended. What the
 * database keeps (proposals, closing notes, costs) stays. Runs daily.
 */
class ForgetOldWork
{
    public const DAYS = 30;

    /** @return list<string> the workspaces it deleted, by folder name */
    public function __invoke(): array
    {
        $root = config('agent.workspaces_path');
        if (! is_dir($root)) {
            return [];
        }

        $cutoff = now()->subDays(self::DAYS);
        $tickets = Ticket::query()->with('sessions')->get()->keyBy(fn (Ticket $ticket) => Str::slug($ticket->key, '-'));
        $gone = [];

        foreach (File::directories($root) as $dir) {
            $ticket = $tickets[basename($dir)] ?? null;

            if ($ticket !== null) {
                // An open session keeps everything: its conversation needs it.
                if ($ticket->sessions->contains(fn ($session) => $session->state !== 'closed')) {
                    continue;
                }
                $last = collect([$ticket->quick_scan_at, ...$ticket->sessions->map(fn ($session) => $session->closed_at ?? $session->updated_at)])
                    ->filter()->max();
            }
            $last ??= Carbon::createFromTimestamp(filemtime($dir));

            if ($last->lt($cutoff)) {
                File::deleteDirectory($dir);
                // The CLI keeps a session's transcript in a folder named after where it ran.
                File::deleteDirectory(rtrim((string) config('agent.claude.config_dir'), '/').'/projects/'.preg_replace('/[^A-Za-z0-9]/', '-', $dir));
                $gone[] = basename($dir);
            }
        }

        return $gone;
    }
}
