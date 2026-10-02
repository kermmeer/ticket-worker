<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PagesTest extends TestCase
{
    public function test_the_overview_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Overview', true));
    }

    public function test_the_design_specimen_renders(): void
    {
        $this->get('/design')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Design', true));
    }

    public function test_the_theme_is_applied_before_anything_is_painted(): void
    {
        $html = $this->get('/')->getContent();

        // In <head>, ahead of the stylesheet, or Night flashes Day on every load.
        $script = strpos($html, "localStorage.getItem('theme')");
        $this->assertNotFalse($script);
        $this->assertLessThan(strpos($html, '</head>'), $script);
        $this->assertLessThan(strpos($html, '<title'), $script);
    }

    public function test_every_page_says_which_environment_it_is_in(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('app.env', app()->environment()));
    }
}
