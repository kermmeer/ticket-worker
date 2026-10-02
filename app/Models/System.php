<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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

    protected $fillable = ['name', 'branch', 'state', 'error'];

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
