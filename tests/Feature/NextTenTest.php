<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\Space;
use App\Models\Ticket;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NextTenTest extends TestCase
{
    public function test_your_next_ten_in_order_with_their_reasons_and_never_sleeping_or_done(): void
    {
        auth()->user()->update(['name' => 'Test Person', 'jira_name' => 'Test Person']);
        $space = SpacesTest::space(['state' => Space::ACTIVE]);
        $make = fn (string $key, array $attributes = []) => Ticket::create($attributes + ['space_id' => $space->id, 'jira_id' => $key, 'key' => $key, 'summary' => $key,
            'status' => 'Open', 'first_seen_at' => now(), 'last_seen_at' => now(), 'jira_updated_at' => now()->subDays(2)]);
        $sla = fn (int $minutes) => [['name' => 'Time to resolution', 'state' => 'running', 'paused' => false, 'remaining_ms' => $minutes * 60000, 'goal_ms' => 0, 'due_at' => now()->addMinutes($minutes)->toIso8601String()]];

        // An answer waiting: a chat of yours, answered after your last look.
        $answered = $make('SUP-2', ['jira_updated_at' => now()->subDays(5)]);
        $session = AgentSession::create(['ticket_id' => $answered->id, 'user_id' => auth()->id(), 'claude_session_id' => '6f1c7e2a-0000-4000-8000-0000000000e9', 'model' => 'claude-opus-5']);
        AgentTurn::create(['agent_session_id' => $session->id, 'kind' => 'message', 'prompt' => 'x', 'state' => AgentTurn::DONE, 'finished_at' => now()->subDays(3)]);
        // New activity: assigned to you, looked at, changed since.
        $changed = $make('SUP-1', ['assignee' => 'Test Person']);
        \Illuminate\Support\Facades\DB::table('ticket_views')->insert(['user_id' => auth()->id(), 'ticket_id' => $changed->id, 'seen_at' => now()->subDays(3)]);
        $make('SUP-3', ['assignee' => 'Test Person', 'slas' => $sla(30)]);
        $make('SUP-4', ['slas' => $sla(10)]); // nobody's, due sooner, but after yours
        $make('SUP-5', ['assignee' => 'Someone Else', 'slas' => $sla(5)]); // someone else's: not yours to pick up
        $make('SUP-6', ['assignee' => 'Test Person', 'priority' => 'High']);
        $make('SUP-7', ['assignee' => 'Test Person', 'priority' => 'High', 'status' => 'Waiting for customer']); // sleeping
        $make('SUP-8', ['assignee' => 'Test Person', 'priority' => 'High', 'done_at' => now(), 'done_how' => 'reply']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('nextTen', fn ($next) => collect($next)->map(fn ($entry) => Ticket::find($entry['id'])->key)->all() === ['SUP-1', 'SUP-2', 'SUP-3', 'SUP-4', 'SUP-6']
            && $next[0]['reason'] === 'Changed in Jira since you last looked'
            && $next[1]['reason'] === 'The agent answered since you last looked'
            && $next[2]['reason'] === 'SLA due in 30 min'
            && $next[3]['reason'] === 'SLA due in 10 min, assigned to nobody'
            && $next[4]['reason'] === 'High priority, yours'));
    }
}
