<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Claude conversation tied to one ticket, open until you close it (CONCEPT.md §8). */
class AgentSession extends Model
{
    protected $fillable = ['ticket_id', 'claude_session_id', 'state', 'reply_language', 'model', 'cost_usd'];

    protected function casts(): array
    {
        return ['cost_usd' => 'float'];
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
