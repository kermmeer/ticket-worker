<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            // The id survives a move to another space; the key does not.
            $table->string('jira_id')->unique();
            $table->string('key')->index();
            $table->string('summary', 1000);
            $table->string('status')->nullable();
            // new, indeterminate or done, as Jira groups its statuses.
            $table->string('status_category')->nullable();
            $table->string('priority')->nullable();
            $table->string('issue_type')->nullable();
            $table->string('reporter')->nullable();
            $table->string('assignee')->nullable();
            $table->timestamp('jira_created_at')->nullable();
            $table->timestamp('jira_updated_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            // Set when a full sync no longer finds it: solved, moved or relabelled.
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
