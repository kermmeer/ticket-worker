<?php

namespace Tests\Feature;

use App\Casebook\Matcher;
use App\Models\CasebookEntry;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Case links you can trust: of its own kind, judged where they show, and grouped once confirmed. */
class CaseJudgedTest extends TestCase
{
    private Space $space;

    private CasebookEntry $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->space = SpacesTest::space(['state' => Space::ACTIVE]);
        $this->case = CasebookEntry::create(['title' => 'Ported-out numbers stay routed', 'symptoms' => 'A number ported out keeps its routing code and stays active.',
            'solution' => 'Clear the routing.', 'keywords' => 'porting, ported out, routing', 'state' => CasebookEntry::APPROVED, 'kind' => 'support']);
    }

    private function ticket(string $key, string $summary, string $type = 'Incident', array $attributes = []): Ticket
    {
        return Ticket::create($attributes + ['space_id' => $this->space->id, 'jira_id' => $key, 'key' => $key, 'summary' => $summary, 'issue_type' => $type, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    public function test_a_support_case_is_no_hint_for_a_development_story(): void
    {
        $matcher = new Matcher([$this->case]);
        $text = 'Ported out number keeps its routing code, stays active after porting';

        $this->assertNotNull($matcher->best($text, [], 'support'));
        $this->assertNull($matcher->best($text, [], 'development'));
        $this->assertNotNull($matcher->best($text), 'Without a kind, as before.');
    }

    public function test_yes_confirms_and_not_this_case_unties_for_good(): void
    {
        $ticket = $this->ticket('SUP-1', 'x', attributes: ['casebook_entry_id' => $this->case->id]);
        auth()->user()->update(['role' => 'member']);

        $this->post("/tickets/{$ticket->id}/case/confirm");
        $this->assertTrue($ticket->fresh()->casebook_manual);

        $this->post("/tickets/{$ticket->id}/case/reject");
        $this->assertFalse($ticket->fresh()->casebook_manual);
        $this->assertNotSame($this->case->id, $ticket->fresh()->casebook_entry_id);
        $this->assertContains($this->case->id, Matcher::rejections()[$ticket->id] ?? []);
    }

    public function test_confirmed_tickets_group_and_get_one_reply(): void
    {
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't', 'services.jira.write' => 'real']);
        Http::fake(['*' => Http::response(['id' => '1'])]);
        $one = $this->ticket('SUP-1', 'x', attributes: ['casebook_entry_id' => $this->case->id, 'casebook_manual' => true]);
        $two = $this->ticket('SUP-2', 'y', attributes: ['casebook_entry_id' => $this->case->id, 'casebook_manual' => true]);
        $this->ticket('SUP-3', 'z', attributes: ['casebook_entry_id' => $this->case->id]); // only a hint: not in the group

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tickets', fn ($tickets) => collect($tickets)->firstWhere('key', 'SUP-1')['casebook']['others'] === 1
            && collect($tickets)->firstWhere('key', 'SUP-3')['casebook']['confirmed'] === false));

        $this->post("/casebook/{$this->case->id}/reply-all", ['body' => 'Fixed for all of you.'])->assertSessionHas('success', 'Posted to SUP-1, SUP-2.');
        Http::assertSentCount(2);
    }

    public function test_an_analysis_that_finds_the_case_fits_confirms_the_link(): void
    {
        config(['agent.claude.api_key' => 'sk-ant-test', 'agent.claude.bin' => 'claude', 'agent.workspaces_path' => sys_get_temp_dir().'/tw-cj-'.uniqid(),
            'services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't']);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'x', 'description' => '', 'status' => ['name' => 'Open'], 'attachment' => []], 'comments' => [], 'total' => 0])]);
        $ticket = $this->ticket('SUP-9', 'x');
        $session = \App\Models\AgentSession::create(['ticket_id' => $ticket->id, 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000c7', 'model' => 'claude-opus-5']);
        $turn = \App\Models\AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'analysis', 'prompt' => 'x']);
        \Illuminate\Support\Facades\Process::fake(['*claude*' => \Illuminate\Support\Facades\Process::result(json_encode(['type' => 'result', 'result' => '', 'structured_output' => [
            'headline' => 'h', 'problem' => 'p', 'system' => 's', 'cause' => 'c', 'evidence' => [], 'fix' => 'f', 'fix_kind' => 'data', 'reply_draft' => 'r', 'confidence' => 'high', 'confidence_reason' => 'x',
            'casebook' => ['case' => $this->case->id, 'fit' => 'fits'],
        ]])."\n"), '*' => \Illuminate\Support\Facades\Process::result('')]);

        (new \App\Jobs\RunAgentTurn($turn))->handle(app(\App\Agent\Workspace::class), app(\App\Agent\ClaudeRun::class));

        $this->assertSame([$this->case->id, true], [$ticket->fresh()->casebook_entry_id, $ticket->fresh()->casebook_manual]);
    }
}
