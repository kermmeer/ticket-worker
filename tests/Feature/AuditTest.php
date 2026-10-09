<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\System;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditTest extends TestCase
{
    public function test_setup_changes_are_written_down_and_secrets_only_as_changed(): void
    {
        $this->post('/hyper');
        $this->put('/setup/reply-rules', ['rules' => '- Short.']);
        $this->post('/systems', ['kind' => 'api', 'name' => 'boss-api', 'base_url' => 'https://boss.example.com', 'auth' => 'bearer', 'secret' => 'tok3n-secret']);
        $system = System::firstWhere('name', 'boss-api');
        $api = $system->apis()->first();
        $this->put("/systems/{$system->id}/apis/{$api->id}", ['name' => 'api', 'base_url' => 'https://boss.example.com', 'auth' => 'bearer', 'secret' => 'n3w-secret']);

        $this->assertSame(['hyper', 'setup.reply-rules', 'project.create', 'api.update'], AuditEvent::orderBy('id')->pluck('action')->all());
        $this->assertStringContainsString('secret (changed)', AuditEvent::firstWhere('action', 'api.update')->summary);
        $this->assertFalse(AuditEvent::query()->where('summary', 'like', '%secret%')->where('summary', 'like', '%n3w%')->exists());

        $this->get('/audit')->assertInertia(fn (Assert $page) => $page->component('Audit', true)->where('events.0.who', 'Test Person'));
    }
}
