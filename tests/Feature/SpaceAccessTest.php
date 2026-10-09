<?php

namespace Tests\Feature;

use App\Models\Space;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SpaceAccessTest extends TestCase
{
    private Space $mine;

    private Space $theirs;

    private Ticket $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = SpacesTest::space(['state' => Space::ACTIVE, 'label' => 'Mine', 'project_key' => 'MIN']);
        $this->theirs = SpacesTest::space(['state' => Space::ACTIVE, 'label' => 'Theirs', 'project_key' => 'THR']);
        Ticket::create(['space_id' => $this->mine->id, 'jira_id' => '1', 'key' => 'MIN-1', 'summary' => 'x', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->hidden = Ticket::create(['space_id' => $this->theirs->id, 'jira_id' => '2', 'key' => 'THR-1', 'summary' => 'y', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        // A member who sees only "Mine".
        auth()->user()->update(['role' => 'member', 'all_spaces' => false]);
        auth()->user()->spaceAccess()->sync([$this->mine->id]);
    }

    public function test_a_limited_person_sees_only_their_spaces_everywhere(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('spaces', fn ($spaces) => collect($spaces)->pluck('label')->all() === ['Mine'])
            ->where('tickets', fn ($tickets) => collect($tickets)->pluck('key')->all() === ['MIN-1']));
        $this->get('/spaces')->assertInertia(fn (Assert $page) => $page->where('spaces', fn ($spaces) => collect($spaces)->pluck('label')->all() === ['Mine']));

        // Their tickets do not exist for them, however they are asked for.
        $this->get("/tickets/{$this->hidden->id}")->assertNotFound();
        $this->post("/tickets/{$this->hidden->id}/analyse", ['language' => 'auto'])->assertNotFound();
        $this->post("/tickets/{$this->hidden->id}/quick-scan")->assertNotFound();
        $this->get("/tickets/{$this->hidden->id}/attachments/10")->assertNotFound();
        $this->put("/spaces/{$this->theirs->id}/follow", ['follow' => true])->assertNotFound();

        Queue::fake();
        config(['agent.claude.api_key' => 'sk-ant-test']);
        $this->post('/quick-scans', ['ids' => [$this->hidden->id]]);
        $this->assertNull($this->hidden->fresh()->quick_scan_state);
    }

    public function test_an_admin_chooses_on_the_person_or_on_the_space(): void
    {
        $person = auth()->user();
        $admin = User::factory()->create(['username' => 'boss', 'role' => 'admin']);
        $this->actingAs($admin);

        $this->put("/spaces/{$this->theirs->id}/people", ['user_ids' => [$person->id]])->assertRedirect();
        $this->assertTrue($person->fresh()->canSee($this->theirs));

        $this->put("/people/{$person->id}/spaces", ['all_spaces' => true])->assertRedirect();
        $this->assertNull($person->fresh()->visibleSpaceIds());
        $this->assertSame(['space.access', 'person.spaces'], \App\Models\AuditEvent::orderBy('id')->pluck('action')->all());
    }
}
