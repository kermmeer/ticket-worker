<?php

namespace Tests\Feature;

use App\Casebook\Matcher;
use App\Models\CasebookEntry;
use App\Models\Space;
use Tests\TestCase;

class CasebookSpacesTest extends TestCase
{
    public function test_a_case_is_for_every_space_unless_tagged(): void
    {
        $billing = SpacesTest::space(['state' => Space::ACTIVE, 'label' => 'Billing', 'project_key' => 'BIL']);
        $support = SpacesTest::space(['state' => Space::ACTIVE, 'label' => 'Support', 'project_key' => 'SUP']);
        $all = CasebookEntry::create(['title' => 'Everywhere', 'symptoms' => 'invoice twice', 'solution' => 'x', 'state' => CasebookEntry::APPROVED]);
        $only = CasebookEntry::create(['title' => 'Billing only', 'symptoms' => 'invoice twice', 'solution' => 'x', 'state' => CasebookEntry::APPROVED, 'space_ids' => [$billing->id]]);

        $this->assertEqualsCanonicalizing([$all->id, $only->id], CasebookEntry::forSpace($billing->id)->pluck('id')->all());
        $this->assertSame([$all->id], CasebookEntry::forSpace($support->id)->pluck('id')->all());
        $this->assertNotNull(Matcher::forSpace($billing));
    }

    public function test_the_form_saves_the_spaces_and_none_means_all(): void
    {
        $billing = SpacesTest::space(['label' => 'Billing']);

        $this->post('/casebook', ['title' => 'T', 'symptoms' => 's', 'solution' => 'x', 'state' => 'approved', 'space_ids' => [$billing->id]])->assertSessionHasNoErrors();
        $this->post('/casebook', ['title' => 'U', 'symptoms' => 's', 'solution' => 'x', 'state' => 'approved', 'space_ids' => []])->assertSessionHasNoErrors();

        $this->assertSame([$billing->id], CasebookEntry::firstWhere('title', 'T')->space_ids);
        $this->assertNull(CasebookEntry::firstWhere('title', 'U')->space_ids);
    }
}
