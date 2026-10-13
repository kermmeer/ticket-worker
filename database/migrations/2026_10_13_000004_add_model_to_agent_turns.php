<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which model answered: a chat question and a deep analysis differ ten times in price. */
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->string('model', 100)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
