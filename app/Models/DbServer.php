<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PDO;

/**
 * A database server with one read-only login, shared by every project database on it: set
 * the login once, not once per database. The password stays here, encrypted.
 */
class DbServer extends Model
{
    protected $fillable = ['name', 'driver', 'host', 'port', 'username', 'password', 'notes', 'ssh_host', 'ssh_port', 'ssh_user'];

    protected $hidden = ['password', 'ssh_key'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'port' => 'integer', 'ssh_key' => 'encrypted', 'ssh_port' => 'integer'];
    }

    /** Reached through a jump host over SSH, rather than directly. */
    public function tunnelled(): bool
    {
        return filled($this->ssh_host) && filled($this->ssh_user);
    }

    /**
     * Where to connect: the server itself, or the local end of its SSH tunnel.
     *
     * @return array{host: string, port: ?int}
     */
    public function endpoint(): array
    {
        return $this->tunnelled() ? \App\Support\SshTunnel::to($this) : ['host' => (string) $this->host, 'port' => $this->port];
    }

    public function databases(): HasMany
    {
        return $this->hasMany(DbConnection::class);
    }

    /** A connection to one of its databases, or to the server itself for listing them. */
    public static function open(string $driver, string $host, ?int $port, ?string $database, ?string $username, ?string $password): PDO
    {
        $port = $port ?: ($driver === 'pgsql' ? 5432 : 3306);
        $dsn = $driver === 'pgsql'
            ? "pgsql:host={$host};port={$port};dbname=".($database ?: 'postgres').';connect_timeout=10'
            : "mysql:host={$host};port={$port};".($database ? "dbname={$database};" : '').'charset=utf8mb4';

        return new PDO($dsn, (string) $username, (string) $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * The databases its login can see, for picking one instead of typing it.
     *
     * @return list<string>
     */
    public function listDatabases(): array
    {
        $at = $this->endpoint();
        $pdo = self::open($this->driver, $at['host'], $at['port'], null, $this->username, $this->password);
        $names = $this->driver === 'pgsql'
            ? $pdo->query('SELECT datname FROM pg_database WHERE NOT datistemplate ORDER BY datname')->fetchAll(PDO::FETCH_COLUMN)
            : $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);

        // The server's own bookkeeping is no project's data.
        return array_values(array_diff($names, ['information_schema', 'mysql', 'performance_schema', 'sys', 'postgres']));
    }

    public function redact(string $text): string
    {
        return filled($this->password) && strlen($this->password) >= 4 ? str_replace($this->password, '[secret]', $text) : $text;
    }
}
