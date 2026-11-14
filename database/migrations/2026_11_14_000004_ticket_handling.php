<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ticket handling that keeps track (CONCEPT.md, proposal of 14 November 2026): when each
     * person last looked at a ticket, how a ticket ended, and which kind of ticket a case is for.
     */
    public function up(): void
    {
        Schema::create('ticket_views', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->timestamp('seen_at');
            $table->primary(['user_id', 'ticket_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('done_how', 20)->nullable();
            $table->string('done_verdict', 10)->nullable();
            $table->string('done_note', 500)->nullable();
        });

        Schema::table('casebook_entries', function (Blueprint $table) {
            $table->string('kind', 12)->nullable();
        });
        // The cases so far are support cases; a development case says so from now on.
        DB::table('casebook_entries')->update(['kind' => 'support']);
    }

    public function down(): void
    {
        Schema::table('casebook_entries', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('done_by');
            $table->dropColumn(['done_at', 'done_how', 'done_verdict', 'done_note']);
        });
        Schema::dropIfExists('ticket_views');
    }
};
