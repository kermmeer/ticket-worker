<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One sign-in for all of a system's APIs; per API what the agent may do (read, query, or
     * change after approval); and the changes it proposes, each waiting for a person to run it.
     */
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            $table->string('api_auth', 20)->nullable();
            $table->string('api_username', 200)->nullable();
            $table->string('api_field', 100)->nullable();
            $table->text('api_secret')->nullable();
        });

        Schema::table('api_connections', function (Blueprint $table) {
            $table->string('access', 10)->default('read')->after('allow_post');
        });
        DB::table('api_connections')->where('allow_post', true)->update(['access' => 'query']);

        Schema::create('api_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_turn_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_connection_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('path', 2000);
            $table->json('query')->nullable();
            $table->json('body')->nullable();
            $table->text('reason')->nullable();
            $table->string('state', 10)->default('waiting');
            $table->unsignedSmallInteger('status')->nullable();
            $table->mediumText('response')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_calls');
        Schema::table('api_connections', function (Blueprint $table) {
            $table->dropColumn('access');
        });
        Schema::table('systems', function (Blueprint $table) {
            $table->dropColumn(['api_auth', 'api_username', 'api_field', 'api_secret']);
        });
    }
};
