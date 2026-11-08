<?php

namespace App\Agent;

use App\Models\System;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The checks a build runs before it is handed in (lint, a type check, unit tests): commands
 * per system, in a checks workspace of its own where the setup command installed the
 * dependencies once. Those (node_modules, vendor) are ignored by git, so they stay when the
 * workspace is moved to the state of a build.
 *
 * A system that needs Node names a version: its official build is downloaded once into the
 * tools folder, since the agent container has only PHP and Composer. Private repositories
 * the setup cannot reach (behind the VPN) can be redirected to a system pushed here.
 */
class Checks
{
    /** The end of each check's output that comes back: enough to see what failed. */
    public const TAIL = 6000;

    public const CHECK_TIMEOUT = 900;

    /** Installed dependencies: never cleaned away when the workspace moves to a build. */
    public const KEEP = ['node_modules/', 'vendor/', '.venv/', 'venv/', '__pycache__/', '.pytest_cache/', '.angular/'];

    public const SETUP_TIMEOUT = 1800;

    public static function root(): string
    {
        return dirname(rtrim((string) config('agent.workspaces_path'), '/'));
    }

    public static function dir(System $system): string
    {
        return self::root().'/checks/'.$system->name;
    }

    /** Where private package files for a system go: copied here from a machine that can reach them. */
    public static function packages(System $system): string
    {
        return self::root().'/checks/packages/'.$system->name;
    }

    /** A fresh workspace at the system's branch, its runtime, and the setup command run in it. */
    public function prepare(System $system): string
    {
        $dir = self::dir($system);
        $log = '';
        if (! is_dir($dir.'/.git')) {
            File::ensureDirectoryExists(dirname($dir));
            $this->git(dirname($dir), ['clone', '--quiet', '--shared', '--no-checkout', $system->path(), $dir]);
        } else {
            $this->git($dir, ['fetch', '--quiet', 'origin']);
        }
        $this->git($dir, ['checkout', '--quiet', '--force', '--detach', "origin/{$system->branch}"]);
        // What the setup installs stays, also where the system's .gitignore does not say so.
        File::put($dir.'/.git/info/exclude', implode("\n", self::KEEP)."\n");
        $this->git($dir, ['clean', '--quiet', '-fd']);
        $log .= "Workspace at {$system->branch} (".trim($this->git($dir, ['rev-parse', '--short', 'HEAD'])).")\n";

        if (filled($system->checks_node)) {
            $log .= 'Node '.$this->node($system->checks_node)['version']."\n";
        }
        if (filled($system->checks_python)) {
            $log .= 'Python '.$this->python($system->checks_python)['version']."\n";
        }

        // Private npm packages you copied here (npm pack, with the VPN on): into npm's cache,
        // where npm ci finds them by the checksum in the lockfile, instead of the registry it cannot reach.
        $packages = glob(self::packages($system).'/*.tgz') ?: [];
        if ($packages !== [] && filled($system->checks_node)) {
            $process = $this->shell($system, $dir, 'npm cache add '.implode(' ', array_map('escapeshellarg', $packages)), 600);
            $log .= 'Private packages: '.implode(', ', array_map('basename', $packages)).($process->isSuccessful() ? '' : ' (could not add: '.trim($process->getErrorOutput()).')')."\n";
        }

        if (filled($system->checks_setup)) {
            $log .= "\n$ {$system->checks_setup}\n";
            $process = $this->shell($system, $dir, $system->checks_setup, self::SETUP_TIMEOUT);
            $log .= Str::substr($process->getOutput().$process->getErrorOutput(), -20000);
            if (! $process->isSuccessful()) {
                throw new RuntimeException($log."\n\nThe setup command failed (exit {$process->getExitCode()}).");
            }
        }

        return $log;
    }

    /**
     * Run the enabled checks on a build copy as it is now, edits not committed included.
     *
     * @return list<array{name: string, ok: bool, exit: ?int, seconds: int, output: string}>
     */
    public function run(System $system, string $copy, ?callable $onCheck = null): array
    {
        $checks = $system->runnableChecks();
        if ($checks->isEmpty()) {
            return [];
        }

        // One build at a time per system: they share the workspace.
        return Cache::lock("checks.{$system->id}", self::CHECK_TIMEOUT * max(1, $checks->count()) + 120)
            ->block(self::CHECK_TIMEOUT * 2, function () use ($system, $copy, $checks, $onCheck) {
                $dir = self::dir($system);
                // The copy's state as a commit of its own, without touching the copy: stash create.
                $this->git($copy, ['add', '--all']);
                $state = trim($this->git($copy, ['stash', 'create'])) ?: trim($this->git($copy, ['rev-parse', 'HEAD']));
                $this->git($copy, ['update-ref', 'refs/ticket-worker/checks', $state]);
                $this->git($dir, ['fetch', '--quiet', '--no-tags', $copy, 'refs/ticket-worker/checks']);
                $this->git($dir, ['checkout', '--quiet', '--force', '--detach', 'FETCH_HEAD']);
                $this->git($dir, ['clean', '--quiet', '-fd']);

                $results = [];
                foreach ($checks as $check) {
                    $started = microtime(true);
                    $process = $this->shell($system, $dir, $check->command, self::CHECK_TIMEOUT, tolerateTimeout: true);
                    $result = [
                        'name' => $check->name,
                        'ok' => $process->isSuccessful(),
                        'exit' => $process->getExitCode(),
                        'seconds' => (int) round(microtime(true) - $started),
                        'output' => Str::substr(trim($process->getOutput()."\n".$process->getErrorOutput()), -self::TAIL),
                    ];
                    $results[] = $result;
                    $onCheck && $onCheck($result);
                }

                return $results;
            });
    }

    /** "lint ✓ · tsc ✗": what a run came to, in one line. */
    public static function line(array $results): string
    {
        return collect($results)->map(fn ($result) => $result['name'].($result['ok'] ? ' ✓' : ' ✗'))->implode(' · ');
    }

    /**
     * The official Node build of a major version, downloaded once into the tools folder.
     *
     * @return array{version: string, bin: string}
     */
    public function node(string $major): array
    {
        $major = ltrim(trim($major), 'v');
        $tools = self::root().'/tools';
        $existing = collect(glob("{$tools}/node-v{$major}.*-linux-x64") ?: [])->sort()->last();
        if ($existing !== null && is_file($existing.'/bin/node')) {
            return ['version' => Str::between(basename($existing), 'node-', '-linux-x64'), 'bin' => $existing.'/bin'];
        }

        $index = Http::timeout(30)->get('https://nodejs.org/dist/index.json')->throw()->json();
        $version = collect($index)->first(fn ($release) => str_starts_with($release['version'] ?? '', "v{$major}."))['version']
            ?? throw new RuntimeException("No Node {$major} on nodejs.org.");
        $name = "node-{$version}-linux-x64";
        File::ensureDirectoryExists($tools);
        $gz = "{$tools}/{$name}.tar.gz";
        Http::timeout(600)->sink($gz)->get("https://nodejs.org/dist/{$version}/{$name}.tar.gz")->throw();
        // No gzip in the image: PHP unzips, tar unpacks (and keeps npm's symlinks).
        $tar = "{$tools}/{$name}.tar";
        copy('compress.zlib://'.$gz, $tar);
        (new Process(['tar', '-xf', $tar, '-C', $tools]))->setTimeout(600)->mustRun();
        @unlink($gz);
        @unlink($tar);

        return ['version' => $version, 'bin' => "{$tools}/{$name}/bin"];
    }

    /**
     * A standalone CPython build (python-build-standalone) of a version like 3.12, downloaded
     * once into the tools folder, with pip.
     *
     * @return array{version: string, bin: string}
     */
    public function python(string $version): array
    {
        $version = trim($version);
        $tools = self::root().'/tools';
        $existing = collect(glob("{$tools}/python-{$version}.*") ?: [])->filter(fn ($dir) => is_file($dir.'/bin/python3'))->sort()->last();
        if ($existing !== null) {
            return ['version' => Str::after(basename($existing), 'python-'), 'bin' => $existing.'/bin'];
        }

        $release = Http::timeout(30)->get('https://api.github.com/repos/astral-sh/python-build-standalone/releases/latest')->throw()->json();
        $asset = collect($release['assets'] ?? [])->first(fn ($asset) => preg_match('/^cpython-'.preg_quote($version, '/').'\.\d+\+\d+-x86_64-unknown-linux-gnu-install_only\.tar\.gz$/', $asset['name'] ?? ''))
            ?? throw new RuntimeException("No Python {$version} build to download.");
        $full = Str::between($asset['name'], 'cpython-', '+');
        File::ensureDirectoryExists($tools);
        $gz = "{$tools}/{$asset['name']}";
        Http::timeout(600)->sink($gz)->get($asset['browser_download_url'])->throw();
        $tar = substr($gz, 0, -3);
        copy('compress.zlib://'.$gz, $tar);
        $unpack = "{$tools}/python-unpack-".uniqid();
        File::ensureDirectoryExists($unpack);
        (new Process(['tar', '-xf', $tar, '-C', $unpack]))->setTimeout(600)->mustRun();
        File::moveDirectory("{$unpack}/python", "{$tools}/python-{$full}");
        File::deleteDirectory($unpack);
        @unlink($gz);
        @unlink($tar);

        return ['version' => $full, 'bin' => "{$tools}/python-{$full}/bin"];
    }

    /** A command in the workspace, with the system's runtime on the PATH and its redirects for git. */
    private function shell(System $system, string $dir, string $command, int $timeout, bool $tolerateTimeout = false): Process
    {
        $path = (filled($system->checks_node) ? $this->node($system->checks_node)['bin'].':' : '')
            .(filled($system->checks_python) ? $this->python($system->checks_python)['bin'].':' : '')
            .(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin');
        $env = [
            'PATH' => $path, 'CI' => 'true', 'HOME' => self::root().'/home', 'NG_CLI_ANALYTICS' => 'false',
            'COMPOSER_HOME' => self::root().'/home/.composer', 'npm_config_cache' => self::root().'/home/.npm',
            'npm_config_prefer_offline' => 'true',
            // Python: the virtualenv in the workspace, where it stays; nothing asks.
            'PIPENV_VENV_IN_PROJECT' => '1', 'PIPENV_YES' => '1', 'PIPENV_NOSPIN' => '1', 'PIP_CACHE_DIR' => self::root().'/home/.pip',
            'PIP_DISABLE_PIP_VERSION_CHECK' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ] + $this->redirects($system);
        File::ensureDirectoryExists(self::root().'/home');

        $process = Process::fromShellCommandline($command, $dir, $env);
        $process->setTimeout($timeout);
        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            if (! $tolerateTimeout) {
                throw new RuntimeException("Stopped after {$timeout} seconds: {$command}");
            }
        }

        return $process;
    }

    /**
     * "git@code.internal…/lib.git = lib": the private address becomes the system pushed here
     * under that name, for git and so for composer and npm, through git's own config.
     *
     * @return array<string, string>
     */
    public function redirects(System $system): array
    {
        $env = ['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'safe.directory', 'GIT_CONFIG_VALUE_0' => '*'];
        $n = 1;
        foreach (preg_split('/\R/', (string) $system->checks_redirects) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$from, $name] = array_map('trim', explode('=', $line, 2));
            $target = System::query()->where('name', $name)->first();
            if ($from === '' || $target === null || ! $target->hasCode()) {
                continue;
            }
            $env["GIT_CONFIG_KEY_{$n}"] = "url.{$target->path()}.insteadOf";
            $env["GIT_CONFIG_VALUE_{$n}"] = $from;
            $n++;
        }
        $env['GIT_CONFIG_COUNT'] = (string) $n;

        return $env;
    }

    private function git(string $cwd, array $arguments): string
    {
        $process = new Process(['git', '-c', 'safe.directory=*', ...$arguments], $cwd, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(300)->mustRun();

        return $process->getOutput();
    }
}
