<?php

namespace Tests\Feature;

use App\Agent\Instructions;
use Tests\TestCase;

class QuickScanRulesTest extends TestCase
{
    public function test_still_there_is_only_ever_a_lookup_never_the_tickets_word(): void
    {
        $still = Instructions::quickScanSchema()['properties']['data_check']['properties']['still_present'];

        $this->assertStringContainsString('only from a call_api lookup', $still['description']);
    }
}
