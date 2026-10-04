<?php

namespace App\Http\Controllers;

use App\Agent\Instructions;
use App\Jobs\PrepareSystemFolder;
use App\Jobs\ScanSystem;
use App\Models\System;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Systems', [
            'systems' => System::query()->orderBy('name')->get()->map(fn (System $system) => [
                'id' => $system->id,
                'name' => $system->name,
                'branch' => $system->branch,
                'state' => $system->state,
                'error' => $system->error,
                'path' => $system->path(),
                'remote' => $system->pushRemote(),
                'head' => $system->state === System::READY ? $system->head() : null,
                'context' => $system->context,
                'context_written_at' => $system->context_written_at?->toIso8601String(),
                'commits_behind' => $system->commitsBehind(),
                'scan_state' => $system->scan_state,
                'scan_log' => $system->scan_log,
                'scan_error' => $system->scan_error,
                'scan_cost_usd' => $system->scan_cost_usd,
            ]),
            'agentReady' => Instructions::credentials() !== [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // It becomes a folder name: lower case, digits and dashes, nothing that climbs out.
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:systems,name'],
            'branch' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'not_regex:/\.\.|\/\/|\.lock$|\/$/'],
        ], [
            'name.regex' => 'Lower-case letters, digits and dashes only, starting with a letter or digit.',
            'branch.regex' => 'That is not a branch name git accepts.',
            'branch.not_regex' => 'That is not a branch name git accepts.',
        ]);

        PrepareSystemFolder::dispatch(System::create($data));

        return to_route('systems.index');
    }

    /** Write or refresh the system's context with an agent (CONCEPT.md §5). */
    public function scan(System $system): RedirectResponse
    {
        abort_unless(Instructions::credentials() !== [], 409, 'No Anthropic sign-in yet: see Setup.');
        if (in_array($system->scan_state, ['queued', 'running'], true)) {
            return back();
        }

        $system->update(['scan_state' => 'queued', 'scan_error' => null, 'scan_log' => null]);
        ScanSystem::dispatch($system);

        return back();
    }

    public function stopScan(System $system): RedirectResponse
    {
        $system->scan_state === 'queued'
            ? $system->update(['scan_state' => 'idle', 'scan_error' => 'Stopped.'])
            : $system->update(['scan_stop_requested' => true]);

        return back();
    }

    /** Your own edit of the context: kept as a version like the agent's. */
    public function updateContext(Request $request, System $system): RedirectResponse
    {
        $data = $request->validate(['context' => ['required', 'string', 'max:60000']]);
        $system->writeContext($data['context'], $system->context_commit ?? $system->headCommit(), 'you');

        return back()->with('success', "Saved the context of {$system->name}.");
    }
}
