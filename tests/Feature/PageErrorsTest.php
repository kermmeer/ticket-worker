<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\Ticket;
use Tests\TestCase;

class PageErrorsTest extends TestCase
{
    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())];
    }

    public function test_a_refused_page_action_answers_in_words_not_an_error_page(): void
    {
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        // Patching with no session yet is refused (409) with a reason.
        $this->post("/tickets/{$ticket->id}/patch", [], $this->inertia())->assertStatus(409)->assertExactJson(['message' => 'Analyse first.']);
        $this->post('/tickets/999999/analyse', ['language' => 'auto'], $this->inertia())->assertStatus(404)->assertJsonStructure(['message']);
    }

    public function test_validation_keeps_its_own_way(): void
    {
        $ticket = Ticket::create(['space_id' => SpacesTest::space(['state' => Space::ACTIVE])->id, 'jira_id' => '1', 'key' => 'SUP-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->post("/tickets/{$ticket->id}/messages", ['text' => ''], $this->inertia())->assertRedirect()->assertSessionHasErrors('text');
    }
}
