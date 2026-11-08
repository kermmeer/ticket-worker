<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Own work: something you start yourself, before there is a Jira ticket for it. It is a
     * ticket here, marked local, until it is made one in Jira and linked.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->boolean('local')->default(false)->after('site');
            $table->text('local_description')->nullable()->after('local');
            $table->foreignId('local_user_id')->nullable()->after('local_description')->constrained('users')->nullOnDelete();
        });
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->json('ticket_draft')->nullable()->after('refinement');
        });
    }

    public function down(): void
    {
        Schema::table('agent_turns', function (Blueprint $table) {
            $table->dropColumn('ticket_draft');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('local_user_id');
            $table->dropColumn(['local', 'local_description']);
        });
    }
};
