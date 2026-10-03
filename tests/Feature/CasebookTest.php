<?php

namespace Tests\Feature;

use App\Casebook\Matcher;
use App\Jira\JiraClient;
use App\Jobs\RematchCasebook;
use App\Jobs\SyncSpace;
use App\Models\CasebookEntry;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CasebookTest extends TestCase
{
    private function entry(array $attributes = []): CasebookEntry
    {
        return CasebookEntry::create($attributes + [
            'title' => 'Invoice mail missing after a credit note',
            'symptoms' => 'Customer gets no invoice by mail after a credit note; the invoice is visible in the portal.',
            'cause' => 'CreditNoteService::apply() returns before the mail is queued.',
            'solution' => 'Resend from the portal: Invoices, Resend. Fixed in release 4.2.',
            'keywords' => 'factuur, facture, faktura, creditnota',
            'state' => CasebookEntry::APPROVED,
        ]);
    }

    private function ticket(Space $space, string $key, string $summary): Ticket
    {
        return Ticket::create([
            'space_id' => $space->id, 'jira_id' => $key, 'key' => $key, 'summary' => $summary,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_a_ticket_matches_on_shared_words_plurals_and_other_languages(): void
    {
        $matcher = new Matcher([$this->entry()]);

        $this->assertNotNull($matcher->best('Invoices not sent after credit note'));
        $this->assertNotNull($matcher->best('Factuur niet ontvangen na creditnota'), 'Dutch, through the keywords.');
        $this->assertNull($matcher->best('Password reset mail arrives twice'), 'One shared word is not a match.');
        $this->assertNull($matcher->best('The issue is not with this and that, please help'), 'Stop words count for nothing.');
    }

    public function test_the_best_of_several_entries_wins(): void
    {
        $invoices = $this->entry();
        $export = $this->entry([
            'title' => 'Accounting export stops at 1000 rows',
            'symptoms' => 'The export to accounting is cut off; only the first 1000 rows arrive.',
            'keywords' => 'export, boekhouding, comptabilité',
        ]);

        $matcher = new Matcher([$invoices, $export]);

        $this->assertSame($export->id, $matcher->best('Export to accounting stops after 1000 rows')['id']);
        $this->assertSame($invoices->id, $matcher->best('No invoice mail since the credit note')['id']);
    }

    public function test_writing_a_case_from_a_ticket_starts_from_that_ticket(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $system = System::create(['name' => 'billing', 'branch' => 'main', 'state' => System::READY]);
        $space->systems()->attach($system);
        $this->ticket($space, 'SUP-7', 'Invoices not sent after a credit note');

        $this->get('/casebook/create?ticket=SUP-7')->assertInertia(fn (Assert $page) => $page
            ->component('Casebook/Form', true)
            ->where('entry.title', 'Invoices not sent after a credit note')
            ->where('entry.source_tickets', 'SUP-7')
            ->where('entry.system_id', $system->id));
    }

    public function test_saving_tidies_the_ticket_keys_and_matches_tickets_again(): void
    {
        Queue::fake();

        $this->post('/casebook', [
            'title' => 'Invoice mail missing', 'symptoms' => 'No invoice mail.', 'solution' => 'Resend it.',
            'source_tickets' => 'sup-1, SUP-2; sup-1', 'state' => CasebookEntry::DRAFT,
        ])->assertRedirect();

        $this->assertSame(['SUP-1', 'SUP-2'], CasebookEntry::first()->source_tickets);
        Queue::assertPushed(RematchCasebook::class);
    }

    public function test_only_approved_cases_are_matched(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $ticket = $this->ticket($space, 'SUP-1', 'Invoices not sent after a credit note');
        $entry = $this->entry(['state' => CasebookEntry::DRAFT]);

        (new RematchCasebook)->handle();
        $this->assertNull($ticket->fresh()->casebook_entry_id);

        $entry->update(['state' => CasebookEntry::APPROVED]);
        (new RematchCasebook)->handle();
        $this->assertSame($entry->id, $ticket->fresh()->casebook_entry_id);

        $entry->update(['state' => CasebookEntry::RETIRED]);
        (new RematchCasebook)->handle();
        $this->assertNull($ticket->fresh()->casebook_entry_id);
    }

    public function test_a_sync_matches_every_ticket_it_brings_in(): void
    {
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 'secret-token']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $entry = $this->entry();
        Http::fake([
            '*/rest/api/3/field' => Http::response([]),
            '*/rest/api/3/search/jql' => Http::response(['issues' => [
                ['id' => '1', 'key' => 'SUP-1', 'fields' => ['summary' => 'Invoices not sent after a credit note']],
                ['id' => '2', 'key' => 'SUP-2', 'fields' => ['summary' => 'Printer offline']],
            ], 'isLast' => true]),
        ]);

        (new SyncSpace($space))->handle(app(JiraClient::class));

        $this->assertSame($entry->id, Ticket::firstWhere('key', 'SUP-1')->casebook_entry_id);
        $this->assertNull(Ticket::firstWhere('key', 'SUP-2')->casebook_entry_id);
    }

    public function test_the_overview_and_the_case_show_each_other(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $entry = $this->entry();
        $ticket = $this->ticket($space, 'SUP-1', 'Invoices not sent after a credit note');
        (new RematchCasebook)->handle();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('tickets.0.casebook.id', $entry->id)
            ->where('tickets.0.casebook.title', $entry->title));

        $this->get("/casebook/{$entry->id}/edit")->assertInertia(fn (Assert $page) => $page
            ->component('Casebook/Form', true)
            ->where('matches.0.key', 'SUP-1'));

        $this->delete("/casebook/{$entry->id}");
        $this->assertNull($ticket->fresh()->casebook_entry_id);
    }
}
