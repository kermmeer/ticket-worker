<?php

namespace App\Agent;

use App\Jobs\WriteApiDocs;
use App\Models\ApiConnection;
use App\Models\AuditEvent;
use App\Models\System;

/**
 * API docs written from the code follow the code: once a push has moved its system past the
 * commit they were written from, and the pushing has stopped (ten minutes), each API is
 * brought up to date. Free when none of its files changed; else an agent reads the diff, on
 * the shared Claude sign-in. Not more than once an hour per API. A spec the API serves is
 * the API as it runs, not the code: those are left alone.
 */
class UpdateApiDocs
{
    public const AT_MOST_EVERY_MINUTES = 60;

    public function __invoke(): void
    {
        if (Instructions::instanceCredentials() === []) {
            return;
        }

        $apis = ApiConnection::query()->with('system')->where('docs_state', 'ready')->whereNotNull('docs_commit')
            ->where(fn ($query) => $query->whereNull('docs_written_at')->orWhere('docs_written_at', '<', now()->subMinutes(self::AT_MOST_EVERY_MINUTES)))
            ->get();
        foreach ($apis as $api) {
            $system = $api->system;
            if ($system === null || $system->kind !== System::CODE || $system->state !== System::READY || ! AutoScanSystems::quiet($system)) {
                continue;
            }
            $head = $system->headCommit();
            if ($head === null || $head === '' || str_starts_with($head, $api->docs_commit)) {
                continue;
            }
            $api->update(['docs_state' => 'queued', 'docs_error' => null]);
            WriteApiDocs::dispatch($api, null, afterPush: true);
            AuditEvent::record('api.docs', "{$system->name}/{$api->name}", 'brought up to date after a push');
        }
    }
}
