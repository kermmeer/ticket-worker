<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->string('project_key')->unique();
            $table->string('project_id');
            $table->string('name');
            // Short, for the overview, with a colour of its own.
            $table->string('label', 24);
            $table->string('colour', 16);
            // projectTypeKey as Jira reports it, and what this tool treats it as:
            // service or plain, Jira's answer unless the setup overrides it.
            $table->string('jira_type');
            $table->string('type');
            // JQL without the project and without an order: which tickets count, and
            // when one counts as done.
            $table->text('rules')->nullable();
            $table->text('done_rule');
            // draft, active or paused. Only active spaces sync.
            $table->string('state')->default('draft');
            $table->timestamp('sync_attempted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('space_system', function (Blueprint $table) {
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('system_id')->constrained()->cascadeOnDelete();
            $table->primary(['space_id', 'system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_system');
        Schema::dropIfExists('spaces');
    }
};
