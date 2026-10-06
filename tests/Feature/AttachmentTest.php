<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jira.base' => 'https://example.atlassian.net',
            'services.jira.email' => 'me@example.com',
            'services.jira.token' => 'secret-token',
        ]);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $this->ticket = Ticket::create([
            'space_id' => $space->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'Invoice twice',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $file = fn (string $id, string $name, string $mime) => [
            'id' => $id, 'filename' => $name, 'size' => 4, 'mimeType' => $mime,
            'content' => "https://example.atlassian.net/rest/api/2/attachment/content/{$id}",
        ];
        Http::fake([
            '*/rest/api/2/issue/SUP-1/comment*' => Http::response(['comments' => [], 'total' => 0]),
            '*/rest/api/2/issue/SUP-1*' => Http::response(['fields' => [
                'summary' => 'Invoice twice', 'description' => 'See [^invoice.pdf].', 'status' => ['name' => 'To Do'],
                'attachment' => [$file('10', 'invoice.pdf', 'application/pdf'), $file('11', 'page.html', 'text/html')],
            ]]),
            '*/rest/api/2/attachment/content/10' => Http::response('%PDF'),
            '*/rest/api/2/attachment/content/11' => Http::response('<script>alert(1)</script>'),
        ]);
    }

    public function test_a_pdf_opens_in_the_browser_fetched_with_the_apps_token(): void
    {
        $response = $this->get("/tickets/{$this->ticket->id}/attachments/10")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/attachment/content/10')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('me@example.com:secret-token')));
    }

    public function test_it_downloads_when_asked(): void
    {
        $response = $this->get("/tickets/{$this->ticket->id}/attachments/10?download=1")->assertOk();

        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_html_never_opens_on_this_site(): void
    {
        $response = $this->get("/tickets/{$this->ticket->id}/attachments/11")->assertOk();

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_only_the_tickets_own_attachments(): void
    {
        $this->get("/tickets/{$this->ticket->id}/attachments/99")->assertNotFound();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/attachment/content/99'));
    }
}
