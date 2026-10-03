<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    private function ticket(Space $space, string $key, bool $left = false, string $status = 'To Do', string $category = 'new'): Ticket
    {
        return Ticket::create([
            'space_id' => $space->id, 'jira_id' => $key, 'key' => $key, 'summary' => "About {$key}",
            'status' => $status, 'status_category' => $category,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'left_at' => $left ? now() : null,
        ]);
    }

    public function test_it_lists_the_open_tickets_of_active_spaces_only(): void
    {
        config(['services.jira.base' => 'https://example.atlassian.net', 'services.jira.open_url' => null]);
        $active = SpacesTest::space(['project_key' => 'SUP', 'state' => Space::ACTIVE]);
        $paused = SpacesTest::space(['project_key' => 'OLD', 'state' => Space::PAUSED]);
        $this->ticket($active, 'SUP-1');
        $this->ticket($active, 'SUP-2', left: true);
        $this->ticket($paused, 'OLD-1');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Overview', true)
            ->has('tickets', 1)
            ->where('tickets.0.key', 'SUP-1')
            ->where('tickets.0.url', 'https://example.atlassian.net/browse/SUP-1')
            ->where('tickets.0.group', 'not-analysed'));
    }

    public function test_tickets_waiting_on_the_requester_sleep_and_every_status_has_a_colour(): void
    {
        $space = SpacesTest::space(['state' => Space::ACTIVE, 'status_colours' => ['To Do' => 'teal']]);
        $this->ticket($space, 'SUP-1', status: 'Waiting for customer', category: 'undefined');
        $this->ticket($space, 'SUP-2', status: 'To Do');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('tickets', fn ($tickets) => $tickets->firstWhere('key', 'SUP-1')['group'] === 'sleeping'
                && $tickets->firstWhere('key', 'SUP-1')['status_tone'] === 'amber'
                && $tickets->firstWhere('key', 'SUP-2')['group'] === 'not-analysed'
                && $tickets->firstWhere('key', 'SUP-2')['status_tone'] === 'teal'));
    }

    public function test_without_spaces_it_says_where_to_start(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('hasSpaces', false)->has('tickets', 0));
    }
}
