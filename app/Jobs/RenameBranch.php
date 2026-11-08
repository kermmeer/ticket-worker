<?php

namespace App\Jobs;

use App\Models\TicketBranch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Process\Process;

/**
 * Own work's branch takes the key of the ticket it became: feature/WIP-12 becomes
 * feature/DEVBESUP-3300, in the system's repository here. By the worker, which may write it.
 */
class RenameBranch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public TicketBranch $branch, public string $to) {}

    public function handle(): void
    {
        $branch = $this->branch->fresh(['system']);
        if ($branch === null || $branch->name === $this->to || $branch->system === null) {
            return;
        }
        $git = fn (array $args) => (new Process(['git', '-c', 'safe.directory=*', ...$args], $branch->system->path()));
        if ($git(['rev-parse', '--verify', '--quiet', "refs/heads/{$branch->name}"])->run() !== 0) {
            return;
        }
        if ($git(['rev-parse', '--verify', '--quiet', "refs/heads/{$this->to}"])->run() === 0) {
            return; // a branch of that name is there already: leave both as they are
        }
        $git(['branch', '-m', $branch->name, $this->to])->mustRun();
        $branch->update(['name' => $this->to]);
    }
}
