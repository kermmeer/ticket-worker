<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A Jira ticket as the last sync saw it. Jira stays the truth; this is what the
 * overview lists without asking Jira on every page load.
 */
class Ticket extends Model
{
    protected $fillable = [
        'space_id', 'jira_id', 'key', 'summary', 'status', 'status_category', 'priority',
        'issue_type', 'reporter', 'assignee', 'assignee_account_id', 'slas', 'casebook_entry_id', 'casebook_score', 'jira_created_at', 'jira_updated_at',
        'first_seen_at', 'last_seen_at', 'left_at', 'hidden_at',
        'quick_scan_state', 'quick_scan', 'quick_scan_steps', 'quick_scan_error', 'quick_scan_cost_usd', 'quick_scan_tokens', 'quick_scan_at', 'quick_scan_user_id', 'sla_warned',
    ];

    protected function casts(): array
    {
        return [
            'jira_created_at' => 'datetime',
            'jira_updated_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'left_at' => 'datetime',
            'hidden_at' => 'datetime',
            'slas' => 'array',
            'quick_scan' => 'array',
            'quick_scan_steps' => 'array',
            'quick_scan_cost_usd' => 'float',
            'quick_scan_at' => 'datetime',
            'sla_warned' => 'array',
        ];
    }

    /**
     * One line of a quick scan's trail, added in place: the scan itself and the API tool
     * server (another process) both write to it while the scan runs.
     */
    public static function addQuickStep(int $id, string $line): void
    {
        DB::transaction(function () use ($id, $line) {
            $ticket = static::query()->lockForUpdate()->find($id, ['id', 'quick_scan_steps']);
            if ($ticket !== null) {
                $steps = $ticket->quick_scan_steps ?? [];
                $steps[] = mb_strimwidth($line, 0, 300, '…');
                $ticket->forceFill(['quick_scan_steps' => array_slice($steps, -60)])->saveQuietly();
            }
        });
    }

    /** A quick scan as the pages show it. */
    public function quickScanData(): ?array
    {
        if ($this->quick_scan_state === null) {
            return null;
        }

        return [
            'state' => $this->quick_scan_state,
            'result' => $this->quick_scan,
            'error' => $this->quick_scan_error,
            'steps' => $this->quick_scan_steps ?? [],
            'cost_usd' => $this->quick_scan_cost_usd,
            'tokens' => $this->quick_scan_tokens,
            'at' => $this->quick_scan_at?->toIso8601String(),
        ];
    }

    /** Only the tickets of spaces this person may see. */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, ?User $user): void
    {
        $ids = $user?->visibleSpaceIds();
        if ($ids !== null) {
            $query->whereIn('space_id', $ids);
        }
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AgentSession::class);
    }

    /** The open conversation about this ticket, if there is one. */
    public function openSession(): ?AgentSession
    {
        return $this->sessions()->where('state', 'open')->latest('id')->first();
    }

    public function casebookEntry(): BelongsTo
    {
        return $this->belongsTo(CasebookEntry::class);
    }

    /** What the casebook is matched against; the description joins it once it is synced. */
    public function matchText(): string
    {
        return (string) $this->summary;
    }

    public function rememberMatch(?array $match): void
    {
        $this->casebook_entry_id = $match['id'] ?? null;
        $this->casebook_score = $match['score'] ?? null;
    }

    /** Still matching its space's rules at the last sync. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('left_at');
    }

    /**
     * Store one issue from a search result, as seen now.
     *
     * @param  list<string>  $slaFields  the ids of the SLA fields asked for
     */
    public static function record(Space $space, array $issue, CarbonInterface $now, array $slaFields = []): self
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
            'assignee_account_id' => $fields['assignee']['accountId'] ?? null,
            'slas' => $slaFields === [] ? null : self::slasFrom($fields, $slaFields),
            // Jira writes its own offset (+0200); the database keeps UTC without one, so convert
            // first, or every time comes back hours off ("updated in 2 hours").
            'jira_created_at' => isset($fields['created']) ? Carbon::parse($fields['created'])->utc() : null,
            'jira_updated_at' => isset($fields['updated']) ? Carbon::parse($fields['updated'])->utc() : null,
            'last_seen_at' => $now,
            'left_at' => null,
        ]);
        $ticket->first_seen_at ??= $now;
        $ticket->save();

        return $ticket;
    }

    /**
     * Jira's SLA fields, each reduced to what the overview shows: the running cycle if
     * there is one, otherwise how the last one ended. An SLA without either does not
     * apply to this ticket and is left out. Times left are Jira's own, in the SLA's
     * calendar (working hours), as of this sync.
     *
     * @param  list<string>  $slaFields
     * @return list<array{name: string, state: string, paused: bool, remaining_ms: ?int, goal_ms: ?int, due_at: ?string}>
     */
    public static function slasFrom(array $fields, array $slaFields): array
    {
        $slas = [];

        foreach ($slaFields as $id) {
            $value = $fields[$id] ?? null;
            $cycle = is_array($value) ? ($value['ongoingCycle'] ?? null) : null;
            $last = is_array($value) && ! empty($value['completedCycles']) ? end($value['completedCycles']) : null;

            if ($cycle === null && $last === null) {
                continue;
            }

            $from = $cycle ?? $last;
            $breached = (bool) ($from['breached'] ?? false);
            $paused = $cycle !== null && (bool) ($cycle['paused'] ?? false);

            $slas[] = [
                'name' => (string) ($value['name'] ?? $id),
                'state' => match (true) {
                    $cycle === null => $breached ? 'missed' : 'met',
                    $breached => 'breached',
                    $paused => 'paused',
                    default => 'running',
                },
                'paused' => $paused,
                'remaining_ms' => isset($from['remainingTime']['millis']) ? (int) $from['remainingTime']['millis'] : null,
                'goal_ms' => isset($from['goalDuration']['millis']) ? (int) $from['goalDuration']['millis'] : null,
                'due_at' => isset($from['breachTime']['epochMillis'])
                    ? Carbon::createFromTimestampMs($from['breachTime']['epochMillis'])->toIso8601String()
                    : null,
            ];
        }

        // Running ones first, the closest to breaching (or the furthest past it) on top.
        usort($slas, fn (array $a, array $b) => [in_array($a['state'], ['met', 'missed'], true), $a['remaining_ms'] ?? PHP_INT_MAX]
            <=> [in_array($b['state'], ['met', 'missed'], true), $b['remaining_ms'] ?? PHP_INT_MAX]);

        return $slas;
    }
}
