<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SetupTest extends TestCase
{
    /** What Jira answers to "who am I": 200 with a name, or a refusal. */
    private int $jiraStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        // State what the page sees instead of inheriting whatever this machine's .env holds.
        config([
            'services.jira.base' => 'https://example.atlassian.net',
            'services.jira.email' => 'support@example.com',
            'services.jira.token' => 'jira-token-that-must-not-leak',
            'services.jira.write' => 'dummy',
            'agent.claude.api_key' => 'sk-ant-key-that-must-not-leak',
            'queue.default' => 'database',
            'services.outbox.url' => 'http://jira-outbox:5173',
            'services.outbox.token' => 'outbox-token-that-must-not-leak',
        ]);
        Http::fake(fn (Request $request) => str_contains($request->url(), 'jira-outbox')
            ? Http::response(['error' => 'No such draft'], 404)
            : ($this->jiraStatus === 200
            ? Http::response(['displayName' => 'Support Bot'])
            : Http::response(['errorMessages' => []], $this->jiraStatus)));
    }

    public function test_secrets_show_as_set_never_as_their_value(): void
    {
        $response = $this->get('/setup')->assertOk();

        $response->assertDontSee('jira-token-that-must-not-leak', false);
        $response->assertDontSee('sk-ant-key-that-must-not-leak', false);
        $response->assertDontSee('outbox-token-that-must-not-leak', false);
        $this->assertSame('ok', $this->check('outbox')['state']);
        $this->assertSame('ok', $this->check('jira')['state']);
        $this->assertSame('ok', $this->check('anthropic')['state']);
    }

    public function test_the_connection_counts_only_when_jira_answers(): void
    {
        $this->assertStringContainsString('as Support Bot', $this->check('jira')['detail']);

        Cache::flush();
        $this->jiraStatus = 401;

        $jira = $this->check('jira');
        $this->assertSame('todo', $jira['state']);
        $this->assertStringContainsString('refused the credentials', $jira['detail']);
    }

    public function test_missing_jira_settings_are_named(): void
    {
        config(['services.jira.base' => '', 'services.jira.token' => null]);

        $jira = $this->check('jira');

        $this->assertSame('todo', $jira['state']);
        $this->assertStringContainsString('JIRA_BASE, JIRA_TOKEN', $jira['detail']);
        $this->assertStringNotContainsString('JIRA_EMAIL', $jira['detail']);
    }

    public function test_writing_to_jira_is_off_unless_asked_for(): void
    {
        $this->assertStringContainsString('dummy', $this->check('jira-write')['detail']);

        config(['services.jira.write' => 'real']);

        $this->assertSame('note', $this->check('jira-write')['state']);
    }

    public function test_the_agent_container_counts_only_while_it_reports(): void
    {
        $this->assertSame('todo', $this->check('agent')['state']);

        Cache::put('health.agents', ['at' => now()->subSeconds(30)->toIso8601String(), 'claude' => '2.1.282 (Claude Code)']);
        $agent = $this->check('agent');
        $this->assertSame('ok', $agent['state']);
        $this->assertStringContainsString('2.1.282 (Claude Code)', $agent['detail']);

        Cache::put('health.agents', ['at' => now()->subMinutes(10)->toIso8601String(), 'claude' => '2.1.282 (Claude Code)']);
        $this->assertSame('todo', $this->check('agent')['state']);

        Cache::put('health.agents', ['at' => now()->toIso8601String(), 'claude' => null]);
        $this->assertStringContainsString('does not answer', $this->check('agent')['detail']);
    }

    public function test_a_synchronous_queue_means_there_is_no_worker(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertSame('todo', $this->check('queue')['state']);
    }

    public function test_a_queue_counts_as_served_only_while_the_worker_reports(): void
    {
        $this->assertSame('todo', $this->check('queue')['state']);

        Cache::put('health.default', ['at' => now()->toIso8601String()]);

        $this->assertSame('ok', $this->check('queue')['state']);
    }

    /** One check, as the page receives it. */
    private function check(string $key): array
    {
        $found = null;

        // A plain closure, not fn(): an arrow function would capture $found by value.
        $this->get('/setup')->assertInertia(function (Assert $page) use ($key, &$found) {
            $page->component('Setup', true)->where('checks', function (Collection $checks) use ($key, &$found) {
                $found = $checks->firstWhere('key', $key);

                return $found !== null;
            });
        });

        return $found;
    }
}
