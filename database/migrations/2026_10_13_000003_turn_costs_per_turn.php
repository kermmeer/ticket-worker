<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Claude Code reports a resumed conversation's cost as the running total since its first
     * turn, and that total was kept as each turn's own cost and added up: a $11 session showed
     * as $57. Keep the total apart, and turn what was stored back into what each turn cost.
     */
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->decimal('claude_total_usd', 10, 4)->nullable()->after('cost_usd');
        });

        foreach (DB::table('agent_sessions')->pluck('id') as $sessionId) {
            $previous = 0.0;
            $sum = 0.0;
            foreach (DB::table('agent_turns')->where('agent_session_id', $sessionId)->whereNotNull('cost_usd')->orderBy('id')->get(['id', 'cost_usd']) as $turn) {
                $total = (float) $turn->cost_usd;
                $own = $total >= $previous ? $total - $previous : $total;
                DB::table('agent_turns')->where('id', $turn->id)->update(['claude_total_usd' => $total, 'cost_usd' => round($own, 4)]);
                $previous = $total;
                $sum += $own;
            }
            DB::table('agent_sessions')->where('id', $sessionId)->update(['cost_usd' => round($sum, 4)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('agent_turns')->whereNotNull('claude_total_usd')->get(['id', 'claude_total_usd']) as $turn) {
            DB::table('agent_turns')->where('id', $turn->id)->update(['cost_usd' => $turn->claude_total_usd]);
        }
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropColumn('claude_total_usd');
        });
    }
};
