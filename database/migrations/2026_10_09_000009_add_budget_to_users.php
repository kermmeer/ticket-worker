<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A monthly ceiling on what someone's agent work may cost; none unless an admin sets one.
        Schema::table('users', fn (Blueprint $table) => $table->decimal('monthly_budget_usd', 10, 2)->nullable()->after('all_spaces'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('monthly_budget_usd'));
    }
};
