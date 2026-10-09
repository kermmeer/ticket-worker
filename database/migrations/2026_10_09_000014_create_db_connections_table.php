<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project's database, for read-only queries the tool runs for the agent (docs/TEAM.md, item 6).
        Schema::create('db_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('driver', 10)->default('mysql');
            $table->string('host', 255);
            $table->unsignedInteger('port')->nullable();
            $table->string('database', 128);
            $table->string('username', 128);
            // Encrypted with APP_KEY; never shown or given to the agent.
            $table->text('password')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['system_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_connections');
    }
};
