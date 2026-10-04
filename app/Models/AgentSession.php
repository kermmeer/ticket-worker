<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Claude conversation tied to one ticket, open until you close it (CONCEPT.md §8). */
class AgentSession extends Model
{
    protected $fillable = ['ticket_id', 'claude_session_id', 'state', 'reply_language', 'model', 'cost_usd', 'closed_at'];

    protected function casts(): array
    {
        return ['cost_usd' => 'float', 'closed_at' => 'datetime'];
    }

    /**
     * What the session did, in one line's worth: no conversation, just the work and its
     * price (shown under Earlier sessions once it is closed).
     */
    public function summary(): array
    {
        $turns = $this->turns()->withCount(['events as steps' => fn ($query) => $query->where('type', 'tool')])->get();
        $proposal = $turns->whereNotNull('proposal')->last()?->proposal;

        return [
            'id' => $this->id,
            'started_at' => ($turns->first()?->started_at ?? $this->created_at)?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'turns' => $turns->count(),
            'questions' => $turns->where('kind', 'message')->count(),
            'steps' => $turns->sum('steps'),
            'minutes' => (int) round($turns->sum('duration_ms') / 60000),
            'cost_usd' => $this->cost_usd,
            'tokens_in' => $turns->sum(fn (AgentTurn $turn) => (int) $turn->tokensIn()),
            'tokens_out' => $turns->sum(fn (AgentTurn $turn) => (int) $turn->output_tokens),
            'case_drafted' => $turns->whereNotNull('case_draft')->isNotEmpty(),
            'system' => $proposal['system'] ?? null,
            'cause' => $proposal['cause'] ?? null,
            'confidence' => $proposal['confidence'] ?? null,
            'model' => $this->model,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function turns(): HasMany
    {
        return $this->hasMany(AgentTurn::class)->orderBy('id');
    }

    public function busy(): bool
    {
        return $this->turns()->whereIn('state', [AgentTurn::QUEUED, AgentTurn::RUNNING])->exists();
    }

    /** The latest proposal in this conversation, if there is one. */
    public function proposal(): ?array
    {
        return $this->turns()->whereNotNull('proposal')->reorder('id', 'desc')->value('proposal');
    }
}
