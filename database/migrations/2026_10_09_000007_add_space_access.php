<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every space by default; otherwise only those in space_access.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('all_spaces')->default(true)->after('role');
        });
        Schema::create('space_access', function (Blueprint $table) {
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['space_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_access');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('all_spaces'));
    }
};
