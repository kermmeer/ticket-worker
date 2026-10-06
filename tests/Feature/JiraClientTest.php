<?php

namespace Tests\Feature;

use App\Jira\JiraClient;
use App\Jira\JiraException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraClientTest extends TestCase
{
    private function client(): JiraClient
    {
        return new JiraClient('https://example.atlassian.net', 'me@example.com', 'secret-token');
    }

    public function test_it_lists_every_space_across_pages(): void
    {
        Http::fake([
            'example.atlassian.net/rest/api/3/project/search?startAt=0*' => Http::response([
                'values' => [['id' => 1, 'key' => 'SUP', 'name' => 'Support', 'projectTypeKey' => 'service_desk']],
                'isLast' => false,
            ]),
            'example.atlassian.net/rest/api/3/project/search?startAt=1*' => Http::response([
                'values' => [['id' => 2, 'key' => 'DEV', 'name' => 'Development', 'projectTypeKey' => 'software']],
                'isLast' => true,
            ]),
        ]);

        $projects = $this->client()->projects();

        $this->assertSame(['SUP', 'DEV'], array_column($projects, 'key'));
        $this->assertSame('service_desk', $projects[0]['type']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('me@example.com:secret-token')));
    }

    public function test_a_search_hands_back_the_next_page_until_the_last(): void
    {
        Http::fakeSequence('example.atlassian.net/rest/api/3/search/jql')
            ->push(['issues' => [['id' => '1', 'key' => 'SUP-1']], 'nextPageToken' => 'page-2', 'isLast' => false])
            ->push(['issues' => [['id' => '2', 'key' => 'SUP-2']], 'isLast' => true]);

        $first = $this->client()->search('project = SUP', ['summary']);
        $second = $this->client()->search('project = SUP', ['summary'], $first['next']);

        $this->assertSame('page-2', $first['next']);
        $this->assertNull($second['next']);
        Http::assertSent(fn (Request $request) => ($request['nextPageToken'] ?? null) === 'page-2');
    }

    public function test_a_refusal_becomes_one_sentence_without_the_credentials(): void
    {
        Http::fake(['*' => Http::response(['errorMessages' => ['Client must be authenticated to access this resource.']], 401)]);

        try {
            $this->client()->myself();
            $this->fail('A 401 must throw.');
        } catch (JiraException $e) {
            $this->assertStringContainsString('refused the credentials', $e->getMessage());
            $this->assertStringContainsString('must be authenticated', $e->getMessage());
            $this->assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function test_field_errors_are_named(): void
    {
        Http::fake(['*' => Http::response(['errorMessages' => [], 'errors' => ['jql' => 'Field labelz does not exist.']], 400)]);

        $this->expectExceptionMessage('jql: Field labelz does not exist.');

        $this->client()->search('labelz = x', ['summary']);
    }

    public function test_nothing_is_sent_without_settings(): void
    {
        Http::fake();

        try {
            (new JiraClient('', null, null))->myself();
            $this->fail('An unconfigured client must throw.');
        } catch (JiraException $e) {
            $this->assertStringContainsString('JIRA_BASE', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_ticket_opens_where_the_settings_say(): void
    {
        $this->assertSame('https://example.atlassian.net/browse/SUP-1', $this->client()->browseUrl('SUP-1'));

        $outbox = new JiraClient('https://example.atlassian.net', 'me@example.com', 'secret-token', 'https://outbox.example.com/{key}');
        $this->assertSame('https://outbox.example.com/SUP-1', $outbox->browseUrl('SUP-1'));
    }
}
