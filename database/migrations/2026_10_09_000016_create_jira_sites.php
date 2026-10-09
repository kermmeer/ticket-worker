<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More than one Jira site (docs/TEAM.md, item 8). The one in .env stays the default (site 0);
 * others are added here. Tickets are told apart per site, since two sites can both have a
 * SUP-1 and the same issue id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jira_sites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('base_url', 255);
            $table->string('email', 255);
            // Encrypted with APP_KEY; never shown again.
            $table->text('token');
            $table->string('open_url', 255)->nullable();
            $table->string('open_label', 40)->nullable();
            $table->timestamps();
        });

        Schema::table('spaces', function (Blueprint $table) {
            $table->foreignId('jira_site_id')->nullable()->after('id')->constrained('jira_sites')->restrictOnDelete();
            $table->dropUnique(['project_key']);
            $table->unique(['jira_site_id', 'project_key']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('site')->default(0)->after('space_id');
            $table->dropUnique(['jira_id']);
            $table->unique(['site', 'jira_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['site', 'jira_id']);
            $table->unique('jira_id');
            $table->dropColumn('site');
        });
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropUnique(['jira_site_id', 'project_key']);
            $table->unique('project_key');
            $table->dropConstrainedForeignId('jira_site_id');
        });
        Schema::dropIfExists('jira_sites');
    }
};
