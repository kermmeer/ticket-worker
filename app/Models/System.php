<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Process;

/**
 * A codebase tickets can be about. Ticket Worker cannot reach the Git servers these
 * live on, so you push the code into its folder yourself; the folder is a checkout
 * that every push to the system's branch updates (CONCEPT.md §4).
 */
class System extends Model
{
    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $fillable = [
        'name', 'branch', 'state', 'error', 'context', 'context_commit', 'context_written_at',
        'scan_state', 'scan_stop_requested', 'scan_log', 'scan_error', 'scan_cost_usd',
        'scan_input_tokens', 'scan_output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'context_written_at' => 'datetime',
            'scan_stop_requested' => 'boolean',
            'scan_cost_usd' => 'float',
        ];
    }

    public function contexts(): HasMany
    {
        return $this->hasMany(SystemContext::class)->orderByDesc('id');
    }

    /** Keep a new context, and the version it replaces. */
    public function writeContext(string $body, ?string $commit, string $by): void
    {
        $this->update(['context' => $body, 'context_commit' => $commit, 'context_written_at' => now()]);
        $this->contexts()->create(['body' => $body, 'commit' => $commit, 'written_by' => $by]);
    }

    /** The commit the folder is at now, full hash, or null before the first push. */
    public function headCommit(): ?string
    {
        $result = Process::path($this->path())->run(['git', '-c', 'safe.directory=*', 'rev-parse', 'HEAD']);

        return $result->successful() ? trim($result->output()) : null;
    }

    /** How many commits the code has moved since the context was written; null when unknown. */
    public function commitsBehind(): ?int
    {
        if ($this->context_commit === null || ! is_dir($this->path().'/.git')) {
            return null;
        }

        $result = Process::path($this->path())->run(['git', '-c', 'safe.directory=*', 'rev-list', '--count', $this->context_commit.'..HEAD']);

        return $result->successful() ? (int) trim($result->output()) : null;
    }

    public function spaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class);
    }

    public function path(): string
    {
        return config('agent.systems_path').'/'.$this->name;
    }

    /** The address to push to from your own machine, when SYSTEMS_PUSH_BASE says where this is. */
    public function pushRemote(): ?string
    {
        $base = config('agent.systems_push_base');

        return filled($base) ? rtrim($base, '/').'/'.$this->name : null;
    }

    /**
     * The last commit pushed, or null while nothing has been.
     *
     * @return array{hash: string, committed_at: string, subject: string}|null
     */
    public function head(): ?array
    {
        if (! is_dir($this->path().'/.git')) {
            return null;
        }

        // php-fpm runs as another user than the one that owns the folder, and git
        // refuses to read a repository it does not own unless told it is safe.
        $result = Process::path($this->path())->run([
            'git', '-c', 'safe.directory=*', 'log', '-1', '--format=%h%x09%cI%x09%s', 'HEAD',
        ]);

        $line = trim($result->output());

        if (! $result->successful() || $line === '') {
            return null;
        }

        [$hash, $committedAt, $subject] = array_pad(explode("\t", $line, 3), 3, '');

        return ['hash' => $hash, 'committed_at' => $committedAt, 'subject' => $subject];
    }
}
