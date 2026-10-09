<?php

namespace App\Http\Controllers;

use App\Models\AgentTurn;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What finished for you since the page last asked: your agent turns and quick scans. The
 * page turns each into a browser notification, so nobody has to watch an analysis run.
 */
class NoticeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $since = Carbon::parse($request->query('since', now()->subMinute()->toIso8601String()))->max(now()->subHour());
        $user = $request->user();
        $labels = ['analysis' => 'Analysis', 'message' => 'Answer', 'proposal' => 'Proposal', 'case' => 'Case draft', 'patch' => 'Patch'];

        $turns = AgentTurn::query()->with('session.ticket:id,key,summary')
            ->where('user_id', $user->id)->whereIn('state', [AgentTurn::DONE, AgentTurn::FAILED])->where('finished_at', '>', $since)
            ->get()->map(fn (AgentTurn $turn) => [
                'id' => "turn-{$turn->id}",
                'title' => ($labels[$turn->kind] ?? 'Agent').($turn->state === AgentTurn::FAILED ? ' failed' : ' ready').': '.$turn->session->ticket->key,
                'body' => $turn->session->ticket->summary,
                'url' => route('tickets.show', $turn->session->ticket, false),
            ]);

        $scans = Ticket::query()->where('quick_scan_user_id', $user->id)->whereIn('quick_scan_state', ['done', 'failed'])->where('quick_scan_at', '>', $since)
            ->limit(5)->get()->map(fn (Ticket $ticket) => [
                'id' => "scan-{$ticket->id}-{$ticket->quick_scan_at?->timestamp}",
                'title' => "Quick scan: {$ticket->key}",
                'body' => $ticket->quick_scan['summary'] ?? $ticket->summary,
                'url' => route('tickets.show', $ticket, false),
            ]);

        return response()->json(['now' => now()->toIso8601String(), 'notices' => $turns->concat($scans)->values()]);
    }
}
