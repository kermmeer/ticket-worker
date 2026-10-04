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
 *
 * The systems' code stays where it is, mounted read-only, and is passed with --add-dir.
 */
class Workspace
{
    /** Attachments bigger than this are listed but not downloaded. */
    public const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;

    public const MAX_ATTACHMENTS = 25;

    public function __construct(private readonly JiraClient $jira) {}

    public static function path(Ticket $ticket): string
    {
        return config('agent.workspaces_path').'/'.Str::slug($ticket->key, '-');
    }

    /** Everything the agent reads, fetched fresh. Returns what it skipped, in words. */
    public function prepare(Ticket $ticket): array
    {
        $root = self::path($ticket);
        File::ensureDirectoryExists($root.'/attachments');
        File::ensureDirectoryExists($root.'/systems');
        File::deleteDirectory($root.'/casebook');
        File::ensureDirectoryExists($root.'/casebook');

        $issue = $this->jira->issue($ticket->key);
        $skipped = [];

        foreach (array_slice($issue['attachments'], 0, self::MAX_ATTACHMENTS) as $file) {
            $target = $root.'/attachments/'.self::safeName($file['id'], $file['filename']);
            if (is_file($target)) {
                continue;
            }
            if ($file['size'] > self::MAX_ATTACHMENT_BYTES || ! $this->jira->download($file['url'], $target, self::MAX_ATTACHMENT_BYTES)) {
                $skipped[] = "{$file['filename']} (not downloaded)";
            }
        }
        if (count($issue['attachments']) > self::MAX_ATTACHMENTS) {
            $skipped[] = (count($issue['attachments']) - self::MAX_ATTACHMENTS).' more attachments (not downloaded)';
        }

        file_put_contents($root.'/ticket.md', self::ticketDocument($issue, $skipped));

        foreach ($this->systems($ticket) as $system) {
            file_put_contents($root.'/systems/'.$system->name.'.history.txt', self::history($system));
        }

        $this->writeCasebook($root.'/casebook');

        return $skipped;
    }

    /** The systems this ticket's space names, or every system when it names none. */
    public function systems(Ticket $ticket)
    {
        $systems = $ticket->space->systems()->where('state', System::READY)->get();

        return $systems->isEmpty() ? System::query()->where('state', System::READY)->get() : $systems;
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

    private function writeCasebook(string $folder): void
    {
        $index = ['# The casebook: solved cases', '', 'Each file holds one case: symptoms, cause, fix. Check them before digging.', ''];

        foreach (CasebookEntry::query()->approved()->with('system:id,name')->get() as $entry) {
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
