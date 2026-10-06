<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            // A patch turn's result: the git format-patch text, and what it is about.
            $table->longText('patch')->nullable()->after('case_draft');
            $table->json('patch_meta')->nullable()->after('patch');
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropColumn(['patch', 'patch_meta']);
        });
    }
};
