<?php

namespace Tests\Feature;

use App\Models\Setting;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentPoolTest extends TestCase
{
    public function test_how_many_agents_at_once_is_set_on_setup_within_bounds(): void
    {
        config(['agent.max_parallel' => 2]);
        $this->assertSame(2, Setting::agents(), 'AGENT_MAX_PARALLEL until Setup says otherwise.');

        $this->put('/setup/agents', ['agents' => 6])->assertSessionHasNoErrors();
        $this->assertSame(6, Setting::agents());
        $this->put('/setup/agents', ['agents' => 40])->assertSessionHasErrors('agents');
        $this->put('/setup/agents', ['agents' => 0])->assertSessionHasErrors('agents');

        $this->get('/agents')->assertInertia(fn (Assert $page) => $page->where('capacity', 6));
        $this->get('/setup')->assertInertia(fn (Assert $page) => $page->where('agents', 6));
    }
}
