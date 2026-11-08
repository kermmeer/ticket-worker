<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checks a build can run before it is handed in: lint, a type check, unit tests. Each
     * system has a checks workspace with its dependencies, made once by a setup command.
     */
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            $table->string('checks_node', 10)->nullable();
            $table->text('checks_setup')->nullable();
            $table->text('checks_redirects')->nullable();
            $table->string('checks_state', 20)->nullable();
            $table->mediumText('checks_log')->nullable();
            $table->timestamp('checks_prepared_at')->nullable();
        });

        Schema::create('system_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->text('command');
            $table->boolean('enabled')->default(true);
            $table->string('note', 255)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_checks');
        Schema::table('systems', function (Blueprint $table) {
            $table->dropColumn(['checks_node', 'checks_setup', 'checks_redirects', 'checks_state', 'checks_log', 'checks_prepared_at']);
        });
    }
};
