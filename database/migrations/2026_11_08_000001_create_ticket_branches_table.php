<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The code built for a ticket: one branch per system, in that system's repository here. */
    public function up(): void
    {
        Schema::create('ticket_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('system_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('base', 200);
            $table->string('head', 40)->nullable();
            $table->unsignedInteger('commits')->default(0);
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('removed')->default(0);
            $table->unsignedInteger('files')->default(0);
            $table->timestamps();
            $table->unique(['ticket_id', 'system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_branches');
    }
};
