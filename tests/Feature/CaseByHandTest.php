<?php

namespace Tests\Feature;

use App\Agent\Instructions;
use App\Casebook\Matcher;
use App\Jobs\RematchCasebook;
use App\Models\CasebookEntry;
use App\Models\Space;
use App\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CaseByHandTest extends TestCase
{
    public function test_a_ticket_tied_by_hand_stays_tied_and_the_agent_reads_that_case_first(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $case = CasebookEntry::create(['title' => 'Invoice line written twice', 'symptoms' => 'Doubled amount.', 'solution' => 'Credit it.', 'state' => CasebookEntry::APPROVED]);
        $ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-7', 'summary' => 'Something entirely different', 'first_seen_at' => now(), 'last_seen_at' => now(), 'left_at' => now()]);

        $this->post("/casebook/{$case->id}/tickets", ['key' => 'sup-7 '])->assertSessionHasNoErrors();
        $this->assertSame([$case->id, true], [$ticket->fresh()->casebook_entry_id, $ticket->fresh()->casebook_manual]);

        // Its words match nothing, yet a rematch leaves it be.
        (new RematchCasebook)->handle();
        $ticket->update(['left_at' => null]);
        (new RematchCasebook)->handle();
        $this->assertSame($case->id, $ticket->fresh()->casebook_entry_id);

        $this->assertStringContainsString("The engineer tied this ticket to casebook/case-{$case->id}.md", Instructions::chatStart($ticket->fresh(), 'And?'));
        $this->get("/casebook/{$case->id}/edit")->assertInertia(fn (Assert $page) => $page->where('linked.0.key', 'SUP-7')->where('linked.0.manual', true));

        $this->post("/casebook/{$case->id}/tickets", ['key' => 'NOPE-1'])->assertSessionHasErrors('key');

        // Removed again: no longer tied, and not tied back by its words.
        $this->post("/casebook/{$case->id}/tickets/{$ticket->id}/unlink");
        $this->assertFalse($ticket->fresh()->casebook_manual);
        $this->assertNotSame($case->id, $ticket->fresh()->casebook_entry_id);
        $this->assertContains($case->id, Matcher::rejections()[$ticket->id] ?? []);
    }
}
