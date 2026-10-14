<?php

namespace Tests\Feature;

use App\Agent\Instructions;
use App\Models\Setting;
use App\Models\Space;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReplyTemplateTest extends TestCase
{
    public function test_the_template_is_saved_on_setup_and_needs_a_place_for_the_reply(): void
    {
        $this->put('/setup/reply-template', ['template' => "Hey @crm,\n\nKind regards"])->assertSessionHasErrors('template');
        $this->assertNull(Setting::get(Setting::REPLY_TEMPLATE));

        $this->put('/setup/reply-template', ['template' => "Hey @crm,\r\n\r\n{reply}\r\n\r\nKind regards and have a great day,\r\n{first_name}"])->assertSessionHasNoErrors();
        $this->assertSame("Hey @crm,\n\n{reply}\n\nKind regards and have a great day,\n{first_name}", Setting::get(Setting::REPLY_TEMPLATE));
    }

    public function test_the_ticket_page_gets_it_signed_by_whoever_is_signed_in_and_the_agent_leaves_the_frame_out(): void
    {
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.email' => 'me@example.com', 'services.jira.token' => 't']);
        Http::fake(['*' => Http::response(['fields' => ['summary' => 'x', 'status' => ['name' => 'To Do'], 'attachment' => []], 'comments' => [], 'total' => 0])]);
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->assertStringNotContainsString('no greeting and no sign-off', Instructions::replyRules());

        Setting::put(Setting::REPLY_TEMPLATE, "Hey @crm,\n\n{reply}\n\nKind regards,\n{first_name}");

        $this->get("/tickets/{$ticket->id}")->assertInertia(fn (Assert $page) => $page->where('replyTemplate', "Hey @crm,\n\n{reply}\n\nKind regards,\nTest"));
        $this->assertStringContainsString('no greeting and no sign-off', Instructions::replyRules());
    }
}
