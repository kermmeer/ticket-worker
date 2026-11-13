<?php

namespace App\Agent;

use App\Jira\JiraClient;
use App\Models\CasebookEntry;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * The folder a ticket's agent works in (CONCEPT.md §10), outside the repository:
 *
 *   ticket.md                 the ticket, description and every comment
 *   attachments/              its files; the agent reads images and PDFs itself
 *   casebook/                 INDEX.md and one file per approved case
 *   systems/<name>.history.txt  each system's recent commits and the files they touched
 *   systems/<name>.branch-*.diff.txt  work already on a branch named after the ticket
 *
 * The systems' code stays where it is, mounted read-only, and is passed with --add-dir.
 */
class Workspace
{
    /** Attachments bigger than this are listed but not downloaded. */
    public const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;

    /** A branch's diff for the agent, at most: a whole feature fits, a rewrite does not. */
    public const MAX_BRANCH_DIFF = 300 * 1024;

    public const MAX_ATTACHMENTS = 25;

    public function __construct(private readonly JiraClient $jira) {}

    public static function path(Ticket $ticket): string
    {
        // Another site's tickets get its number in front: two sites can both have a SUP-1.
        return config('agent.workspaces_path').'/'.($ticket->site ? "site{$ticket->site}-" : '').Str::slug($ticket->key, '-');
    }

    /** Everything the agent reads, fetched fresh. Returns what it skipped, in words. */
    public function prepare(Ticket $ticket): array
    {
        $root = self::path($ticket);
        File::ensureDirectoryExists($root.'/attachments');
        File::ensureDirectoryExists($root.'/systems');
        File::deleteDirectory($root.'/casebook');
        File::ensureDirectoryExists($root.'/casebook');

        $skipped = [];
        // Own work has no Jira issue: what you wrote, and your screenshots and files.
        if ($ticket->isLocal()) {
            $issue = $ticket->localIssue();
            foreach ($ticket->localFiles() as $file) {
                copy($ticket->filesPath().'/'.$file['name'], $root.'/attachments/'.$file['name']);
            }
        } else {
            $jira = $ticket->space->jira();
            $issue = $jira->issue($ticket->key);
        }

        foreach ($ticket->isLocal() ? [] : array_slice($issue['attachments'], 0, self::MAX_ATTACHMENTS) as $file) {
            $target = $root.'/attachments/'.self::safeName($file['id'], $file['filename']);
            if (is_file($target)) {
                continue;
            }
            if ($file['size'] > self::MAX_ATTACHMENT_BYTES || ! $jira->download($file['url'], $target, self::MAX_ATTACHMENT_BYTES)) {
                $skipped[] = "{$file['filename']} (not downloaded)";
            }
        }
        if (count($issue['attachments']) > self::MAX_ATTACHMENTS) {
            $skipped[] = (count($issue['attachments']) - self::MAX_ATTACHMENTS).' more attachments (not downloaded)';
        }

        file_put_contents($root.'/ticket.md', self::ticketDocument($issue, $skipped)
            .($ticket->isLocal() ? "\n\nThis is the engineer's own work: no Jira ticket yet. Screenshots and files: "
                .(implode(', ', array_map(fn ($file) => 'attachments/'.$file['name'], $ticket->localFiles())) ?: 'none')."\n" : ''));

        $branchFiles = [];
        foreach ($this->systems($ticket)->filter->hasCode() as $system) {
            file_put_contents($root.'/systems/'.$system->name.'.history.txt', self::history($system));
            // Work already done for this ticket on a branch of its own (yours, or a build's):
            // the agent reads only the checked-out branch and cannot run git, so it gets the diff.
            foreach (self::ticketBranches($system, $ticket->key) as $branch) {
                $file = $system->name.'.branch-'.Str::slug($branch).'.diff.txt';
                file_put_contents($root.'/systems/'.$file, self::branchWork($system, $branch));
                $branchFiles[] = "systems/{$file} ({$system->name}, branch {$branch})";
            }
        }

        if ($branchFiles !== []) {
            file_put_contents($root.'/ticket.md', "\n\n## Work already done for this ticket, on branches\n\n"
                ."The systems' folders show their main branch, not this work. Read these first: what is already changed, in which commits.\n\n"
                .implode("\n", array_map(fn ($line) => "- {$line}", $branchFiles))."\n", FILE_APPEND);
        }

        $this->writeCasebook($root.'/casebook', $ticket->space_id);

        return $skipped;
    }

    /** The systems this ticket's space names: none when it names none. */
    public function systems(Ticket $ticket)
    {
        // What the space has ticked, and nothing else: a space without systems has tickets
        // the agent answers from the ticket, its attachments and the casebook alone.
        return $ticket->space->systems()->where('state', System::READY)->get();
    }

    public static function ticketDocument(array $issue, array $skipped = []): string
    {
        $lines = [
            "# {$issue['key']}: {$issue['summary']}",
            '',
            "Status: {$issue['status']} · Priority: {$issue['priority']} · Type: {$issue['type']}",
            "Reporter: {$issue['reporter']} · Assignee: ".($issue['assignee'] ?? 'nobody'),
            "Created: {$issue['created']} · Updated: {$issue['updated']}",
            '',
            '## Description',
            '',
            trim($issue['description']) !== '' ? trim($issue['description']) : '(empty)',
            '',
            '## Comments ('.count($issue['comments']).', oldest first)',
        ];

        foreach ($issue['comments'] as $comment) {
            $lines[] = '';
            $lines[] = "### {$comment['author']}, {$comment['created']}".($comment['public'] ? '' : ' (internal note)');
            $lines[] = '';
            $lines[] = trim($comment['body']);
        }

        $lines[] = '';
        $lines[] = '## Attachments';
        $lines[] = '';
        foreach ($issue['attachments'] as $file) {
            $lines[] = '- attachments/'.self::safeName($file['id'], $file['filename'])." ({$file['mime']}, {$file['size']} bytes)";
        }
        foreach ($skipped as $note) {
            $lines[] = "- {$note}";
        }
        if ($issue['attachments'] === []) {
            $lines[] = '(none)';
        }

        return implode("\n", $lines)."\n";
    }

    /** The last 150 commits with the files each touched: where regressions show first. */
    /**
     * The branches in a system named after the ticket: ODOSSER-898, fix/ODOSSER-898,
     * feature/odosser-898-something. Ticket keys are unique, so a match is that ticket's work.
     *
     * @return list<string>
     */
    public static function ticketBranches(System $system, string $key): array
    {
        if (! is_dir($system->path().'/.git')) {
            return [];
        }
        // Plumbing: a plain process, the same in a test as anywhere (the Process facade is Claude's, faked there).
        $refs = new \Symfony\Component\Process\Process(['git', '-c', 'safe.directory=*', 'for-each-ref', '--format=%(refname:short)', 'refs/heads'], $system->path());
        $refs->run();
        $pattern = '/(^|\/)'.preg_quote($key, '/').'($|[^0-9])/i';

        return collect(preg_split('/\R/', trim($refs->getOutput())))
            ->filter(fn ($branch) => $branch !== '' && $branch !== $system->branch && preg_match($pattern, $branch))
            // The exact key first, then prefixed ones.
            ->sortBy(fn ($branch) => strcasecmp($branch, $key) === 0 ? 0 : 1)->values()->all();
    }

    /** A branch's commits and its whole change against the system's branch. */
    public static function branchWork(System $system, string $branch): string
    {
        $git = function (array $args) use ($system) {
            $process = new \Symfony\Component\Process\Process(['git', '-c', 'safe.directory=*', ...$args], $system->path());
            $process->setTimeout(120)->run();

            return $process->getOutput();
        };
        $base = $system->branch;
        $log = $git(['log', '--date=iso', '--format=%h %ad %an%n    %s', "{$base}..{$branch}"]);
        $stat = $git(['diff', '--stat', "{$base}...{$branch}"]);
        $diff = $git(['diff', "{$base}...{$branch}"]);
        if (strlen($diff) > self::MAX_BRANCH_DIFF) {
            $diff = substr($diff, 0, self::MAX_BRANCH_DIFF)."\n[cut off: the diff goes on; read the files on the branch's commits above]";
        }

        return "Branch {$branch} of {$system->name}: work already done for this ticket, against {$base}.\n"
            ."This is not what the system's folder shows: that folder is {$base}.\n\n"
            ."Commits on it, newest first:\n".($log ?: "(none beyond {$base})\n")."\nFiles changed:\n{$stat}\nThe change:\n{$diff}";
    }

    public static function history(System $system): string
    {
        $result = Process::path($system->path())->run([
            'git', '-c', 'safe.directory=*', 'log', '-150', '--date=iso', '--name-only',
            '--format=%n%h %ad %an%n    %s',
        ]);

        return $result->successful() && trim($result->output()) !== ''
            ? "Recent history of {$system->name} (branch {$system->branch}), newest first:\n".$result->output()
            : "No history yet: nothing has been pushed to {$system->name}.\n";
    }

    private function writeCasebook(string $folder, int $spaceId): void
    {
        $index = ['# The casebook: solved cases', '', 'Each file holds one case: symptoms, cause, fix. Check them before digging.', ''];

        foreach (CasebookEntry::query()->approved()->forSpace($spaceId)->with('system:id,name')->get() as $entry) {
            $file = "case-{$entry->id}.md";
            file_put_contents("{$folder}/{$file}", implode("\n", [
                "# Case {$entry->id}: {$entry->title}",
                '',
                'System: '.($entry->system?->name ?? 'any'),
                '',
                '## Symptoms', '', $entry->symptoms, '',
                '## Cause', '', $entry->cause ?: '(not written down)', '',
                '## Fix', '', $entry->solution, '',
                'Keywords: '.($entry->keywords ?: '-'),
            ])."\n");
            $index[] = "- {$file}: {$entry->title}".($entry->system ? " ({$entry->system->name})" : '');
        }

        if (count($index) === 4) {
            $index[] = '(no cases yet)';
        }

        file_put_contents("{$folder}/INDEX.md", implode("\n", $index)."\n");
    }

    private static function safeName(string $id, string $filename): string
    {
        return $id.'-'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
    }
}
