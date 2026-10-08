<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTurn;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalysisNoteTest extends TestCase
{
    public function test_your_note_goes_with_the_analysis_and_shows_as_yours(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'x', 'status' => ['name' => 'Open'], 'attachment' => []], 'comments' => [], 'total' => 0])]);
        config(['agent.claude.api_key' => 'sk-ant-test', 'services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'a@b.c', 'services.jira.token' => 't']);
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Billed twice', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->post("/tickets/{$ticket->id}/analyse", ['language' => 'auto', 'note' => "Service 337800; I suspect the September proration.\n"])->assertRedirect();

        $turn = AgentTurn::sole();
        $this->assertSame('Service 337800; I suspect the September proration.', $turn->note);
        $this->assertStringContainsString('It comes from them, not from the reporter', $turn->prompt);
        $this->assertStringContainsString('Service 337800; I suspect the September proration.', $turn->prompt);
        Queue::assertPushed(RunAgentTurn::class);

        $this->get("/tickets/{$ticket->id}")->assertInertia(fn (Assert $page) => $page
            ->where('session.turns.0.prompt', 'Service 337800; I suspect the September proration.'));
    }

    public function test_without_a_note_nothing_is_added(): void
    {
        Queue::fake();
        config(['agent.claude.api_key' => 'sk-ant-test']);
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->post("/tickets/{$ticket->id}/analyse", ['language' => 'auto', 'note' => ''])->assertRedirect();

        $this->assertNull(AgentTurn::sole()->note);
        $this->assertStringNotContainsString('The engineer adds', AgentTurn::sole()->prompt);
    }
}
