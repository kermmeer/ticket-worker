<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_without_a_password_the_app_is_open(): void
    {
        config(['app.password' => null]);

        $this->get('/design')->assertOk();
        $this->get('/login')->assertRedirect('/');
    }

    public function test_with_a_password_every_page_asks_for_it_first(): void
    {
        config(['app.password' => 'open sesame']);

        $this->get('/design')->assertRedirect('/login');
        $this->post('/hyper')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Login', true));
    }

    public function test_the_right_password_lets_you_in_and_back_where_you_were(): void
    {
        config(['app.password' => 'open sesame']);

        $this->get('/design');
        $this->post('/login', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->get('/design')->assertRedirect('/login');

        $this->post('/login', ['password' => 'open sesame'])->assertRedirect('/design');
        $this->get('/design')->assertOk()->assertInertia(fn (Assert $page) => $page->where('app.gate', true));

        $this->post('/logout')->assertRedirect('/login');
        $this->get('/design')->assertRedirect('/login');
    }

    public function test_guessing_is_slowed_down(): void
    {
        config(['app.password' => 'open sesame']);

        foreach (range(1, 6) as $ignored) {
            $this->post('/login', ['password' => 'guess']);
        }

        $this->post('/login', ['password' => 'open sesame'])->assertStatus(429);
    }
}
