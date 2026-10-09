<?php

namespace Tests\Feature;

use App\Agent\Instructions;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SharedClaudeTest extends TestCase
{
    public function test_own_first_then_the_env_one_then_the_one_set_on_setup(): void
    {
        config(['agent.claude.api_key' => null, 'agent.claude.oauth_token' => 'sk-ant-oat-env']);
        $this->assertSame(['CLAUDE_CODE_OAUTH_TOKEN' => 'sk-ant-oat-env'], Instructions::credentials());

        // With .env set, that is the shared one, even with one saved on Setup.
        $this->put('/setup/claude', ['kind' => 'key', 'secret' => 'sk-ant-api-shared'])->assertSessionHasNoErrors();
        $this->assertSame(['CLAUDE_CODE_OAUTH_TOKEN' => 'sk-ant-oat-env'], Instructions::instanceCredentials());
        $this->assertSame(['env', 'subscription token'], Instructions::sharedSource());

        // Without .env, the one set on Setup.
        config(['agent.claude.oauth_token' => null]);
        $this->assertSame(['ANTHROPIC_API_KEY' => 'sk-ant-api-shared'], Instructions::credentials());
        $this->assertSame(['ANTHROPIC_API_KEY' => 'sk-ant-api-shared'], Instructions::instanceCredentials(), 'Automatic quick scans run on the shared one.');
        $this->assertStringNotContainsString('sk-ant-api-shared', (string) DB::table('settings')->where('key', Setting::SHARED_CLAUDE)->value('value'));
        $this->assertFalse(str_contains($this->get('/setup')->getContent(), 'sk-ant-api-shared'), 'The setup page shows the shared secret.');

        $me = auth()->user();
        $me->update(['claude_oauth_token' => 'sk-ant-oat-mine']);
        $this->assertSame(['CLAUDE_CODE_OAUTH_TOKEN' => 'sk-ant-oat-mine'], Instructions::credentials($me->fresh()), 'Your own goes first.');
    }

    public function test_only_an_admin_sets_it(): void
    {
        auth()->user()->update(['role' => 'editor']);

        $this->put('/setup/claude', ['kind' => 'key', 'secret' => 'x'])->assertForbidden();
    }
}
