<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tied to its case by hand: the matcher leaves it alone. */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->boolean('casebook_manual')->default(false)->after('casebook_score');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('casebook_manual');
        });
    }
};
