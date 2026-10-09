<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to the setup, by whom (docs/TEAM.md, item 11). What changed is said in words,
 * and a secret only ever as "changed", never its value.
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'subject', 'summary'];

    public static function record(string $action, ?string $subject, string $summary): void
    {
        static::create(['user_id' => auth()->id(), 'action' => $action, 'subject' => $subject, 'summary' => $summary]);
    }

    /**
     * What changed between two sets of attributes, in words: "rules, colour". Fields named
     * in $secret say only "secret changed".
     */
    public static function changes(array $before, array $after, array $secret = []): string
    {
        $changed = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) != $value) {
                $changed[] = in_array($key, $secret, true) ? "{$key} (changed)" : $key;
            }
        }

        return $changed === [] ? 'nothing' : implode(', ', $changed);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
