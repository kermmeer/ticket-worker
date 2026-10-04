<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tokens as Claude Code reports them at the end of a run, next to what it cost.
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->unsignedBigInteger('input_tokens')->nullable()->after('cost_usd');
            $table->unsignedBigInteger('cache_read_tokens')->nullable()->after('input_tokens');
            $table->unsignedBigInteger('cache_write_tokens')->nullable()->after('cache_read_tokens');
            $table->unsignedBigInteger('output_tokens')->nullable()->after('cache_write_tokens');
        });
        Schema::table('systems', function (Blueprint $table) {
            $table->unsignedBigInteger('scan_input_tokens')->nullable()->after('scan_cost_usd');
            $table->unsignedBigInteger('scan_output_tokens')->nullable()->after('scan_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', fn (Blueprint $table) => $table->dropColumn(['input_tokens', 'cache_read_tokens', 'cache_write_tokens', 'output_tokens']));
        Schema::table('systems', fn (Blueprint $table) => $table->dropColumn(['scan_input_tokens', 'scan_output_tokens']));
    }
};
