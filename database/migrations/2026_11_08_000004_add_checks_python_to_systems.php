<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A Python version for a system's checks, beside Node's: downloaded once like it. */
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            $table->string('checks_python', 10)->nullable()->after('checks_node');
        });
    }

    public function down(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            $table->dropColumn('checks_python');
        });
    }
};
