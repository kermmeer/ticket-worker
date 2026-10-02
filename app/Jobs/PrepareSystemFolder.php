<?php

namespace App\Jobs;

use App\Models\System;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Makes a system's folder ready to receive your pushes.
 *
 * It runs in the worker, not in php-fpm: the worker writes as the tree's owner, the
 * same user your pushes arrive as, so the folder stays writable for them.
 */
class PrepareSystemFolder implements ShouldQueue
{
    use Queueable;

    public function __construct(public System $system) {}

    public function handle(): void
    {
        $path = $this->system->path();

        try {
            if (! is_dir($path.'/.git')) {
                File::ensureDirectoryExists($path);
                $this->git($path, ['init', '--quiet', '--initial-branch='.$this->system->branch]);
            }

            // A push to the checked-out branch updates the files as well, so the folder
            // is always the code as last pushed, and nothing here ever has to pull.
            $this->git($path, ['config', 'receive.denyCurrentBranch', 'updateInstead']);

            $this->system->update(['state' => System::READY, 'error' => null]);
        } catch (Throwable $e) {
            $this->system->update(['state' => System::FAILED, 'error' => $e->getMessage()]);
        }
    }

    private function git(string $path, array $arguments): void
    {
        Process::path($path)->run(['git', ...$arguments])->throw();
    }
}
