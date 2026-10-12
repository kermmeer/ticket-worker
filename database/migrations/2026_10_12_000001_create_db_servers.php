<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One database server with one read-only login, for many projects' databases: the login is
 * kept once, and each project's database only names which database on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('driver', 10)->default('mysql');
            $table->string('host', 255);
            $table->unsignedInteger('port')->nullable();
            $table->string('username', 128);
            // Encrypted with APP_KEY; never shown or given to the agent.
            $table->text('password')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('db_connections', function (Blueprint $table) {
            $table->foreignId('db_server_id')->nullable()->after('system_id')->constrained('db_servers')->restrictOnDelete();
            $table->string('host', 255)->nullable()->change();
            $table->string('username', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('db_connections', fn (Blueprint $table) => $table->dropConstrainedForeignId('db_server_id'));
        Schema::dropIfExists('db_servers');
    }
};
