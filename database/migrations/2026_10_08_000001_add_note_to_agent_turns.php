<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            // What you added yourself when starting an analysis: shown in the log as yours.
            $table->text('note')->nullable()->after('prompt');
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
