<?php

namespace App\Jobs;

use App\Agent\ApiDocs;
use App\Agent\ClaudeRun;
use App\Agent\Instructions;
use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * An API's docs. A spec the API serves is the whole of it (no agent, no cost). Otherwise an
 * agent reads the code where its routes are, told which endpoints a spec in the code already
 * describes, which win where both have one. On the agents queue, where the systems are and
 * Claude runs.
 *
 * After a push ($afterPush), only what changed: when none of the files its endpoints come
 * from changed, the docs just move to the new commit; else the agent gets the diff and
 * reports what is new, different or gone.
 */
class WriteApiDocs implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** Larger diffs are not handed over; the agent reads the changed files instead. */
    public const MAX_DIFF_BYTES = 400_000;

    public function __construct(public ApiConnection $api, public ?int $userId = null, public bool $afterPush = false)
    {
        $this->onQueue('agents');
    }

    public function handle(ApiDocs $docs, ClaudeRun $runner): void
    {
        $api = $this->api->fresh(['system']);
        if ($api === null) {
            return;
        }
        $api->update(['docs_state' => 'writing', 'docs_error' => null]);

        try {
            if (! $this->afterPush && ($found = $docs->served($api)) !== null && $found['endpoints'] !== []) {
                $api->update(['docs' => $found['endpoints'], 'docs_source' => 'OpenAPI spec '.$found['source'], 'docs_commit' => null, 'docs_files' => null,
                    'docs_state' => 'ready', 'docs_cost_usd' => 0, 'docs_written_at' => now()]);

                return;
            }
            $system = $api->system;
            $head = $system?->hasCode() ? self::git($system->path(), ['rev-parse', 'HEAD']) : null;
            if ($head === null) {
                throw new \RuntimeException('No spec to read, and no code here to read it from: push the system first, or describe the API in its notes.');
            }
            $spec = $docs->inCode($api);
            $known = $spec['endpoints'] ?? [];

            $since = $this->afterPush && is_array($api->docs) && $api->docs !== [] && $api->docs_commit
                ? self::git($system->path(), ['rev-parse', '--verify', '--quiet', $api->docs_commit.'^{commit}'])
                : null;
            if ($since !== null) {
                $this->update($api, $runner, $since, $head, $known, $spec['files'] ?? []);

                return;
            }

            $result = $this->run($api, $runner, Instructions::apiDocsRequest($api, $known), Instructions::apiDocsSchema());
            $written = array_values($result['structured_output']['endpoints'] ?? []);
            if ($written === [] && $known === []) {
                throw new \RuntimeException(Str::limit(($result['result'] ?? '') ?: 'The agent found no endpoints.', 1000));
            }
            $source = 'written from the code of '.$system->name.' at '.substr($head, 0, 10);
            if ($known !== []) {
                $source = 'OpenAPI spec in the code: '.Str::limit(implode(', ', $spec['files']), 200).' ('.count($known).'), and '.$source.' ('.count($written).' more)';
            }
            $api->update([
                'docs' => ApiDocs::merge($written, $known), 'docs_source' => $source, 'docs_commit' => $head,
                'docs_files' => self::files($result, $spec['files'] ?? [], $system->name),
                'docs_state' => 'ready', 'docs_cost_usd' => $result['total_cost_usd'] ?? null, 'docs_written_at' => now(),
            ]);
        } catch (Throwable $e) {
            $api->update(['docs_state' => 'failed', 'docs_error' => Str::limit($api->redact($e->getMessage()), 2000)]);
        }
    }

    /** After a push: only what changed since the docs' commit, and only when it touched the API. */
    private function update(ApiConnection $api, ClaudeRun $runner, string $since, string $head, array $known, array $specFiles): void
    {
        $system = $api->system;
        $changed = array_values(array_filter(preg_split('/\R/', (string) self::git($system->path(), ['diff', '--name-only', $since, $head]))));
        $files = [...($api->docs_files ?? []), ...$specFiles];
        $touched = $files === [] ? $changed : array_values(array_filter($changed, fn ($file) => collect($files)
            ->contains(fn ($ours) => $file === $ours || str_starts_with($file, rtrim($ours, '/').'/'))));

        if ($touched === []) {
            $api->update(['docs_commit' => $head, 'docs_state' => 'ready', 'docs_source' => preg_replace('/ at [0-9a-f]{7,40}( \(|$)/', ' at '.substr($head, 0, 10).'$1', (string) $api->docs_source, 1)]);

            return;
        }

        $cwd = $this->workspace($api);
        $diff = (string) self::git($system->path(), ['diff', $since, $head, '--', ...array_slice($touched, 0, 300)]);
        $diffFile = null;
        if ($diff !== '' && strlen($diff) <= self::MAX_DIFF_BYTES) {
            File::put($cwd.'/changes.diff', $diff);
            $diffFile = $cwd.'/changes.diff';
        }
        $result = $this->run($api, $runner, Instructions::apiDocsRequest($api, [], ['current' => $api->docs, 'changed' => $touched, 'diff' => $diffFile]), Instructions::apiDocsSchema(update: true));
        $output = $result['structured_output'] ?? null;
        if (! is_array($output) || ! isset($output['changed'], $output['removed'])) {
            throw new \RuntimeException(Str::limit(($result['result'] ?? '') ?: 'The agent gave no update.', 1000));
        }

        $gone = array_map(fn ($line) => ApiDocs::key(['method' => Str::before(trim($line), ' '), 'path' => Str::after(trim($line), ' ')]), $output['removed']);
        $kept = array_values(array_filter($api->docs, fn ($endpoint) => ! in_array(ApiDocs::key($endpoint), $gone, true)));
        $api->update([
            'docs' => ApiDocs::merge(ApiDocs::merge($kept, $output['changed']), $known),
            'docs_source' => preg_replace('/ at [0-9a-f]{7,40}( \(|$)/', ' at '.substr($head, 0, 10).'$1', (string) $api->docs_source, 1),
            'docs_commit' => $head, 'docs_files' => self::files($result, $specFiles, $system->name),
            'docs_state' => 'ready', 'docs_cost_usd' => round((float) $api->docs_cost_usd + (float) ($result['total_cost_usd'] ?? 0), 4), 'docs_written_at' => now(),
        ]);
    }

    private function run(ApiConnection $api, ClaudeRun $runner, string $request, array $schema): array
    {
        $system = $api->system;
        $command = [...ClaudeRun::command((float) config('agent.scan_budget_usd'), model: config('agent.chat.model'), effort: 'high'),
            '--no-session-persistence',
            '--json-schema', json_encode($schema),
            '--add-dir', $system->path(),
        ];
        $run = $runner->run(
            $command, $this->workspace($api), $request, [$system->path() => $system->name.'/'],
            fn () => null, fn () => false, $this->timeout,
            Instructions::credentials($this->userId ? User::find($this->userId) : null),
        );
        if (! isset($run['result']['structured_output']) && trim((string) $run['errors']) !== '') {
            throw new \RuntimeException(Str::limit(trim($run['errors']), 1000));
        }

        return $run['result'];
    }

    private function workspace(ApiConnection $api): string
    {
        $cwd = dirname(config('agent.workspaces_path')).'/scans/'.$api->system->name.'-'.$api->name;
        File::ensureDirectoryExists($cwd);

        return $cwd;
    }

    /** @return list<string> the files the endpoints come from, the system's name taken off */
    private static function files(array $result, array $specFiles, string $system): array
    {
        $files = collect($result['structured_output']['files'] ?? [])->filter(fn ($file) => is_string($file) && trim($file) !== '')
            ->map(fn ($file) => ltrim(preg_replace('/:\d+$/', '', trim($file)), '/'))
            ->map(fn ($file) => str_starts_with($file, $system.'/') ? substr($file, strlen($system) + 1) : $file);

        return $files->merge($specFiles)->unique()->values()->take(500)->all();
    }

    /** Git plumbing on the system's folder; null when it fails. (Not the Process facade: that is the agent's.) */
    private static function git(string $path, array $args): ?string
    {
        $git = new Process(['git', '-c', 'safe.directory=*', ...$args], $path);
        $git->run();

        return $git->isSuccessful() ? trim($git->getOutput()) : null;
    }

    public function failed(?Throwable $e): void
    {
        $this->api->fresh()?->update(['docs_state' => 'failed', 'docs_error' => 'Interrupted: the agent container stopped. Write them again.']);
    }
}
