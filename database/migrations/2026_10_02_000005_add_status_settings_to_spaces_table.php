<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            // Status name => colour, for the statuses you gave a colour yourself.
            $table->json('status_colours')->nullable()->after('done_rule');
            // Statuses that put a ticket to sleep: waiting on the requester. Null means
            // automatic: whatever says "waiting for customer", until you choose.
            $table->json('sleep_statuses')->nullable()->after('status_colours');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn(['status_colours', 'sleep_statuses']);
        });
    }
};
