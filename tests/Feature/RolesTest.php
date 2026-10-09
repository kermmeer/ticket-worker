<?php

namespace Tests\Feature;

use App\Models\CasebookEntry;
use App\Models\Space;
use Tests\TestCase;

class RolesTest extends TestCase
{
    private function as(string $role): void
    {
        auth()->user()->update(['role' => $role]);
    }

    public function test_a_member_works_tickets_but_does_not_change_the_setup(): void
    {
        $this->as('member');
        $space = SpacesTest::space(['state' => Space::ACTIVE]);

        $this->get('/')->assertOk();
        $this->get('/spaces')->assertOk();
        $this->put("/spaces/{$space->id}/follow", ['follow' => true])->assertRedirect();
        $this->get('/casebook')->assertOk();

        $this->get("/spaces/{$space->id}/edit")->assertForbidden();
        $this->get('/systems')->assertForbidden();
        $this->post('/hyper')->assertForbidden();
        $this->get('/setup')->assertForbidden();
        $this->get('/docs/concept')->assertForbidden();
        $this->get('/audit')->assertForbidden();
    }

    public function test_a_members_case_waits_for_an_editor(): void
    {
        $this->as('member');

        $this->post('/casebook', ['title' => 'Invoice twice', 'symptoms' => 's', 'solution' => 'x', 'state' => CasebookEntry::APPROVED])->assertRedirect('/casebook');

        $this->assertSame(CasebookEntry::DRAFT, CasebookEntry::sole()->state);
        $this->assertSame('Test', CasebookEntry::sole()->written_by);
    }

    public function test_an_editor_runs_spaces_and_systems_but_not_people_or_setup(): void
    {
        $this->as('editor');
        $space = SpacesTest::space(['state' => Space::ACTIVE]);

        $this->get('/systems')->assertOk();
        $this->post("/spaces/{$space->id}/pause")->assertRedirect();

        $this->get('/setup')->assertForbidden();
        $this->post('/people', ['username' => 'x', 'name' => 'x'])->assertForbidden();
        $this->get('/design')->assertForbidden();
    }
}
