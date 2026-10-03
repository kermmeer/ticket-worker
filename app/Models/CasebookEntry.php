<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A solved case: the problem as tickets show it, what caused it, what fixed it.
 * Written in general terms: entries outlive the tickets they came from, so they hold
 * no customer's name or data (CONCEPT.md §8 and §11).
 */
class CasebookEntry extends Model
{
    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const RETIRED = 'retired';

    protected $fillable = [
        'system_id', 'title', 'symptoms', 'cause', 'solution', 'keywords',
        'source_tickets', 'state', 'written_by', 'used_count', 'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'source_tickets' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('state', self::APPROVED);
    }
}
