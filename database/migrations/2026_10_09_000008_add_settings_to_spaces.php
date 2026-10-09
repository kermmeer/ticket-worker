<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A space's own settings, each one falling back to the defaults on Setup when unset.
        Schema::table('spaces', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('quick_scan');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', fn (Blueprint $table) => $table->dropColumn('settings'));
    }
};
