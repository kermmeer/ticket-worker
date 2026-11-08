<?php

namespace Tests\Feature;

use App\Models\DbServer;
use App\Support\SshTunnel;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SshTunnelTest extends TestCase
{
    private string $bin;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        // A stand-in ssh first on the PATH, in a folder of its own.
        $this->bin = sys_get_temp_dir().'/tw-fake-ssh-'.uniqid();
        mkdir($this->bin);
        symlink(base_path('tests/fixtures/fake-ssh'), $this->bin.'/ssh');
        $this->path = getenv('PATH');
        putenv("PATH={$this->bin}:{$this->path}");
        $this->env('FAKE_SSH_ARGS', $this->bin.'/args.json');
    }

    protected function tearDown(): void
    {
        SshTunnel::closeAll();
        putenv("PATH={$this->path}");
        putenv('FAKE_SSH_FAIL');
        unset($_SERVER['FAKE_SSH_FAIL'], $_SERVER['FAKE_SSH_ARGS']);
        array_map('unlink', glob($this->bin.'/*'));
        rmdir($this->bin);
        parent::tearDown();
    }

    /** Symfony's Process passes on only variables $_SERVER knows of. */
    private function env(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_SERVER[$name] = $value;
    }

    private function server(array $attributes = []): DbServer
    {
        return DbServer::create($attributes + [
            'name' => 'prod-mysql', 'driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'username' => 'reader', 'password' => 'secret',
            'ssh_host' => 'jump.example', 'ssh_user' => 'tunnel',
        ])->forceFill(['ssh_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----"]);
    }

    public function test_queries_go_to_a_local_port_that_leads_through_the_jump_host_and_its_key_is_pinned(): void
    {
        $server = $this->server();
        $server->save();

        $at = $server->endpoint();

        $this->assertSame('127.0.0.1', $at['host']);
        $socket = @fsockopen('127.0.0.1', $at['port'], $errno, $error, 1);
        $this->assertNotFalse($socket, 'The local end answers.');
        $args = json_decode(file_get_contents($this->bin.'/args.json'), true);
        $this->assertContains("127.0.0.1:{$at['port']}:db.internal:3306", $args);
        $this->assertContains('tunnel@jump.example', $args);
        $this->assertContains('StrictHostKeyChecking=accept-new', $args);
        $this->assertSame('jump.example ssh-ed25519 AAAAFAKEHOSTKEY', $server->fresh()->ssh_known_hosts, 'Pinned at first contact.');
        $this->assertSame($at, $server->endpoint(), 'One tunnel per server, shared by the queries.');
    }

    public function test_what_ssh_says_when_it_cannot_get_in_comes_back(): void
    {
        $this->env('FAKE_SSH_FAIL', 'tunnel@jump.example: Permission denied (publickey).');
        $server = $this->server();
        $server->save();

        $this->expectExceptionMessage('Permission denied (publickey)');
        $server->endpoint();
    }

    public function test_a_server_without_a_tunnel_is_reached_directly_and_another_jump_host_unpins_its_key(): void
    {
        $plain = $this->server(['name' => 'local', 'ssh_host' => null, 'ssh_user' => null]);
        $plain->save();
        $this->assertSame(['host' => 'db.internal', 'port' => 3306], $plain->endpoint());

        $server = $this->server();
        $server->forceFill(['ssh_known_hosts' => 'jump.example ssh-ed25519 OLD'])->save();
        $form = ['name' => 'prod-mysql', 'driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'username' => 'reader', 'ssh_user' => 'tunnel'];
        $this->put("/systems/db-servers/{$server->id}", $form + ['ssh_host' => 'jump.example'])->assertSessionHasNoErrors();
        $this->assertNotNull($server->fresh()->ssh_known_hosts, 'Same jump host: still pinned.');
        $this->put("/systems/db-servers/{$server->id}", $form + ['ssh_host' => 'other-jump.example'])->assertSessionHasNoErrors();
        $this->assertNull($server->fresh()->ssh_known_hosts);

        $this->get('/systems')->assertInertia(fn (Assert $page) => $page
            ->where('dbServers', fn ($servers) => collect($servers)->firstWhere('name', 'prod-mysql')['ssh_host'] === 'other-jump.example'
                && ! array_key_exists('ssh_key', collect($servers)->firstWhere('name', 'prod-mysql'))));
    }
}
