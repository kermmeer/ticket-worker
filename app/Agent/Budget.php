<?php

namespace App\Agent;

use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use App\Models\User;
use App\Settings\SpaceSettings;

/**
 * Monthly budgets (docs/TEAM.md, item 9): per space and per person, none unless set. At 80%
 * the pages warn; at 100% no new agent work starts there this month. Costs are what the
 * API charges, also for work on a subscription.
 */
class Budget
{
    public const WARN_AT = 0.8;

    /** @return array{cost: float, turns: int, scans: int} what a person's work cost this month */
    public static function personThisMonth(int $userId): array
    {
        $since = now()->startOfMonth();
        $turns = AgentTurn::query()->where('created_at', '>=', $since)->where('user_id', $userId);
        $scans = Ticket::query()->where('quick_scan_at', '>=', $since)->where('quick_scan_user_id', $userId);

        return [
            'cost' => round((float) (clone $turns)->sum('cost_usd') + (float) (clone $scans)->sum('quick_scan_cost_usd'), 2),
            'turns' => (clone $turns)->count(),
            'scans' => (clone $scans)->count(),
        ];
    }

    /** What all work on a space cost this month, whoever asked. */
    public static function spaceThisMonth(Space $space): float
    {
        $since = now()->startOfMonth();
        $turns = AgentTurn::query()->where('agent_turns.created_at', '>=', $since)
            ->join('agent_sessions', 'agent_sessions.id', '=', 'agent_turns.agent_session_id')
            ->join('tickets', 'tickets.id', '=', 'agent_sessions.ticket_id')
            ->where('tickets.space_id', $space->id)->sum('agent_turns.cost_usd');
        $scans = Ticket::query()->where('space_id', $space->id)->where('quick_scan_at', '>=', $since)->sum('quick_scan_cost_usd');

        return round((float) $turns + (float) $scans, 2);
    }

    /**
     * Where new work on this space by this person stands: null when no budget is near, or
     * ['level' => 'warn'|'stop', 'text' => …] saying which budget and how far.
     */
    public static function check(Space $space, ?User $user): ?array
    {
        $found = [];
        if (($limit = SpaceSettings::for($space)->get('monthly_budget_usd')) !== null) {
            $found[] = ['what' => "{$space->label}'s budget", 'spent' => self::spaceThisMonth($space), 'limit' => (float) $limit];
        }
        if ($user?->monthly_budget_usd !== null && $user !== null) {
            $found[] = ['what' => 'your budget', 'spent' => self::personThisMonth($user->id)['cost'], 'limit' => (float) $user->monthly_budget_usd];
        }

        $worst = collect($found)->sortByDesc(fn ($budget) => $budget['spent'] / max($budget['limit'], 0.01))->first();
        if ($worst === null || $worst['spent'] < $worst['limit'] * self::WARN_AT) {
            return null;
        }

        $stop = $worst['spent'] >= $worst['limit'];

        return [
            'level' => $stop ? 'stop' : 'warn',
            'text' => sprintf('%s: $%.2f of $%.2f this month%s', ucfirst($worst['what']), $worst['spent'], $worst['limit'],
                $stop ? '. No new agent work starts until next month, or until an admin raises it.' : '.'),
        ];
    }

    public static function allows(Space $space, ?User $user): bool
    {
        return (self::check($space, $user)['level'] ?? null) !== 'stop';
    }
}
