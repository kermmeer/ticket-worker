<?php

namespace Tests\Feature;

use App\Models\System;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemTroubleTest extends TestCase
{
    public function test_what_failed_in_the_systems_shows_for_those_who_can_fix_it(): void
    {
        config(['agent.systems_path' => sys_get_temp_dir().'/tw-trouble-'.uniqid()]);
        $fine = System::create(['name' => 'fine', 'branch' => 'main', 'state' => System::READY]);
        $boss = System::create(['name' => 'boss', 'branch' => 'main', 'state' => System::READY, 'scan_state' => 'failed']);
        $boss->apis()->create(['name' => 'crm-api', 'base_url' => 'https://crm.example.com', 'auth' => 'none', 'access' => 'read', 'docs_state' => 'failed']);
        auth()->user()->update(['role' => 'editor']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('systemTrouble', [
            ['id' => $boss->id, 'name' => 'boss', 'what' => ['the scan failed', 'the docs of crm-api failed']],
        ]));

        auth()->user()->update(['role' => 'member', 'is_admin' => false]);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('systemTrouble', []));

        $boss->update(['scan_state' => 'idle']);
        $boss->apis()->update(['docs_state' => 'ready']);
        $this->assertSame([], System::trouble());
        $this->assertNotNull($fine);
    }
}
