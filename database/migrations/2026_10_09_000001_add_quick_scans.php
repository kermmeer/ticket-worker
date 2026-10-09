<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            // New tickets get a quick scan by themselves; only those that arrive after it is on.
            $table->boolean('quick_scan')->default(false)->after('hidden_slas');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('quick_scan_state', 20)->nullable();
            $table->json('quick_scan')->nullable();
            $table->json('quick_scan_steps')->nullable();
            $table->text('quick_scan_error')->nullable();
            $table->decimal('quick_scan_cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('quick_scan_tokens')->nullable();
            $table->timestamp('quick_scan_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spaces', fn (Blueprint $table) => $table->dropColumn('quick_scan'));
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn([
            'quick_scan_state', 'quick_scan', 'quick_scan_steps', 'quick_scan_error', 'quick_scan_cost_usd', 'quick_scan_tokens', 'quick_scan_at',
        ]));
    }
};
