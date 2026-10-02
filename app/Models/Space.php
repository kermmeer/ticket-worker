<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Jira space this tool looks at, and the rules for which of its tickets count
 * (CONCEPT.md §4).
 */
class Space extends Model
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const SERVICE = 'service';

    public const PLAIN = 'plain';

    /** Label colours, each defined for Day and Night in app.css. */
    public const COLOURS = ['teal', 'violet', 'rose', 'sky', 'olive', 'slate'];

    public const DEFAULT_DONE_RULE = 'statusCategory = Done';

    /** Status label colours, each readable on both themes (app.css, --tone-*). */
    public const TONES = ['grey', 'blue', 'amber', 'green', 'rose', 'violet', 'teal'];

    protected $fillable = [
        'project_key', 'project_id', 'name', 'label', 'colour', 'jira_type', 'type',
        'rules', 'done_rule', 'status_colours', 'sleep_statuses',
        'state', 'sync_attempted_at', 'synced_at', 'sync_error',
    ];

    protected function casts(): array
    {
        return [
            'sync_attempted_at' => 'datetime',
            'synced_at' => 'datetime',
            'status_colours' => 'array',
            'sleep_statuses' => 'array',
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function systems(): BelongsToMany
    {
        return $this->belongsToMany(System::class);
    }

    /** What Jira's project type means here: a service space, or a plain one. */
    public static function typeFromJira(string $projectType): string
    {
        return $projectType === 'service_desk' ? self::SERVICE : self::PLAIN;
    }

    /** The colour of a status's label: yours if you chose one, otherwise one that fits. */
    public function statusTone(?string $status, ?string $category): string
    {
        $chosen = ($this->status_colours ?? [])[$status] ?? null;

        return in_array($chosen, self::TONES, true) ? $chosen : self::defaultTone($status, $category);
    }

    /**
     * Jira's own grouping (to do, in progress, done) gives several statuses the same
     * colour, so the name decides first: who the ticket is waiting for matters most.
     */
    public static function defaultTone(?string $status, ?string $category): string
    {
        $name = mb_strtolower((string) $status);

        return match (true) {
            self::looksAsleep($status) => 'amber',
            str_contains($name, 'waiting for support') || str_contains($name, 'escalat') => 'rose',
            str_contains($name, 'on hold') || str_contains($name, 'blocked') => 'violet',
            $category === 'done' => 'green',
            $category === 'indeterminate' => 'blue',
            default => 'grey',
        };
    }

    /** Whether a ticket in this status sleeps: it waits on the requester, not on you. */
    public function sleeps(?string $status): bool
    {
        return $this->sleep_statuses === null
            ? self::looksAsleep($status)
            : in_array($status, $this->sleep_statuses, true);
    }

    /** The automatic choice, until a space has its own list. */
    public static function looksAsleep(?string $status): bool
    {
        return (bool) preg_match('/\b(waiting for|awaiting|pending)( the)? (customer|reporter|requester)\b/i', (string) $status);
    }

    /** The query a sync runs: the space, its rules, minus what counts as done. */
    public function jql(): string
    {
        return self::composeJql($this->project_key, $this->rules, $this->done_rule);
    }

    /** $ordered = false for counting, where an ORDER BY means nothing. */
    public static function composeJql(string $projectKey, ?string $rules, ?string $doneRule, bool $ordered = true): string
    {
        $parts = ['project = "'.str_replace('"', '', $projectKey).'"'];

        if (filled($rules)) {
            $parts[] = '('.trim($rules).')';
        }

        if (filled($doneRule)) {
            $parts[] = 'NOT ('.trim($doneRule).')';
        }

        return implode(' AND ', $parts).($ordered ? ' ORDER BY updated DESC' : '');
    }
}
