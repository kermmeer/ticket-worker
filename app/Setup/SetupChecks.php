<?php

namespace App\Setup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * What the Setup page shows: whether each thing Ticket Worker needs is in place.
 *
 * A secret is reported as set or not set, never by its value. This page is the
 * first place anyone looks when something does not work, which makes it the
 * page most likely to end up in a screenshot.
 */
class SetupChecks
{
    public const OK = 'ok';

    public const TODO = 'todo';

    public const NOTE = 'note';

    /**
     * @return list<array{key: string, group: string, title: string, state: string, detail: string}>
     */
    public function all(): array
    {
        return [
            $this->database(),
            $this->queue(),
            $this->jira(),
            $this->jiraWrite(),
            $this->anthropicKey(),
            $this->claudeCli(),
            $this->folder('systems', 'Systems', config('agent.systems_path'),
                'where the tool keeps its own clones of the systems'),
            $this->folder('workspaces', 'Agent workspaces', config('agent.workspaces_path'),
                'one folder per ticket, where its agent works'),
        ];
    }

    private function database(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            // The driver's message can name hosts and users; the page does not need them.
            return $this->item('database', 'Basics', 'Database', self::TODO,
                'Cannot reach the database. Check the DB_ settings in shared/.env.');
        }

        try {
            $ran = DB::table('migrations')->count();
        } catch (Throwable) {
            $ran = 0;
        }

        return $ran > 0
            ? $this->item('database', 'Basics', 'Database', self::OK, "Connected, {$ran} migrations run.")
            : $this->item('database', 'Basics', 'Database', self::TODO, 'Connected, but no migrations have run yet.');
    }

    private function queue(): array
    {
        $connection = config('queue.default');

        return $connection === 'sync'
            ? $this->item('queue', 'Basics', 'Queue worker', self::TODO,
                'Jobs run inside the web request: there is no worker yet. It comes with the worker containers (CONCEPT.md §12).')
            : $this->item('queue', 'Basics', 'Queue worker', self::OK, "Jobs go to the {$connection} queue.");
    }

    private function jira(): array
    {
        $settings = [
            'JIRA_BASE' => config('services.jira.base'),
            'JIRA_EMAIL' => config('services.jira.email'),
            'JIRA_TOKEN' => config('services.jira.token'),
        ];
        $missing = array_keys(array_filter($settings, fn ($value) => blank($value)));

        if ($missing !== []) {
            return $this->item('jira', 'Jira', 'Connection', self::TODO,
                'Not connected. Set '.implode(', ', $missing).' in shared/.env.');
        }

        return $this->item('jira', 'Jira', 'Connection', self::OK,
            'Reads '.$settings['JIRA_BASE'].' as '.$settings['JIRA_EMAIL'].'.');
    }

    private function jiraWrite(): array
    {
        return config('services.jira.write') === 'real'
            ? $this->item('jira-write', 'Jira', 'Writing to Jira', self::NOTE,
                'JIRA_WRITE=real: the post and transition buttons reach Jira.')
            : $this->item('jira-write', 'Jira', 'Writing to Jira', self::OK,
                'JIRA_WRITE=dummy: nothing is ever posted to Jira.');
    }

    private function anthropicKey(): array
    {
        return filled(config('agent.claude.api_key'))
            ? $this->item('anthropic', 'Claude', 'API key', self::OK, 'Set.')
            : $this->item('anthropic', 'Claude', 'API key', self::TODO,
                'Not set. Agents need ANTHROPIC_API_KEY in shared/.env.');
    }

    private function claudeCli(): array
    {
        try {
            $result = Process::timeout(10)->run([config('agent.claude.bin'), '--version']);
        } catch (Throwable) {
            $result = null;
        }

        if ($result?->successful()) {
            return $this->item('claude-cli', 'Claude', 'Claude Code CLI', self::OK, trim($result->output()));
        }

        return $this->item('claude-cli', 'Claude', 'Claude Code CLI', self::TODO,
            'Not installed where this page runs. Agents run in the agent container, which does not exist yet (CONCEPT.md §12).');
    }

    private function folder(string $key, string $title, string $path, string $purpose): array
    {
        if (! is_dir($path)) {
            return $this->item($key, 'Folders', $title, self::TODO, "Not created yet: {$path}, {$purpose}.");
        }

        if (! is_writable($path)) {
            return $this->item($key, 'Folders', $title, self::TODO, "Not writable from here: {$path}, {$purpose}.");
        }

        return $this->item($key, 'Folders', $title, self::OK, "{$path}, {$purpose}.");
    }

    private function item(string $key, string $group, string $title, string $state, string $detail): array
    {
        return compact('key', 'group', 'title', 'state', 'detail');
    }
}
