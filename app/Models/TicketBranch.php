<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A ticket's code on one system: the branch, and how much it changes against its base. */
class TicketBranch extends Model
{
    protected $fillable = ['ticket_id', 'system_id', 'name', 'base', 'head', 'commits', 'added', 'removed', 'files'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    /** What the page shows: your clones reach the systems here as the "ticket-worker" remote. */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'system' => $this->system?->name,
            'name' => $this->name,
            'base' => $this->base,
            'head' => $this->head ? substr($this->head, 0, 10) : null,
            'commits' => $this->commits,
            'added' => $this->added,
            'removed' => $this->removed,
            'files' => $this->files,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'fetch' => "git fetch ticket-worker {$this->name} && git checkout {$this->name}",
        ];
    }
}
