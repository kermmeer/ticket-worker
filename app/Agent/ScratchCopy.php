<?php

namespace App\Agent;

use App\Models\System;
use App\Models\Ticket;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Where a patch turn works (CONCEPT.md §8): a copy of one system inside the ticket's
 * workspace, the only place the agent may write, at the commit the system is at now. The
 * system itself is never touched. Afterwards the copy's changes become one commit, and that
 * commit a `git format-patch` file for `git am`.
 *
 * Git runs here as a plain process, not through the Process facade: it is plumbing, the same
 * in a test as anywhere else.
 */
class ScratchCopy
{
    /** A patch bigger than this is not a fix, it is a rewrite: refused. */
    public const MAX_BYTES = 512 * 1024;

    public static function path(Ticket $ticket, System $system): string
    {
        return Workspace::path($ticket).'/patch/'.$system->name;
    }

    /** A fresh copy at the system's current commit. Returns that commit. */
    public function prepare(System $system, string $dir): string
    {
        $base = trim($this->git($system->path(), ['rev-parse', 'HEAD']));

        File::deleteDirectory($dir);
        File::ensureDirectoryExists(dirname($dir));
        // --shared borrows the system's objects instead of copying them: fast, and the
        // system is only ever read. New objects land in the copy's own folder.
        $this->git(dirname($dir), ['clone', '--quiet', '--shared', '--no-checkout', $system->path(), $dir]);
        $this->git($dir, ['checkout', '--quiet', '--detach', $base]);

        return $base;
    }

    /**
     * The agent's changes as one commit, and that commit as a patch. Null when it changed
     * nothing.
     *
     * @return array{patch: string, files: list<array{path: string, added: int, removed: int}>}|null
     */
    public function build(string $dir, string $message): ?array
    {
        $this->git($dir, ['add', '--all']);
        if (trim($this->git($dir, ['diff', '--cached', '--name-only'])) === '') {
            return null;
        }

        $this->git($dir, [
            '-c', 'user.name=Ticket Worker', '-c', 'user.email=ticket-worker@localhost',
            'commit', '--quiet', '--no-verify', '--no-gpg-sign', '-m', $message,
        ]);

        $patch = $this->git($dir, ['format-patch', '-1', '--stdout', '--no-signature', 'HEAD']);
        if (strlen($patch) > self::MAX_BYTES) {
            throw new RuntimeException('The patch is over 512 kB: too big to be a fix. Ask for a smaller change.');
        }

        $files = [];
        foreach (preg_split('/\R/', trim($this->git($dir, ['show', '--numstat', '--format=', 'HEAD']))) as $line) {
            if (preg_match('/^(\d+|-)\t(\d+|-)\t(.+)$/', $line, $m)) {
                $files[] = ['path' => $m[3], 'added' => (int) $m[1], 'removed' => (int) $m[2]];
            }
        }

        return ['patch' => $patch, 'files' => $files];
    }

    private function git(string $cwd, array $arguments): string
    {
        // The system folders belong to whoever pushes into them; git must be told that is fine.
        $process = new Process(['git', '-c', 'safe.directory=*', ...$arguments], $cwd, ['GIT_TERMINAL_PROMPT' => '0']);
        $process->setTimeout(300)->mustRun();

        return $process->getOutput();
    }
}
