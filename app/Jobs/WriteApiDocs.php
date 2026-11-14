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
use Throwable;

/**
 * An API's docs: from its OpenAPI spec when it serves one or the code holds one (no agent,
 * no cost), else written by an agent that reads the code where its routes are. On the agents
 * queue, where the systems are and Claude runs.
 */
class WriteApiDocs implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public ApiConnection $api, public ?int $userId = null)
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
            if (($found = $docs->fromSpec($api)) !== null && $found['endpoints'] !== []) {
                $api->update(['docs' => $found['endpoints'], 'docs_source' => 'OpenAPI spec '.$found['source'], 'docs_state' => 'ready', 'docs_cost_usd' => 0, 'docs_written_at' => now()]);

                return;
            }
            $system = $api->system;
            if ($system === null || ! $system->hasCode() || $system->headCommit() === null) {
                throw new \RuntimeException('No spec to read, and no code here to read it from: push the system first, or describe the API in its notes.');
            }

            $cwd = dirname(config('agent.workspaces_path')).'/scans/'.$system->name.'-'.$api->name;
            File::ensureDirectoryExists($cwd);
            $command = [...ClaudeRun::command((float) config('agent.scan_budget_usd'), model: config('agent.chat.model'), effort: 'high'),
                '--no-session-persistence',
                '--json-schema', json_encode(Instructions::apiDocsSchema()),
                '--add-dir', $system->path(),
            ];
            $run = $runner->run(
                $command, $cwd, Instructions::apiDocsRequest($api), [$system->path() => $system->name.'/'],
                fn () => null, fn () => false, $this->timeout,
                Instructions::credentials($this->userId ? User::find($this->userId) : null),
            );
            $result = $run['result'];
            $endpoints = $result['structured_output']['endpoints'] ?? null;
            if (! is_array($endpoints) || $endpoints === []) {
                throw new \RuntimeException(Str::limit(($result['result'] ?? '') ?: trim($run['errors']) ?: 'The agent found no endpoints.', 1000));
            }
            $api->update([
                'docs' => array_values($endpoints), 'docs_source' => 'written from the code of '.$system->name.' at '.substr($system->headCommit(), 0, 10),
                'docs_state' => 'ready', 'docs_cost_usd' => $result['total_cost_usd'] ?? null, 'docs_written_at' => now(),
            ]);
        } catch (Throwable $e) {
            $api->update(['docs_state' => 'failed', 'docs_error' => Str::limit($api->redact($e->getMessage()), 2000)]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->api->fresh()?->update(['docs_state' => 'failed', 'docs_error' => 'Interrupted: the agent container stopped. Write them again.']);
    }
}
