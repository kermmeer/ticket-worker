<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a project can be fetched from when its host is reachable (docs/TEAM.md, item 7).
        Schema::table('systems', function (Blueprint $table) {
            $table->string('pull_url', 500)->nullable();
            $table->string('pull_auth', 10)->default('none');
            $table->string('pull_username', 200)->nullable();
            // A token or an SSH private key, encrypted with APP_KEY; never shown again.
            $table->text('pull_secret')->nullable();
            $table->text('pull_public_key')->nullable();
            $table->timestamp('pulled_at')->nullable();
            $table->string('pull_state', 10)->nullable();
            $table->text('pull_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('systems', fn (Blueprint $table) => $table->dropColumn(['pull_url', 'pull_auth', 'pull_username', 'pull_secret', 'pull_public_key', 'pulled_at', 'pull_state', 'pull_error']));
    }
};
