<?php

namespace Tests\Feature;

use App\Agent\Instructions;
use App\Models\AgentSession;
use App\Models\Setting;
use Tests\TestCase;

class BrevityTest extends TestCase
{
    public function test_a_proposal_leads_with_one_line_and_keeps_the_rest_short(): void
    {
        $schema = Instructions::proposalSchema();

        $this->assertSame('headline', $schema['required'][0]);
        $this->assertStringContainsString('under 140 characters', $schema['properties']['headline']['description']);
        $this->assertStringContainsString('At most 5', $schema['properties']['evidence']['description']);
        $this->assertStringContainsString('At most 3', $schema['properties']['commands']['description']);
    }

    public function test_agents_work_silently_and_answer_short(): void
    {
        $this->assertStringContainsString('Work silently', Instructions::system(new AgentSession(['reply_language' => 'auto']), []));
        $this->assertStringContainsString('at most about 120 words', Setting::DEFAULT_ANSWER_RULES);
        $this->assertStringContainsString('under 200 characters', Instructions::quickScanSchema()['properties']['likely_cause']['description']);
    }
}
