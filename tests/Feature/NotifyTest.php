<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use App\Notify\WarnSlas;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotifyTest extends TestCase
{
    public function test_notices_are_your_own_finished_work_since_you_last_asked(): void
    {
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Billed twice', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $session = AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-000000000016', 'model' => 'm']);
        AgentTurn::create(['agent_session_id' => $session->id, 'user_id' => auth()->id(), 'kind' => 'analysis', 'prompt' => 'x', 'state' => 'done', 'finished_at' => now()]);
        AgentTurn::create(['agent_session_id' => $session->id, 'user_id' => null, 'kind' => 'message', 'prompt' => 'x', 'state' => 'done', 'finished_at' => now()]);

        $notices = $this->getJson('/notices?since='.urlencode(now()->subMinute()->toIso8601String()))->assertOk()->json('notices');

        $this->assertSame(['Analysis ready: SUP-1'], array_column($notices, 'title'));
    }

    public function test_an_sla_about_to_break_posts_once_in_its_spaces_channel(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $space = SpacesTest::space(['state' => Space::ACTIVE, 'notify_webhook' => 'https://hooks.slack.com/services/T0/B0/x']);
        $this->assertStringNotContainsString('hooks.slack.com', (string) DB::table('spaces')->value('notify_webhook'), 'Stored encrypted.');
        Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Billed twice', 'first_seen_at' => now(), 'last_seen_at' => now(),
            'slas' => [['name' => 'Time to first response', 'state' => 'running', 'remaining_ms' => 1200000, 'due_at' => now()->addMinutes(20)->toIso8601String()],
                ['name' => 'Time to resolution', 'state' => 'running', 'remaining_ms' => 9000000, 'due_at' => now()->addHours(30)->toIso8601String()]]]);

        (new WarnSlas)();
        (new WarnSlas)();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'SUP-1: Time to first response breaks in'));
    }

    public function test_the_webhook_is_set_tested_and_never_shown(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);

        $this->put("/spaces/{$space->id}/webhook", ['url' => 'https://hooks.slack.com/services/T0/B0/secret'])->assertSessionHas('success');
        $this->put("/spaces/{$space->id}/webhook", ['test' => true])->assertSessionHas('success');
        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'can post here'));
        $this->assertFalse(str_contains($this->get("/spaces/{$space->id}/edit")->getContent(), 'services/T0/B0/secret'));
    }
}
