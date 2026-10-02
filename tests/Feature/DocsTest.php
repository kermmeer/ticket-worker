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
                // CONCEPT.md links to its sections as GitHub names them.
                ->where('html', fn (string $html) => str_contains($html, 'id="17-open-questions"')
                    && str_contains($html, 'href="#17-open-questions"')));
    }

    public function test_only_known_documents_are_served(): void
    {
        $this->get('/docs/README')->assertNotFound();
        $this->get('/docs/composer.json')->assertNotFound();
        $this->get('/docs/..%2F.env')->assertNotFound();
    }
}
