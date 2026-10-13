<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Room for a pasted query result: a text column stops at 64 kB. */
    public function up(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->mediumText('prompt')->change();
            $table->mediumText('note')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->text('prompt')->change();
            $table->text('note')->nullable()->change();
        });
    }
};
