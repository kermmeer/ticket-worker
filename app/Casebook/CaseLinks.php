<?php

namespace App\Casebook;

use App\Models\AuditEvent;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * A case hint, judged: confirmed (it is that case: it groups with the others, and the matcher
 * leaves it be) or rejected (not this case, for good: the next best one may take its place).
 */
class CaseLinks
{
    public static function confirm(Ticket $ticket, ?int $caseId = null, string $by = 'you'): void
    {
        $caseId ??= $ticket->casebook_entry_id;
        if ($caseId === null) {
            return;
        }
        DB::table('casebook_rejections')->where(['casebook_entry_id' => $caseId, 'ticket_id' => $ticket->id])->delete();
        $ticket->forceFill(['casebook_entry_id' => $caseId, 'casebook_manual' => true])->save();
        AuditEvent::record('case.confirm', $ticket->key, "case {$caseId}, confirmed by {$by}");
    }

    public static function reject(Ticket $ticket): void
    {
        $caseId = $ticket->casebook_entry_id;
        if ($caseId === null) {
            return;
        }
        DB::table('casebook_rejections')->insertOrIgnore(['casebook_entry_id' => $caseId, 'ticket_id' => $ticket->id, 'user_id' => auth()->id(), 'created_at' => now()]);
        $ticket->casebook_manual = false;
        $ticket->rememberMatch(Matcher::forSpace($ticket->space)->best($ticket->matchText(), Matcher::rejections()[$ticket->id] ?? [], $ticket->kind()));
        $ticket->save();
        AuditEvent::record('case.unlink', $ticket->key, "not case {$caseId}");
    }
}
