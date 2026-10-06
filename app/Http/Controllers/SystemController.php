<?php

namespace App\Http\Controllers;

use App\Agent\Instructions;
use App\Jobs\PrepareSystemFolder;
use App\Jobs\ScanSystem;
use App\Models\ApiConnection;
use App\Models\System;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Systems', [
            'systems' => System::query()->with('apis')->orderBy('name')->get()->map(fn (System $system) => [
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
                'scan_tokens_in' => $system->scan_input_tokens,
                'scan_tokens_out' => $system->scan_output_tokens,
                // Its APIs; a secret is only ever "set" or not.
                'apis' => $system->apis->map(fn (ApiConnection $api) => [
                    'id' => $api->id, 'name' => $api->name, 'base_url' => $api->base_url, 'auth' => $api->auth,
                    'username' => $api->username, 'field' => $api->field, 'has_secret' => filled($api->secret),
                    'allow_post' => $api->allow_post, 'notes' => $api->notes,
                ]),
            ]),
            'authKinds' => ApiConnection::AUTH,
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

    public function storeApi(Request $request, System $system): RedirectResponse
    {
        $system->apis()->create($this->apiData($request, $system));

        return back()->with('success', 'Added. The next analysis of its tickets can use it.');
    }

    public function updateApi(Request $request, System $system, ApiConnection $api): RedirectResponse
    {
        abort_unless($api->system_id === $system->id, 404);
        $data = $this->apiData($request, $system, $api);
        // An empty secret field keeps the one there is: it is never sent to the page.
        if (! filled($data['secret'] ?? null) && ! $request->boolean('clear_secret')) {
            unset($data['secret']);
        }
        $api->update($data);

        return back()->with('success', "Saved {$api->name}.");
    }

    public function destroyApi(System $system, ApiConnection $api): RedirectResponse
    {
        abort_unless($api->system_id === $system->id, 404);
        $api->delete();

        return back()->with('success', 'Removed.');
    }

    /** One GET, to see the address and sign-in work. Shows the status, not the data. */
    public function tryApi(Request $request, System $system, ApiConnection $api): RedirectResponse
    {
        abort_unless($api->system_id === $system->id, 404);
        $path = (string) $request->validate(['path' => ['nullable', 'string', 'max:500']])['path'];

        try {
            $answer = $api->call('GET', $path);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $verdict = match (true) {
            $answer['status'] === 0 => 'no answer. '.$answer['body'],
            $answer['status'] === 401, $answer['status'] === 403 => "HTTP {$answer['status']}: it answers, but refuses the sign-in.",
            $answer['status'] >= 200 && $answer['status'] < 400 => "HTTP {$answer['status']}: it answers and lets you in.",
            default => "HTTP {$answer['status']}: it answers; check the path.",
        };

        return back()->with($answer['status'] >= 200 && $answer['status'] < 400 ? 'success' : 'error', "{$api->name}: {$verdict}");
    }

    private function apiData(Request $request, System $system, ?ApiConnection $api = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('api_connections')->where('system_id', $system->id)->ignore($api?->id)],
            'base_url' => ['required', 'string', 'max:500', 'url:https,http'],
            'auth' => ['required', Rule::in(array_keys(ApiConnection::AUTH))],
            'username' => ['nullable', 'required_if:auth,basic', 'string', 'max:200'],
            'field' => ['nullable', 'required_if:auth,header,query', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'secret' => ['nullable', 'string', 'max:4000'],
            'allow_post' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ], [
            'name.regex' => 'Lower-case letters, digits and dashes, like boss-api.',
            'field.required_if' => 'Which header or query parameter carries the secret?',
        ]);

        return $data;
    }

    /** Your own edit of the context: kept as a version like the agent's. */
    public function updateContext(Request $request, System $system): RedirectResponse
    {
        $data = $request->validate(['context' => ['required', 'string', 'max:60000']]);
        $system->writeContext($data['context'], $system->context_commit ?? $system->headCommit(), 'you');

        return back()->with('success', "Saved the context of {$system->name}.");
    }
}
