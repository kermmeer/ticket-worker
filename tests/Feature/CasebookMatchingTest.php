<?php

namespace Tests\Feature;

use App\Casebook\Matcher;
use App\Models\CasebookEntry;
use Tests\TestCase;

class CasebookMatchingTest extends TestCase
{
    public function test_words_most_tickets_share_are_no_evidence(): void
    {
        $case = CasebookEntry::create(['title' => 'Contract moved to the billing platform: services cannot be priced', 'state' => CasebookEntry::APPROVED,
            'symptoms' => 'After moving a contract the services under it show no price and no invoice is made.',
            'solution' => 'x', 'keywords' => 'contract migration pricing invoice priced services boss']);

        // A space where nearly every ticket mentions boss, services and status.
        $corpus = [];
        foreach (range(1, 40) as $n) {
            $corpus[] = "Number {$n} stuck on status pending in boss services inventory";
        }
        $corpus[] = 'Contract migration done but services cannot be priced: no invoice for the migrated contract';
        $matcher = new Matcher([$case], $corpus);

        $this->assertNull($matcher->best('COM-ONE numbers stuck on status pending release in boss services'), 'Only everyday words in common.');
        $this->assertSame($case->id, $matcher->best('After the contract migration the services cannot be priced, so no invoice')['id'] ?? null);
    }
}
