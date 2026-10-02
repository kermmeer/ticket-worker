<?php

namespace Tests\Feature;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SetupTest extends TestCase
{
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
        ]);
        Process::fake();
    }

    public function test_secrets_show_as_set_never_as_their_value(): void
    {
        $response = $this->get('/setup')->assertOk();

        $response->assertDontSee('jira-token-that-must-not-leak', false);
        $response->assertDontSee('sk-ant-key-that-must-not-leak', false);
        $this->assertSame('ok', $this->check('jira')['state']);
        $this->assertSame('ok', $this->check('anthropic')['state']);
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

    public function test_the_claude_cli_counts_only_when_it_answers(): void
    {
        Process::fake(['*' => Process::result('2.1.282 (Claude Code)')]);
        $cli = $this->check('claude-cli');
        $this->assertSame('ok', $cli['state']);
        $this->assertSame('2.1.282 (Claude Code)', $cli['detail']);

        Process::fake(['*' => Process::result(exitCode: 127)]);
        $this->assertSame('todo', $this->check('claude-cli')['state']);
    }

    public function test_a_synchronous_queue_means_there_is_no_worker(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertSame('todo', $this->check('queue')['state']);
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
