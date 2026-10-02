<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A Jira ticket as the last sync saw it. Jira stays the truth; this is what the
 * overview lists without asking Jira on every page load.
 */
class Ticket extends Model
{
    protected $fillable = [
        'space_id', 'jira_id', 'key', 'summary', 'status', 'status_category', 'priority',
        'issue_type', 'reporter', 'assignee', 'jira_created_at', 'jira_updated_at',
        'first_seen_at', 'last_seen_at', 'left_at',
    ];

    protected function casts(): array
    {
        return [
            'jira_created_at' => 'datetime',
            'jira_updated_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /** Still matching its space's rules at the last sync. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('left_at');
    }

    /** Store one issue from a search result, as seen now. */
    public static function record(Space $space, array $issue, CarbonInterface $now): self
    {
        $fields = $issue['fields'] ?? [];

        $ticket = static::firstOrNew(['jira_id' => (string) $issue['id']]);
        $ticket->fill([
            'space_id' => $space->id,
            'key' => (string) $issue['key'],
            'summary' => Str::limit((string) ($fields['summary'] ?? ''), 990),
            'status' => $fields['status']['name'] ?? null,
            'status_category' => $fields['status']['statusCategory']['key'] ?? null,
            'priority' => $fields['priority']['name'] ?? null,
            'issue_type' => $fields['issuetype']['name'] ?? null,
            'reporter' => $fields['reporter']['displayName'] ?? null,
            'assignee' => $fields['assignee']['displayName'] ?? null,
            'jira_created_at' => isset($fields['created']) ? Carbon::parse($fields['created']) : null,
            'jira_updated_at' => isset($fields['updated']) ? Carbon::parse($fields['updated']) : null,
            'last_seen_at' => $now,
            'left_at' => null,
        ]);
        $ticket->first_seen_at ??= $now;
        $ticket->save();

        return $ticket;
    }
}
