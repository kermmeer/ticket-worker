<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One round in a session: your message, the agent working, its answer. */
class AgentTurn extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const STOPPED = 'stopped';

    protected $fillable = [
        'agent_session_id', 'kind', 'prompt', 'state', 'stop_requested', 'proposal', 'case_draft', 'answer',
        'error', 'cost_usd', 'input_tokens', 'cache_read_tokens', 'cache_write_tokens', 'output_tokens',
        'duration_ms', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'proposal' => 'array',
            'case_draft' => 'array',
            'stop_requested' => 'boolean',
            'cost_usd' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** Everything it read, fresh or from the cache. */
    public function tokensIn(): ?int
    {
        return $this->input_tokens === null ? null
            : $this->input_tokens + (int) $this->cache_read_tokens + (int) $this->cache_write_tokens;
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class, 'agent_session_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class)->orderBy('id');
    }
}
