<?php

namespace App\Agent;

use App\Models\AgentSession;
use App\Models\AgentTurn;
use Illuminate\Support\Facades\DB;

/**
 * Chats that are over close by themselves: their ticket left the open list (resolved, moved),
 * or nobody asked anything for 14 days. Never one with an agent at work. The next question on
 * the ticket starts a fresh chat with a summary of this one, as after any quiet spell.
 */
class CloseIdleChats
{
    public const IDLE_DAYS = 14;

    public function __invoke(): int
    {
        $busy = AgentTurn::query()->whereIn('state', [AgentTurn::QUEUED, AgentTurn::RUNNING])->pluck('agent_session_id');

        $over = AgentSession::query()->where('state', 'open')->whereNotIn('id', $busy)
            ->where(fn ($query) => $query
                ->whereIn('ticket_id', DB::table('tickets')->whereNotNull('left_at')->select('id'))
                ->orWhere(fn ($quiet) => $quiet->where('created_at', '<', now()->subDays(self::IDLE_DAYS))
                    ->whereNotExists(fn ($recent) => $recent->from('agent_turns')->whereColumn('agent_turns.agent_session_id', 'agent_sessions.id')
                        ->where('agent_turns.created_at', '>', now()->subDays(self::IDLE_DAYS)))))
            ->pluck('id');

        return AgentSession::query()->whereKey($over)->update(['state' => 'closed', 'closed_at' => now()]);
    }
}
