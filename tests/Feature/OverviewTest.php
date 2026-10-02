<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    private function ticket(Space $space, string $key, bool $left = false): Ticket
    {
        return Ticket::create([
            'space_id' => $space->id, 'jira_id' => $key, 'key' => $key, 'summary' => "About {$key}",
            'first_seen_at' => now(), 'last_seen_at' => now(), 'left_at' => $left ? now() : null,
        ]);
    }

    public function test_it_lists_the_open_tickets_of_active_spaces_only(): void
    {
        config(['services.jira.base' => 'https://example.atlassian.net']);
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

    public function test_without_spaces_it_says_where_to_start(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('hasSpaces', false)->has('tickets', 0));
    }
}
