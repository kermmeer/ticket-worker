<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            // SLA names not to show. Hidden rather than shown, so every SLA shows by
            // default, a new one in Jira included, until you untick it.
            $table->json('hidden_slas')->nullable()->after('sleep_statuses');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('hidden_slas');
        });
    }
};
