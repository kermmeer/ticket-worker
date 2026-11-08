<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A database server reached through an SSH tunnel: a jump host that can reach it, a key
     * pair made here (the public half goes on the jump host), and the jump host's own key,
     * pinned on first contact.
     */
    public function up(): void
    {
        Schema::table('db_servers', function (Blueprint $table) {
            $table->string('ssh_host', 255)->nullable()->after('notes');
            $table->unsignedSmallInteger('ssh_port')->nullable()->after('ssh_host');
            $table->string('ssh_user', 100)->nullable()->after('ssh_port');
            $table->text('ssh_key')->nullable()->after('ssh_user');
            $table->text('ssh_public_key')->nullable()->after('ssh_key');
            $table->text('ssh_known_hosts')->nullable()->after('ssh_public_key');
        });
    }

    public function down(): void
    {
        Schema::table('db_servers', function (Blueprint $table) {
            $table->dropColumn(['ssh_host', 'ssh_port', 'ssh_user', 'ssh_key', 'ssh_public_key', 'ssh_known_hosts']);
        });
    }
};
