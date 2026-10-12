<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DbConnection;
use App\Models\DbServer;
use App\Models\System;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DbServerTest extends TestCase
{
    public function test_one_login_on_the_server_serves_every_database_on_it(): void
    {
        $this->post('/systems/db-servers', ['name' => 'main-mysql', 'driver' => 'mysql', 'host' => 'db.internal', 'username' => 'reader', 'password' => 'r3ad-only-secret'])->assertSessionHasNoErrors();
        $server = DbServer::sole();
        $this->assertStringNotContainsString('r3ad-only-secret', (string) DB::table('db_servers')->value('password'));

        $billing = System::create(['name' => 'billing', 'branch' => 'main', 'state' => System::READY]);
        $crm = System::create(['name' => 'crm', 'branch' => 'main', 'state' => System::READY]);
        $this->post("/systems/{$billing->id}/databases", ['name' => 'db', 'db_server_id' => $server->id, 'database' => 'billing_prod'])->assertSessionHasNoErrors();
        $this->post("/systems/{$crm->id}/databases", ['name' => 'db', 'db_server_id' => $server->id, 'database' => 'crm_prod'])->assertSessionHasNoErrors();

        foreach (DbConnection::with('server')->get() as $db) {
            $this->assertNull($db->getAttributes()['password'], 'No login of its own.');
            $this->assertSame(['driver' => 'mysql', 'host' => 'db.internal', 'port' => null, 'username' => 'reader', 'password' => 'r3ad-only-secret'], $db->login());
        }
        $this->assertSame('billing_prod on server main-mysql', AuditEvent::where('action', 'db.create')->orderBy('id')->value('summary'));
        $this->assertFalse(str_contains($this->get('/systems')->getContent(), 'r3ad-only-secret'));

        // In use: not removed.
        $this->delete("/systems/db-servers/{$server->id}")->assertStatus(409);
    }

    public function test_the_password_does_not_follow_the_server_to_another_host(): void
    {
        $server = DbServer::create(['name' => 'main-mysql', 'driver' => 'mysql', 'host' => 'db.internal', 'username' => 'reader', 'password' => 'r3ad-only-secret']);

        $this->put("/systems/db-servers/{$server->id}", ['name' => 'main-mysql', 'driver' => 'mysql', 'host' => 'db.internal', 'username' => 'reader', 'notes' => 'all prod databases'])->assertSessionHasNoErrors();
        $this->assertSame('r3ad-only-secret', $server->fresh()->password, 'Same host and user: kept.');

        $this->put("/systems/db-servers/{$server->id}", ['name' => 'main-mysql', 'driver' => 'mysql', 'host' => 'evil.example.com', 'username' => 'reader'])->assertSessionHasNoErrors();
        $this->assertNull($server->fresh()->password);
    }

    public function test_members_do_not_manage_servers(): void
    {
        auth()->user()->update(['role' => 'member']);

        $this->post('/systems/db-servers', ['name' => 'x', 'driver' => 'mysql', 'host' => 'h', 'username' => 'u'])->assertForbidden();
    }
}
