<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            // code: a folder you push into. api: only APIs to call, no code to read.
            $table->string('kind', 10)->default('code')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('systems', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
