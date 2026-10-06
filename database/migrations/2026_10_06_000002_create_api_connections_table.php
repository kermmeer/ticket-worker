<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An API a system's tickets can be checked against, called by the tool for the agent.
        Schema::create('api_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('base_url', 500);
            $table->string('auth', 20)->default('none');
            $table->string('username', 200)->nullable();
            $table->string('field', 100)->nullable();
            // Encrypted with APP_KEY; never sent to a page or to the agent.
            $table->text('secret')->nullable();
            $table->boolean('allow_post')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['system_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_connections');
    }
};
