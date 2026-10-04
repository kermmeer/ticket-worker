<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages render without a Vite build, so the suite never needs node.
        $this->withoutVite();

        // Tests never reach Jira: a request nobody faked fails the test instead.
        Http::preventStrayRequests();

        // This machine's .env points at a real outbox; a test that wants one says so.
        config(['services.outbox.url' => null, 'services.outbox.token' => null, 'agent.claude.oauth_token' => null]);
    }
}
