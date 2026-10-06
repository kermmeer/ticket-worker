<?php

namespace Tests\Feature;

use App\Agent\Instructions;
use App\Jira\JiraClient;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReplySignOffTest extends TestCase
{
    private function jira(): void
    {
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 'token']);
        $this->app->forgetInstance(JiraClient::class);
    }

    public function test_replies_are_signed_with_the_first_name_of_the_jira_account(): void
    {
        $this->jira();
        Http::fake(['example.atlassian.net/rest/api/3/myself' => Http::response(['displayName' => 'Robin de Vries'])]);

        $this->assertStringContainsString('Sign off as Robin.', Instructions::replyRules());
        // Asked once a day, not on every turn.
        Instructions::replyRules();
        Http::assertSentCount(1);
    }

    public function test_without_an_answer_from_jira_the_team_signs(): void
    {
        $this->jira();
        Http::fake(['example.atlassian.net/*' => Http::response([], 503)]);

        $this->assertStringContainsString('Sign off as the support team.', Instructions::replyRules());
    }

    public function test_your_own_rules_can_use_the_name_too(): void
    {
        Setting::put(Setting::REPLY_RULES, "- Keep it short.\n- Groetjes, {first_name}");

        $this->assertSame("- Keep it short.\n- Groetjes, Sam", Setting::replyRulesFor('Sam'));
    }
}
