<?php

namespace App\Setup;

use App\Jira\JiraClient;
use App\Jira\JiraException;
use App\Outbox\OutboxClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /** The scheduler asks every minute; a report older than this means nobody answered. */
    private const FRESH_SECONDS = 180;

    public function __construct(
        private readonly JiraClient $jira,
        private readonly OutboxClient $outbox,
    ) {}

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
            $this->outbox(),
            $this->anthropicKey(),
            $this->agentContainer(),
            $this->folder('systems', 'Systems', config('agent.systems_path'),
                'the folders you push the systems into'),
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

        if ($connection === 'sync') {
            return $this->item('queue', 'Basics', 'Queue worker', self::TODO,
                'Jobs run inside the web request: there is no worker. Set QUEUE_CONNECTION=database once the worker containers run.');
        }

        $report = Cache::get('health.default');

        return $this->fresh($report)
            ? $this->item('queue', 'Basics', 'Queue worker', self::OK,
                "The worker takes jobs from the {$connection} queue; it last reported {$this->ago($report)}.")
            : $this->item('queue', 'Basics', 'Queue worker', self::TODO,
                "Jobs go to the {$connection} queue, but no worker has reported {$this->since($report)}. Are the worker and scheduler containers running?");
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

        try {
            // Asked once a minute at most: this page is opened often while setting up.
            $me = Cache::remember('setup.jira.'.md5($settings['JIRA_BASE'].$settings['JIRA_EMAIL']), 60, fn () => $this->jira->myself());
        } catch (JiraException $e) {
            return $this->item('jira', 'Jira', 'Connection', self::TODO, $e->getMessage());
        }

        return $this->item('jira', 'Jira', 'Connection', self::OK,
            'Connected to '.$settings['JIRA_BASE'].' as '.($me['displayName'] ?? $settings['JIRA_EMAIL']).'.');
    }

    private function jiraWrite(): array
    {
        return config('services.jira.write') === 'real'
            ? $this->item('jira-write', 'Jira', 'Writing to Jira', self::NOTE,
                'JIRA_WRITE=real: the post and transition buttons reach Jira.')
            : $this->item('jira-write', 'Jira', 'Writing to Jira', self::OK,
                'JIRA_WRITE=dummy: nothing is ever posted to Jira.');
    }

    private function outbox(): array
    {
        $problem = Cache::remember('setup.outbox', 60, fn () => $this->outbox->check() ?? '');

        return $problem === ''
            ? $this->item('outbox', 'Jira', 'jira-outbox', self::OK, 'Connected: reply drafts go there, and you send them from there.')
            : $this->item('outbox', 'Jira', 'jira-outbox', self::TODO, $problem);
    }

    private function anthropicKey(): array
    {
        return match (true) {
            filled(config('agent.claude.api_key')) => $this->item('anthropic', 'Claude', 'Sign-in', self::OK,
                'API key set: Commercial Terms, billed per use.'),
            filled(config('agent.claude.oauth_token')) => $this->item('anthropic', 'Claude', 'Sign-in', self::NOTE,
                "Subscription token set: analyses use your subscription's limits, and its privacy setting decides whether Anthropic may train on them."),
            default => $this->item('anthropic', 'Claude', 'Sign-in', self::TODO,
                'Not set. Agents need ANTHROPIC_API_KEY, or a subscription token as CLAUDE_CODE_OAUTH_TOKEN, in shared/.env.'),
        };
    }

    private function agentContainer(): array
    {
        $report = Cache::get('health.agents');

        if (! $this->fresh($report)) {
            return $this->item('agent', 'Claude', 'Agent container', self::TODO,
                "The agent container has not reported {$this->since($report)}. Agents run there (CONCEPT.md §12).");
        }

        if (blank($report['claude'] ?? null)) {
            return $this->item('agent', 'Claude', 'Agent container', self::TODO,
                'The agent container runs, but Claude Code does not answer in it.');
        }

        return $this->item('agent', 'Claude', 'Agent container', self::OK,
            "{$report['claude']}, last reported {$this->ago($report)}.");
    }

    private function fresh(?array $report): bool
    {
        return $report !== null && Carbon::parse($report['at'])->isAfter(now()->subSeconds(self::FRESH_SECONDS));
    }

    private function ago(array $report): string
    {
        return Carbon::parse($report['at'])->diffForHumans();
    }

    private function since(?array $report): string
    {
        return $report === null ? 'yet' : 'since '.$this->ago($report);
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
