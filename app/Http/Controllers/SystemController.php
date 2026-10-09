<?php

namespace App\Http\Controllers;

use App\Agent\Instructions;
use App\Jobs\PrepareSystemFolder;
use App\Jobs\PullSystem;
use App\Jobs\ScanSystem;
use App\Models\ApiConnection;
use App\Models\AuditEvent;
use App\Models\DbConnection;
use App\Models\System;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Systems', [
            'systems' => System::query()->with(['apis', 'databases'])->orderBy('name')->get()->map(fn (System $system) => [
                'id' => $system->id,
                'name' => $system->name,
                'kind' => $system->kind,
                'branch' => $system->branch,
                'state' => $system->state,
                'error' => $system->error,
                'path' => $system->hasCode() ? $system->path() : null,
                'remote' => $system->hasCode() ? $system->pushRemote() : null,
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
                'pull' => $system->hasCode() ? [
                    'url' => $system->pull_url, 'auth' => $system->pull_auth, 'username' => $system->pull_username,
                    'has_secret' => filled($system->pull_secret), 'public_key' => $system->pull_public_key,
                    'state' => $system->pull_state, 'error' => $system->pull_error, 'at' => $system->pulled_at?->toIso8601String(),
                ] : null,
                // Its APIs; a secret is only ever "set" or not.
                'databases' => $system->databases->map(fn (DbConnection $db) => [
                    'id' => $db->id, 'name' => $db->name, 'driver' => $db->driver, 'host' => $db->host, 'port' => $db->port,
                    'database' => $db->database, 'username' => $db->username, 'has_password' => filled($db->password),
                    'notes' => $db->notes, 'available' => $db->available(),
                ]),
                'apis' => $system->apis->map(fn (ApiConnection $api) => [
                    'id' => $api->id, 'name' => $api->name, 'base_url' => $api->base_url, 'auth' => $api->auth,
                    'username' => $api->username, 'field' => $api->field, 'has_secret' => filled($api->secret),
                    'allow_post' => $api->allow_post, 'notes' => $api->notes,
                ]),
            ]),
            'authKinds' => ApiConnection::AUTH,
            'dbDrivers' => DbConnection::DRIVERS,
            'sshAvailable' => PullSystem::sshAvailable(),
            'agentReady' => Instructions::credentials() !== [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // An API-only project: the project and its API in one go, ready at once.
        if ($request->input('kind') === System::API) {
            $name = $request->validate([
                'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:systems,name'],
            ], ['name.regex' => 'Lower-case letters, digits and dashes only, starting with a letter or digit.'])['name'];
            $system = new System(['name' => $name, 'kind' => System::API, 'branch' => '-', 'state' => System::READY]);
            $api = $this->apiData($request->merge(['name' => 'api']), $system);
            $system->save();
            $system->apis()->create($api);
            AuditEvent::record('project.create', $name, 'API-only project, API at '.$api['base_url']);

            return to_route('systems.index')->with('success', "Added {$name}. Link it to a space (Spaces → edit) and its tickets can use it.");
        }

        $data = $request->validate([
            // It becomes a folder name: lower case, digits and dashes, nothing that climbs out.
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:systems,name'],
            'branch' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'not_regex:/\.\.|\/\/|\.lock$|\/$/'],
        ], [
            'name.regex' => 'Lower-case letters, digits and dashes only, starting with a letter or digit.',
            'branch.regex' => 'That is not a branch name git accepts.',
            'branch.not_regex' => 'That is not a branch name git accepts.',
        ]);

        PrepareSystemFolder::dispatch($system = System::create($data));
        AuditEvent::record('project.create', $system->name, "code project, branch {$system->branch}");

        return to_route('systems.index');
    }

    /** Write or refresh the system's context with an agent (CONCEPT.md §5). */
    public function scan(System $system): RedirectResponse
    {
        abort_unless(Instructions::credentials() !== [], 409, 'No Anthropic sign-in yet: see Setup.');
        abort_unless($system->hasCode(), 409, 'An API-only project has no code to scan: write its notes by hand.');
        if (in_array($system->scan_state, ['queued', 'running'], true)) {
            return back();
        }

        $system->update(['scan_state' => 'queued', 'scan_error' => null, 'scan_log' => null, 'scan_user_id' => auth()->id()]);
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
        $api = $system->apis()->create($this->apiData($request, $system));
        AuditEvent::record('api.create', "{$system->name}/{$api->name}", "at {$api->base_url}, {$api->auth} sign-in".(filled($api->secret) ? ', secret set' : ''));

        return back()->with('success', 'Added. The next analysis of its tickets can use it.');
    }

    public function updateApi(Request $request, System $system, ApiConnection $api): RedirectResponse
    {
        abort_unless($api->system_id === $system->id, 404);
        $data = $this->apiData($request, $system, $api);
        // A secret only ever goes where it was given for. Moving the API (another server,
        // another way of signing in) without giving the secret again would let anyone who
        // can edit it send the stored one to a server of their own: so that drops it.
        // Another server, that is: a new path on the same one is no risk.
        $server = fn (string $url) => mb_strtolower(implode('|', [parse_url($url, PHP_URL_SCHEME), parse_url($url, PHP_URL_HOST), parse_url($url, PHP_URL_PORT)]));
        $moved = $server($data['base_url']) !== $server($api->base_url)
            || $data['auth'] !== $api->auth
            || ($data['username'] ?? null) !== $api->username
            || ($data['field'] ?? null) !== $api->field;
        if (! filled($data['secret'] ?? null)) {
            if ($moved || $request->boolean('clear_secret')) {
                $data['secret'] = null;
            } else {
                unset($data['secret']);
            }
        }
        $before = $api->getAttributes();
        $api->update($data);
        AuditEvent::record('api.update', "{$system->name}/{$api->name}", 'changed '.AuditEvent::changes($before, $api->getAttributes(), ['secret']));

        return back()->with('success', $moved && ! filled($data['secret'] ?? null) && $api->auth !== 'none'
            ? "Saved {$api->name}. It moved, so its secret was dropped: enter it again."
            : "Saved {$api->name}.");
    }

    public function destroyApi(System $system, ApiConnection $api): RedirectResponse
    {
        abort_unless($api->system_id === $system->id, 404);
        $api->delete();
        AuditEvent::record('api.remove', "{$system->name}/{$api->name}", 'removed, with its secret');

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

    /** Where to pull from, and how to sign in: editors and up (the route says so). */
    public function pull(Request $request, System $system): RedirectResponse
    {
        abort_unless($system->hasCode(), 409, 'An API-only project has no code to pull.');
        $data = $request->validate([
            'url' => ['nullable', 'string', 'max:500', 'regex:~^(https://[^\s@]+|ssh://[^\s]+|[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[^\s]+)$~'],
            'auth' => ['required', 'in:none,token,ssh'],
            'username' => ['nullable', 'string', 'max:200'],
            'secret' => ['nullable', 'string', 'max:500'],
        ], ['url.regex' => 'An https:// address without a password in it, or an SSH one (git@host:group/project.git).']);

        $host = fn (?string $url) => mb_strtolower((string) (parse_url((string) $url, PHP_URL_HOST) ?: Str::between((string) $url, '@', ':')));
        $changes = ['pull_url' => $data['url'] ?: null, 'pull_auth' => $data['auth'], 'pull_username' => $data['username'] ?: null];
        if ($data['auth'] === 'token' && filled($data['secret'] ?? null)) {
            $changes += ['pull_secret' => trim($data['secret']), 'pull_public_key' => null];
        } elseif ($data['auth'] === 'none' || $data['auth'] !== $system->pull_auth || $host($data['url']) !== $host($system->pull_url)) {
            // Another host or another way in: the old secret does not follow (as with APIs).
            $changes += ['pull_secret' => null, 'pull_public_key' => null];
        }
        $system->update($changes);
        AuditEvent::record('project.pull', $system->name, $changes['pull_url'] ? "pull from {$changes['pull_url']} ({$data['auth']})".(isset($changes['pull_secret']) && $changes['pull_secret'] ? ', secret changed' : '') : 'no pulling');

        return back()->with('success', 'Saved.'.($data['auth'] === 'ssh' && ! filled($system->pull_public_key) ? ' Now make a deploy key.' : ''));
    }

    /** A fresh SSH key pair: the private half stays here, the public half goes on the Git host. */
    public function pullKey(System $system): RedirectResponse
    {
        abort_unless(PullSystem::sshAvailable(), 409, 'This server has no ssh: use a token instead.');
        $dir = sys_get_temp_dir().'/key-'.Str::random(12);
        mkdir($dir, 0700);
        $process = new \Symfony\Component\Process\Process(['ssh-keygen', '-t', 'ed25519', '-N', '', '-C', "ticket-worker {$system->name}", '-f', $dir.'/key', '-q']);
        $process->run();
        if (! $process->isSuccessful()) {
            return back()->with('error', 'ssh-keygen failed: '.$process->getErrorOutput());
        }
        $system->update(['pull_auth' => 'ssh', 'pull_secret' => file_get_contents($dir.'/key'), 'pull_public_key' => trim(file_get_contents($dir.'/key.pub'))]);
        @unlink($dir.'/key');
        @unlink($dir.'/key.pub');
        @rmdir($dir);
        AuditEvent::record('project.pull-key', $system->name, 'new deploy key (secret changed)');

        return back()->with('success', 'Made. Add the public key as a read-only deploy key on the Git host, then Pull now.');
    }

    public function pullNow(System $system): RedirectResponse
    {
        abort_unless(filled($system->pull_url), 409, 'Set where to pull from first.');
        $system->update(['pull_state' => 'queued', 'pull_error' => null]);
        PullSystem::dispatch($system);

        return back();
    }

    public function storeDb(Request $request, System $system): RedirectResponse
    {
        $db = $system->databases()->create($this->dbData($request, $system));
        AuditEvent::record('db.create', "{$system->name}/{$db->name}", "{$db->driver} {$db->database} on {$db->host} as {$db->username}".(filled($db->password) ? ', password set' : ''));

        return back()->with('success', 'Added. Try it, then the next analysis of its tickets can query it.');
    }

    public function updateDb(Request $request, System $system, DbConnection $db): RedirectResponse
    {
        abort_unless($db->system_id === $system->id, 404);
        $data = $this->dbData($request, $system, $db);
        // As with an API: a password never follows its database to another server or account.
        $moved = $data['host'] !== $db->host || ($data['port'] ?? null) != $db->port || $data['username'] !== $db->username || $data['driver'] !== $db->driver;
        if (! filled($data['password'] ?? null)) {
            if ($moved) {
                $data['password'] = null;
            } else {
                unset($data['password']);
            }
        }
        $before = $db->getAttributes();
        $db->update($data);
        AuditEvent::record('db.update', "{$system->name}/{$db->name}", 'changed '.AuditEvent::changes($before, $db->getAttributes(), ['password']));

        return back()->with('success', $moved && ! filled($data['password'] ?? null) ? "Saved {$db->name}. It moved, so its password was dropped: enter it again." : "Saved {$db->name}.");
    }

    public function destroyDb(System $system, DbConnection $db): RedirectResponse
    {
        abort_unless($db->system_id === $system->id, 404);
        $db->delete();
        AuditEvent::record('db.remove', "{$system->name}/{$db->name}", 'removed, with its password');

        return back()->with('success', 'Removed.');
    }

    /** SELECT 1: does the address, the account and the password work? */
    public function tryDb(System $system, DbConnection $db): RedirectResponse
    {
        abort_unless($db->system_id === $system->id, 404);
        try {
            $db->runQuery('SELECT 1 AS ok');
        } catch (\Throwable $e) {
            return back()->with('error', "{$db->name}: ".$db->redact($e->getMessage()));
        }

        return back()->with('success', "{$db->name}: it answers and lets you in.");
    }

    private function dbData(Request $request, System $system, ?DbConnection $db = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('db_connections')->where('system_id', $system->id)->ignore($db?->id)],
            'driver' => ['required', Rule::in(array_keys(DbConnection::DRIVERS))],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:128'],
            'username' => ['required', 'string', 'max:128'],
            'password' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ], ['name.regex' => 'Lower-case letters, digits and dashes, like crm-db.']);
    }

    private function apiData(Request $request, System $system, ?ApiConnection $api = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('api_connections')->where('system_id', $system->id ?? 0)->ignore($api?->id)],
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
        $system->writeContext($data['context'], $system->context_commit ?? $system->headCommit(), $request->user()->firstName());
        AuditEvent::record('project.context', $system->name, 'context edited by hand');

        return back()->with('success', "Saved the context of {$system->name}.");
    }
}
