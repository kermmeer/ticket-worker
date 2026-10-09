<?php

namespace App\Models;

use App\Agent\ReadOnlySql;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PDO;

/**
 * A project's database the agent may query, read-only, through the tool (docs/TEAM.md,
 * item 6). The password stays here, encrypted. Give it an account that can only read:
 * the checks here are a second line, not the first.
 */
class DbConnection extends Model
{
    public const DRIVERS = ['mysql' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL'];

    public const MAX_ROWS = 200;

    public const MAX_ANSWER = 60_000;

    public const TIMEOUT_SECONDS = 15;

    protected $fillable = ['system_id', 'name', 'driver', 'host', 'port', 'database', 'username', 'password', 'notes'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'port' => 'integer'];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    public function available(): bool
    {
        return extension_loaded($this->driver === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql');
    }

    /**
     * Run one read-only query and return its rows as text.
     *
     * @return array{rows: int, text: string, truncated: bool}
     */
    public function runQuery(string $sql): array
    {
        $sql = ReadOnlySql::check($sql);
        if (! $this->available()) {
            throw new \RuntimeException("This server has no {$this->driver} driver for PHP (pdo_{$this->driver}).");
        }

        $pdo = $this->connect();
        $ms = self::TIMEOUT_SECONDS * 1000;
        if ($this->driver === 'pgsql') {
            $pdo->exec("SET statement_timeout = {$ms}");
            $pdo->exec('BEGIN READ ONLY');
        } else {
            $pdo->exec("SET SESSION max_execution_time = {$ms}");
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->exec('START TRANSACTION READ ONLY');
        }

        try {
            $statement = $pdo->query($sql);
            $rows = [];
            while (count($rows) < self::MAX_ROWS && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $rows[] = $row;
            }
            $more = $statement->fetch(PDO::FETCH_ASSOC) !== false;
        } finally {
            $pdo->exec('ROLLBACK');
        }

        $text = $rows === [] ? '(no rows)' : implode("\n", array_map(fn ($row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $rows));
        $cut = strlen($text) > self::MAX_ANSWER;

        return [
            'rows' => count($rows),
            'text' => $this->redact(mb_strcut($text, 0, self::MAX_ANSWER)).($more ? "\n[more rows: ask with a WHERE or a LIMIT]" : ''),
            'truncated' => $cut || $more,
        ];
    }

    public function redact(string $text): string
    {
        return filled($this->password) && strlen($this->password) >= 4 ? str_replace($this->password, '[secret]', $text) : $text;
    }

    private function connect(): PDO
    {
        $port = $this->port ?: ($this->driver === 'pgsql' ? 5432 : 3306);
        $dsn = $this->driver === 'pgsql'
            ? "pgsql:host={$this->host};port={$port};dbname={$this->database};connect_timeout=10"
            : "mysql:host={$this->host};port={$port};dbname={$this->database};charset=utf8mb4";

        return new PDO($dsn, $this->username, (string) $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
