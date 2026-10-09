<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Admin, editor or member, one role per person for the whole instance (docs/TEAM.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 10)->default('member')->after('password');
        });
        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_admin')->default(false));
        DB::table('users')->where('role', 'admin')->update(['is_admin' => true]);
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
