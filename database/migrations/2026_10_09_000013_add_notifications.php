<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A space's Slack or Teams incoming webhook (a secret address, so encrypted), and per
        // ticket the SLA deadlines it already warned about, so each warns once.
        Schema::table('spaces', fn (Blueprint $table) => $table->text('notify_webhook')->nullable()->after('settings'));
        Schema::table('tickets', fn (Blueprint $table) => $table->json('sla_warned')->nullable());
    }

    public function down(): void
    {
        Schema::table('spaces', fn (Blueprint $table) => $table->dropColumn('notify_webhook'));
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('sla_warned'));
    }
};
