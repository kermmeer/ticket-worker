<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More than one person on one instance (CONCEPT.md §17, decided 2026-10-09): who is who,
 * each person's own Claude sign-in, and who started which piece of agent work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->unique()->after('id');
            $table->boolean('is_admin')->default(false)->after('password');
            // Encrypted with APP_KEY; never shown again, only "set".
            $table->text('claude_api_key')->nullable()->after('is_admin');
            $table->text('claude_oauth_token')->nullable()->after('claude_api_key');
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::table('agent_sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('ticket_id')->constrained()->nullOnDelete();
        });
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('agent_session_id')->constrained()->nullOnDelete();
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('quick_scan_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('systems', function (Blueprint $table) {
            $table->foreignId('scan_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('systems', fn (Blueprint $table) => $table->dropConstrainedForeignId('scan_user_id'));
        Schema::table('tickets', fn (Blueprint $table) => $table->dropConstrainedForeignId('quick_scan_user_id'));
        Schema::table('agent_turns', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::table('agent_sessions', fn (Blueprint $table) => $table->dropConstrainedForeignId('user_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['username', 'is_admin', 'claude_api_key', 'claude_oauth_token', 'last_seen_at']));
    }
};
