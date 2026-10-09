<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The spaces a case is for; empty, as it starts, means every space.
        Schema::table('casebook_entries', fn (Blueprint $table) => $table->json('space_ids')->nullable()->after('system_id'));
    }

    public function down(): void
    {
        Schema::table('casebook_entries', fn (Blueprint $table) => $table->dropColumn('space_ids'));
    }
};
