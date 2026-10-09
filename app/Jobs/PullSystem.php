<?php

namespace App\Jobs;

use App\Models\System;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Fetches a project from its Git host, where that host is reachable (docs/TEAM.md, item 7),
 * and moves its folder to what the branch is there: the same as a push would. A token goes
 * to git as a header through its environment, never on a command line or in a URL; an SSH
 * key, as a file only this run can read, deleted afterwards.
 */
class PullSystem implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public System $system) {}

    public static function sshAvailable(): bool
    {
        return (new Process(['sh', '-c', 'command -v ssh']))->run() === 0;
    }

    public function handle(): void
    {
        $system = $this->system->fresh();
        if ($system === null || ! $system->hasCode() || ! filled($system->pull_url) || ! is_dir($system->path().'/.git')) {
            return;
        }
        $system->update(['pull_state' => 'running', 'pull_error' => null]);

        $env = ['GIT_TERMINAL_PROMPT' => '0'];
        $keyFile = null;
        if ($system->pull_auth === 'token' && filled($system->pull_secret)) {
            $env += [
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'http.extraHeader',
                'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode(($system->pull_username ?: 'oauth2').':'.$system->pull_secret),
            ];
        } elseif ($system->pull_auth === 'ssh' && filled($system->pull_secret)) {
            $keyFile = tempnam(sys_get_temp_dir(), 'pull-key-');
            file_put_contents($keyFile, rtrim($system->pull_secret)."\n");
            chmod($keyFile, 0600);
            $known = dirname((string) config('agent.claude.config_dir')).'/known_hosts';
            $env['GIT_SSH_COMMAND'] = 'ssh -i '.escapeshellarg($keyFile).' -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile='.escapeshellarg($known);
        }

        try {
            $branch = $system->branch;
            // Into the checked-out branch itself, then the files follow: as a push with updateInstead does.
            $this->git($system, ['fetch', '--prune', '--no-tags', '--update-head-ok', $system->pull_url, "+refs/heads/{$branch}:refs/heads/{$branch}"], $env);
            $this->git($system, ['reset', '--quiet', '--hard', 'HEAD'], $env);
            $system->update(['pull_state' => 'done', 'pulled_at' => now(), 'pull_error' => null]);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            foreach (array_filter([$system->pull_secret, $env['GIT_CONFIG_VALUE_0'] ?? null]) as $secret) {
                $message = str_replace($secret, '[secret]', $message);
            }
            $system->update(['pull_state' => 'failed', 'pull_error' => Str::limit($message, 1500)]);
        } finally {
            if ($keyFile !== null) {
                @unlink($keyFile);
            }
        }
    }

    private function git(System $system, array $arguments, array $env): void
    {
        $process = new Process(['git', '-c', 'safe.directory=*', ...$arguments], $system->path(), $env);
        $process->setTimeout(600);
        if ($process->run() !== 0) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'git stopped without saying why.');
        }
    }
}
