<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The reply, files, assignee and status in one step, from Ticket Worker itself. */
class SendReplyTest extends TestCase
{
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't', 'services.jira.write' => 'real']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $this->ticket = Ticket::create(['space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Http::fake([
            '*/rest/api/2/issue/SUP-1/transitions*' => Http::response(['transitions' => [
                ['id' => '31', 'name' => 'Respond to customer', 'to' => ['name' => 'Waiting for customer', 'statusCategory' => ['key' => 'indeterminate']], 'fields' => []],
            ]]),
            '*/rest/api/2/issue/SUP-1/attachments' => Http::response([['id' => '9']]),
            '*/rest/api/3/issue/SUP-1/comment' => Http::response(['id' => '100']),
            '*/rest/api/2/issue/SUP-1/comment' => Http::response(['id' => '101']),
            '*/rest/api/3/issue/SUP-1/assignee' => Http::response(null, 204),
            '*/rest/api/2/issue/SUP-1*' => Http::response(['fields' => ['summary' => 'x', 'status' => ['name' => 'Open'], 'reporter' => ['displayName' => 'Rita', 'accountId' => 'rita-1', 'accountType' => 'atlassian'], 'attachment' => []]]),
            '*' => Http::response(['comments' => [], 'total' => 0]),
        ]);
    }

    public function test_the_reply_the_assignee_and_the_status_go_in_that_order(): void
    {
        $this->post("/tickets/{$this->ticket->id}/send", ['body' => "Hi Rita,\n\nFixed: see https://boss.example.com/x.", 'visibility' => 'internal', 'assignee' => 'reporter', 'transition' => '31'])
            ->assertSessionHas('success', 'Internal note posted, assigned, status Waiting for customer.');

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => $r->method() !== 'GET')->values();
        $this->assertStringEndsWith('/rest/api/3/issue/SUP-1/comment', $sent[0]->url());
        $this->assertSame('sd.public.comment', $sent[0]['properties'][0]['key']);
        $this->assertSame('https://boss.example.com/x', $sent[0]['body']['content'][1]['content'][1]['marks'][0]['attrs']['href'], 'A web address is a link.');
        $this->assertSame('rita-1', $sent[1]['accountId']);
        $this->assertSame('31', $sent[2]['transition']['id']);
    }

    public function test_your_template_alone_is_never_posted_but_the_change_still_goes(): void
    {
        Setting::put(Setting::REPLY_TEMPLATE, "Hey @crm,\n\n{reply}\n\nKind regards,\n{first_name}");

        $this->post("/tickets/{$this->ticket->id}/send", ['body' => "Hey @crm,\n\n\n\nKind regards,\nTest", 'transition' => '31'])->assertSessionHas('success', 'Status Waiting for customer.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/comment'));
        $this->post("/tickets/{$this->ticket->id}/send", ['body' => "Hey @crm,\n\nKind regards,\nTest"])->assertSessionHas('error');
    }

    public function test_files_go_with_the_reply_in_wiki_markup(): void
    {
        $this->post("/tickets/{$this->ticket->id}/send", ['body' => 'See *this*.', 'files' => [UploadedFile::fake()->image('screen.png')]])->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/attachments'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/rest/api/2/issue/SUP-1/comment') && str_contains($request['body'], 'See \\*this\\*.') && str_contains($request['body'], '!screen.png|thumbnail!'));
    }

    public function test_with_writing_off_it_says_what_it_would_do_and_sends_nothing(): void
    {
        config(['services.jira.write' => 'dummy']);

        $this->post("/tickets/{$this->ticket->id}/send", ['body' => 'Fixed.', 'transition' => '31'])
            ->assertSessionHas('error', 'JIRA_WRITE=dummy: nothing was sent. With it real, SUP-1 would now get the reply, then the status.');

        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_a_status_jira_no_longer_offers_stops_after_what_was_done(): void
    {
        $this->post("/tickets/{$this->ticket->id}/send", ['body' => 'Fixed.', 'transition' => '99'])
            ->assertSessionHas('error', 'Done: reply posted. Then it stopped: Jira no longer offers that status change from where the ticket is now.');
    }

    public function test_the_form_gets_the_status_changes_and_who_can_take_it(): void
    {
        $this->getJson("/tickets/{$this->ticket->id}/send-options")
            ->assertJsonPath('transitions.0.to', 'Waiting for customer')
            ->assertJsonPath('reporter.assignable', true);
    }
}
