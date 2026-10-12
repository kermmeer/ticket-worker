<?php

namespace Tests\Feature;

use App\Jobs\RematchCasebook;
use App\Models\CasebookEntry;
use App\Models\Space;
use App\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CaseLinksTest extends TestCase
{
    public function test_a_ticket_said_not_related_is_untied_and_stays_untied(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $case = CasebookEntry::create(['title' => 'Invoice mail not sent after credit note', 'symptoms' => 'No invoice mail after a credit note', 'solution' => 'x',
            'keywords' => 'invoice mail credit note', 'state' => CasebookEntry::APPROVED]);
        $ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Invoice mail not sent after credit note',
            'first_seen_at' => now(), 'last_seen_at' => now()]);
        (new RematchCasebook)->handle();
        $this->assertSame($case->id, $ticket->fresh()->casebook_entry_id, 'The words match: tied.');

        $this->get("/casebook/{$case->id}/edit")->assertInertia(fn (Assert $page) => $page->where('linked.0.key', 'SUP-1'));

        $this->post("/casebook/{$case->id}/tickets/{$ticket->id}/unlink")->assertSessionHas('success');
        $this->assertNull($ticket->fresh()->casebook_entry_id);

        // The next sync or rematch does not tie it again.
        (new RematchCasebook)->handle();
        $this->assertNull($ticket->fresh()->casebook_entry_id);
        $this->get("/casebook/{$case->id}/edit")->assertInertia(fn (Assert $page) => $page->where('linked', [])->where('unlinked.0.key', 'SUP-1'));

        // Changed your mind.
        $this->post("/casebook/{$case->id}/tickets/{$ticket->id}/relink")->assertSessionHas('success');
        $this->assertSame($case->id, $ticket->fresh()->casebook_entry_id);
    }

    public function test_members_cannot_untie(): void
    {
        $case = CasebookEntry::create(['title' => 't', 'symptoms' => 's', 'solution' => 'x', 'state' => CasebookEntry::APPROVED]);
        $ticket = Ticket::create(['space_id' => SpacesTest::space()->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        auth()->user()->update(['role' => 'member']);

        $this->post("/casebook/{$case->id}/tickets/{$ticket->id}/unlink")->assertForbidden();
    }
}
