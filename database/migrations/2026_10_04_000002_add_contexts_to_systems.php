<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The system context (CONCEPT.md §5): a short map of the system every agent starts from.
        Schema::table('systems', function (Blueprint $table) {
            $table->longText('context')->nullable()->after('error');
            // The commit the context describes, to tell how far the code has moved since.
            $table->string('context_commit')->nullable()->after('context');
            $table->timestamp('context_written_at')->nullable()->after('context_commit');
            // idle, queued, running, failed: the scan that writes it.
            $table->string('scan_state')->default('idle')->after('context_written_at');
            $table->boolean('scan_stop_requested')->default(false)->after('scan_state');
            $table->text('scan_log')->nullable()->after('scan_stop_requested');
            $table->text('scan_error')->nullable()->after('scan_log');
            $table->decimal('scan_cost_usd', 10, 4)->nullable()->after('scan_error');
        });

        // Every version, so an edit or a rescan can be undone.
        Schema::create('system_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained()->cascadeOnDelete();
            $table->longText('body');
            $table->string('commit')->nullable();
            // agent or you.
            $table->string('written_by');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_contexts');
        Schema::table('systems', function (Blueprint $table) {
            $table->dropColumn(['context', 'context_commit', 'context_written_at', 'scan_state', 'scan_stop_requested', 'scan_log', 'scan_error', 'scan_cost_usd']);
        });
    }
};
