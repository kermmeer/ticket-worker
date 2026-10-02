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

    protected $fillable = [
        'project_key', 'project_id', 'name', 'label', 'colour', 'jira_type', 'type',
        'rules', 'done_rule', 'state', 'sync_attempted_at', 'synced_at', 'sync_error',
    ];

    protected function casts(): array
    {
        return [
            'sync_attempted_at' => 'datetime',
            'synced_at' => 'datetime',
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
