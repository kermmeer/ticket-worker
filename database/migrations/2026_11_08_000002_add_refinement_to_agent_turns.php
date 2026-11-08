<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A development ticket refined: questions, acceptance criteria, where, size. */
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->json('refinement')->nullable()->after('case_draft');
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropColumn('refinement');
        });
    }
};
