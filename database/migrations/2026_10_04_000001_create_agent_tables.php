<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One Claude conversation per ticket (CONCEPT.md §8, §10).
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            // The id Claude Code files the conversation under; later turns resume it.
            $table->uuid('claude_session_id')->unique();
            // open or closed.
            $table->string('state')->default('open');
            // auto, or a language name: what the reply draft is written in.
            $table->string('reply_language')->default('auto');
            $table->string('model');
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->timestamps();
        });

        // One round: your message, the agent working, its answer.
        Schema::create('agent_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_session_id')->constrained()->cascadeOnDelete();
            // analysis (the first, ends in a proposal), message, or proposal (restate it).
            $table->string('kind');
            $table->text('prompt');
            // queued, running, done, failed or stopped.
            $table->string('state')->default('queued');
            $table->boolean('stop_requested')->default(false);
            $table->json('proposal')->nullable();
            $table->longText('answer')->nullable();
            $table->text('error')->nullable();
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Everything the agent did in a turn, in order: the trail the page shows.
        Schema::create('agent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_turn_id')->constrained()->cascadeOnDelete();
            // text, tool, result, error.
            $table->string('type');
            $table->text('summary');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_events');
        Schema::dropIfExists('agent_turns');
        Schema::dropIfExists('agent_sessions');
    }
};
