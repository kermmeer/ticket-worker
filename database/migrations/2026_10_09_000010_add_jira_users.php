<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who a ticket is assigned to, by Jira's account id, and who each person is in Jira:
        // together, "assigned to me" (docs/TEAM.md, item 2).
        Schema::table('tickets', fn (Blueprint $table) => $table->string('assignee_account_id', 128)->nullable()->after('assignee')->index());
        Schema::table('users', function (Blueprint $table) {
            $table->string('jira_account_id', 128)->nullable()->after('email');
            $table->string('jira_name', 200)->nullable()->after('jira_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('assignee_account_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['jira_account_id', 'jira_name']));
    }
};
