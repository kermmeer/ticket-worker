<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solved cases, written once, read by every later agent (CONCEPT.md §8, the casebook).
        Schema::create('casebook_entries', function (Blueprint $table) {
            $table->id();
            // The system the case is about; null when it is not about one in particular.
            $table->foreignId('system_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // How it shows up: what reporters write, error messages, screens.
            $table->text('symptoms');
            $table->text('cause')->nullable();
            $table->text('solution');
            // Extra words to match on, in every language tickets come in.
            $table->text('keywords')->nullable();
            // Ticket keys it was solved on.
            $table->json('source_tickets')->nullable();
            // draft, approved or retired. Only approved entries are offered.
            $table->string('state')->default('draft');
            // you, or agent once agents draft entries from resolved tickets.
            $table->string('written_by')->default('you');
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table) {
            // The approved entry this ticket most looks like, if any, and how strongly.
            $table->foreignId('casebook_entry_id')->nullable()->after('slas')->constrained()->nullOnDelete();
            $table->float('casebook_score')->nullable()->after('casebook_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('casebook_entry_id');
            $table->dropColumn('casebook_score');
        });
        Schema::dropIfExists('casebook_entries');
    }
};
