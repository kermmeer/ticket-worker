<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** An API's endpoints, read from its OpenAPI spec or written from its code: for you and the agent. */
    public function up(): void
    {
        Schema::table('api_connections', function (Blueprint $table) {
            $table->json('docs')->nullable();
            $table->string('docs_source', 500)->nullable();
            $table->string('docs_state', 20)->nullable();
            $table->text('docs_error')->nullable();
            $table->decimal('docs_cost_usd', 8, 4)->nullable();
            $table->timestamp('docs_written_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_connections', function (Blueprint $table) {
            $table->dropColumn(['docs', 'docs_source', 'docs_state', 'docs_error', 'docs_cost_usd', 'docs_written_at']);
        });
    }
};
