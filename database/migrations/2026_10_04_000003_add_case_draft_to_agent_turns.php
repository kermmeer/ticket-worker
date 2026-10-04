<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            // A casebook case the agent drafted; it becomes one only when you submit the form.
            $table->json('case_draft')->nullable()->after('proposal');
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropColumn('case_draft');
        });
    }
};
