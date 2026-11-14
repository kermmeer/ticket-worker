<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change the agent proposed through an API that allows changes after approval: it waits
 * here until a person runs it or refuses it, and the agent hears which on its next turn.
 */
class ApiCall extends Model
{
    public const WAITING = 'waiting';

    protected $fillable = ['ticket_id', 'agent_turn_id', 'api_connection_id', 'method', 'path', 'query', 'body', 'reason', 'state', 'status', 'response', 'decided_by', 'decided_at', 'reported_at'];

    protected function casts(): array
    {
        return ['query' => 'array', 'body' => 'array', 'decided_at' => 'datetime', 'reported_at' => 'datetime'];
    }

    public function api(): BelongsTo
    {
        return $this->belongsTo(ApiConnection::class, 'api_connection_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** What the page shows of it. */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'api' => $this->api ? $this->api->system?->name.'/'.$this->api->name : '?',
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'body' => $this->body,
            'reason' => $this->reason,
            'state' => $this->state,
            'status' => $this->status,
            'response' => $this->response,
            'decided_by' => $this->decider?->firstName(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** "change #3, PUT /orders/12 on boss/ordering-api": in the agent's words. */
    public function label(): string
    {
        return "change #{$this->id}, {$this->method} {$this->path} on ".($this->api ? $this->api->system?->name.'/'.$this->api->name : 'its API');
    }
}
