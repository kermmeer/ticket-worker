<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The commit an API's docs were written from, and the files its endpoints are defined in:
 * after a push, only an API whose files changed is brought up to date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_connections', function (Blueprint $table) {
            $table->string('docs_commit', 40)->nullable()->after('docs_source');
            $table->json('docs_files')->nullable()->after('docs_commit');
        });

        // Docs written from the code already say at which commit.
        foreach (DB::table('api_connections')->where('docs_source', 'like', 'written from the code of % at %')->get(['id', 'docs_source']) as $api) {
            if (preg_match('/ at ([0-9a-f]{7,40})$/', $api->docs_source, $match)) {
                DB::table('api_connections')->where('id', $api->id)->update(['docs_commit' => $match[1]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('api_connections', function (Blueprint $table) {
            $table->dropColumn(['docs_commit', 'docs_files']);
        });
    }
};
