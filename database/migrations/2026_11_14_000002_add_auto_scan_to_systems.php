<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Rescan the context by itself after a push that changes the code. */
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            $table->boolean('auto_scan')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            $table->dropColumn('auto_scan');
        });
    }
};
