<?php

namespace App\Http\Controllers;

use App\Jobs\WriteApiDocs;
use App\Models\ApiConnection;
use App\Models\AuditEvent;
use App\Models\System;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The APIs' docs, for everyone using the tool; writing them is for editors (Systems). */
class ApiDocsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ApiDocs/Index', [
            'apis' => ApiConnection::query()->with('system:id,name')->orderBy('system_id')->orderBy('name')->get()->map(fn (ApiConnection $api) => [
                'id' => $api->id, 'system' => $api->system?->name, 'name' => $api->name, 'base_url' => $api->base_url,
                'endpoints' => is_array($api->docs) ? count($api->docs) : 0, 'source' => $api->docs_source,
                'state' => $api->docs_state, 'written_at' => $api->docs_written_at?->toIso8601String(), 'access' => $api->access,
            ]),
        ]);
    }

    public function show(ApiConnection $api): Response
    {
        $api->load('system:id,name');

        return Inertia::render('ApiDocs/Show', [
            'api' => [
                'id' => $api->id, 'system_id' => $api->system_id, 'system' => $api->system?->name, 'name' => $api->name, 'base_url' => $api->base_url,
                'access' => $api->access, 'notes' => $api->notes, 'source' => $api->docs_source, 'state' => $api->docs_state, 'error' => $api->docs_error,
                'written_at' => $api->docs_written_at?->toIso8601String(), 'cost_usd' => $api->docs_cost_usd, 'follows' => $api->docs_commit !== null, 'files' => count($api->docs_files ?? []),
            ],
            'endpoints' => $api->docs ?? [],
        ]);
    }

    /** From the OpenAPI spec it serves, else written from the code (and any spec in it) by an agent. */
    public function write(System $system, ApiConnection $api): RedirectResponse
    {
        abort_unless($api->system_id === $system->id, 404);
        if (in_array($api->docs_state, ['queued', 'writing'], true)) {
            return back();
        }
        $api->update(['docs_state' => 'queued', 'docs_error' => null]);
        WriteApiDocs::dispatch($api, auth()->id());
        AuditEvent::record('api.docs', "{$system->name}/{$api->name}", 'docs being written');

        return back()->with('success', "Writing the docs of {$api->name}: from the spec it serves if it does (seconds), else from the code and any spec in it (a few minutes).");
    }
}
