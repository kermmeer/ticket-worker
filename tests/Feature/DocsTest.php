<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocsTest extends TestCase
{
    public function test_the_concept_renders_with_its_own_links_working(): void
    {
        $this->get('/docs/concept')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Docs', true)
                ->where('title', 'Ticket Worker: concept')
                ->where('html', function (string $html) {
                    // CONCEPT.md links to its own sections as GitHub names them; every such
                    // link must land on a heading here too.
                    preg_match_all('/href="#([^"]+)"/', $html, $links);
                    $this->assertNotEmpty($links[1]);
                    foreach (array_unique($links[1]) as $anchor) {
                        $this->assertStringContainsString('id="'.$anchor.'"', $html, "No heading for #{$anchor}");
                    }

                    return true;
                }));
    }

    public function test_only_known_documents_are_served(): void
    {
        $this->get('/docs/README')->assertNotFound();
        $this->get('/docs/composer.json')->assertNotFound();
        $this->get('/docs/..%2F.env')->assertNotFound();
    }
}
