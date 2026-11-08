<?php

namespace App\Support;

use App\Models\DbServer;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A local port that leads, through a jump host over SSH, to a database server the tool
 * cannot reach itself (behind a VPN, say). One tunnel per server per process: a turn's many
 * queries share it, and it closes when the process ends.
 *
 * The jump host's own key is pinned the first time (accept-new) and kept with the server:
 * after that a different key is refused, not trusted.
 */
class SshTunnel
{
    /** @var array<int, array{process: Process, port: int, files: list<string>}> */
    private static array $open = [];

    /** @return array{host: string, port: int} where to connect instead of the server itself */
    public static function to(DbServer $server): array
    {
        $open = self::$open[$server->id] ?? null;
        if ($open !== null && $open['process']->isRunning()) {
            return ['host' => '127.0.0.1', 'port' => $open['port']];
        }

        if (blank($server->ssh_key)) {
            throw new RuntimeException('No key for the SSH tunnel yet: make one, and put its public half on the jump host.');
        }

        $dir = sys_get_temp_dir().'/tw-tunnel-'.$server->id.'-'.getmypid();
        @mkdir($dir, 0700, true);
        $key = "{$dir}/key";
        $known = "{$dir}/known_hosts";
        file_put_contents($key, rtrim($server->ssh_key)."\n");
        chmod($key, 0600);
        file_put_contents($known, (string) $server->ssh_known_hosts);

        $port = self::freePort();
        $dbPort = $server->port ?: ($server->driver === 'pgsql' ? 5432 : 3306);
        $process = new Process(self::command($server, $key, $known, $port, $dbPort));
        $process->setTimeout(null);
        $process->start();

        // Up when the local port answers; down when ssh gave up, with what it said.
        $until = microtime(true) + 15;
        while (microtime(true) < $until) {
            if (! $process->isRunning()) {
                self::clean($dir);
                throw new RuntimeException('The SSH tunnel did not open: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.3);
            if ($socket) {
                fclose($socket);
                break;
            }
            usleep(200000);
        }
        if (! isset($socket) || ! $socket) {
            $process->stop(1);
            self::clean($dir);
            throw new RuntimeException('The SSH tunnel did not open within 15 seconds.');
        }

        // The jump host's key, as met now: kept, so the next time a different one is refused.
        if (blank($server->ssh_known_hosts) && filled($seen = @file_get_contents($known))) {
            $server->forceFill(['ssh_known_hosts' => trim($seen)])->saveQuietly();
        }

        self::$open[$server->id] = ['process' => $process, 'port' => $port, 'files' => [$dir]];
        if (count(self::$open) === 1) {
            register_shutdown_function([self::class, 'closeAll']);
        }

        return ['host' => '127.0.0.1', 'port' => $port];
    }

    /** @return list<string> */
    public static function command(DbServer $server, string $key, string $known, int $localPort, int $dbPort): array
    {
        return ['ssh', '-N',
            '-o', 'BatchMode=yes', '-o', 'ExitOnForwardFailure=yes', '-o', 'ConnectTimeout=10',
            '-o', 'StrictHostKeyChecking=accept-new', '-o', "UserKnownHostsFile={$known}", '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'ServerAliveInterval=15', '-o', 'IdentitiesOnly=yes', '-i', $key,
            '-p', (string) ($server->ssh_port ?: 22),
            '-L', "127.0.0.1:{$localPort}:{$server->host}:{$dbPort}",
            "{$server->ssh_user}@{$server->ssh_host}",
        ];
    }

    public static function closeAll(): void
    {
        foreach (self::$open as $id => $open) {
            $open['process']->stop(1);
            foreach ($open['files'] as $dir) {
                self::clean($dir);
            }
            unset(self::$open[$id]);
        }
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        return $port;
    }

    private static function clean(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
