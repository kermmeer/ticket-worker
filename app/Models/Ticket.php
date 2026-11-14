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
        'space_id', 'site', 'jira_id', 'key', 'summary', 'status', 'status_category', 'priority',
        'issue_type', 'reporter', 'assignee', 'assignee_account_id', 'slas', 'casebook_entry_id', 'casebook_score', 'jira_created_at', 'jira_updated_at',
        'first_seen_at', 'last_seen_at', 'left_at', 'hidden_at',
        'casebook_manual', 'done_at', 'done_by', 'done_how', 'done_verdict', 'done_note', 'local', 'local_description', 'local_user_id', 'quick_scan_state', 'quick_scan', 'quick_scan_steps', 'quick_scan_error', 'quick_scan_cost_usd', 'quick_scan_tokens', 'quick_scan_at', 'quick_scan_user_id', 'sla_warned',
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
            'casebook_manual' => 'boolean',
            'local' => 'boolean',
            'done_at' => 'datetime',
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
            // Who started it, or nobody: then the sync did, when the ticket arrived.
            'by' => $this->quick_scan_user_id ? (User::find($this->quick_scan_user_id)?->firstName() ?? 'someone') : null,
        ];
    }

    /**
     * Assigned to this person: by Jira account id on the default site, where accounts are
     * linked, and by name on another site, where the same person has another id.
     */
    public static function isMine(?string $accountId, ?string $assignee, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return (filled($accountId) && $accountId === $user->jira_account_id)
            || (filled($assignee) && filled($user->jira_name) && mb_strtolower($assignee) === mb_strtolower($user->jira_name));
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

    /** How a ticket can end, as Done asks it. */
    public const DONE_HOW = ['reply' => 'A reply', 'data' => 'A data fix', 'code' => 'A code change', 'config' => 'Configuration', 'not_ours' => 'Not ours', 'duplicate' => 'A duplicate', 'other' => 'Something else'];

    public const VERDICTS = ['right' => 'Right', 'partly' => 'Partly', 'wrong' => 'Wrong'];

    /** When this person last opened it; null when never. */
    public function seenAt(?User $user): ?\Illuminate\Support\Carbon
    {
        if ($user === null) {
            return null;
        }
        $at = \Illuminate\Support\Facades\DB::table('ticket_views')->where(['user_id' => $user->id, 'ticket_id' => $this->id])->value('seen_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    public function markSeen(?User $user): void
    {
        if ($user !== null) {
            \Illuminate\Support\Facades\DB::table('ticket_views')->upsert([['user_id' => $user->id, 'ticket_id' => $this->id, 'seen_at' => now()]], ['user_id', 'ticket_id'], ['seen_at']);
        }
    }

    /** Own work, not (yet) a Jira ticket. */
    public function isLocal(): bool
    {
        return (bool) $this->local;
    }

    /** Where its screenshots and files are kept, until they go to Jira with the ticket. */
    public function filesPath(): string
    {
        return rtrim((string) config('agent.work_files_path'), '/').'/'.$this->id;
    }

    /** @return list<array{name: string, size: int, mime: ?string}> */
    public function localFiles(): array
    {
        return collect(glob($this->filesPath().'/*') ?: [])->filter(fn ($path) => is_file($path))->sort()->map(fn ($path) => [
            'name' => basename($path), 'size' => filesize($path), 'mime' => mime_content_type($path) ?: null,
        ])->values()->all();
    }

    /** Own work, in the shape Jira's issue has: for the page and for the agent's ticket.md. */
    public function localIssue(): array
    {
        return [
            'key' => $this->key,
            'summary' => $this->summary,
            'description' => (string) $this->local_description,
            'status' => 'Own work',
            'status_category' => null,
            'priority' => null,
            'type' => $this->issue_type,
            'reporter' => $this->reporter,
            'assignee' => $this->assignee,
            'assignee_account_id' => null,
            'created' => $this->created_at?->toIso8601String(),
            'updated' => $this->updated_at?->toIso8601String(),
            'comments' => [],
            'attachments' => [],
        ];
    }

    public function branches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TicketBranch::class);
    }

    /** Support (an incident, a question) or development (a story, a bug to build a fix for). */
    public function kind(): string
    {
        $types = \App\Settings\SpaceSettings::for($this->space)->types('development_types');

        return in_array(mb_strtolower((string) $this->issue_type), $types, true) ? 'development' : 'support';
    }

    /** The kind among a space's development types, worked out once for many tickets. */
    public function kindAmong(array $developmentTypes): string
    {
        return in_array(mb_strtolower((string) $this->issue_type), $developmentTypes, true) ? 'development' : 'support';
    }

    public function isDevelopment(): bool
    {
        return $this->kind() === 'development';
    }

    /** feature/DEVBESUP-3211, fix/… for a fix type, or the key alone with prefixes off. */
    public function branchName(): string
    {
        $settings = \App\Settings\SpaceSettings::for($this->space);
        if (! $settings->get('branch_prefixes')) {
            return $this->key;
        }
        $fix = in_array(mb_strtolower((string) $this->issue_type), $settings->types('fix_types'), true);

        return ((string) $settings->get($fix ? 'fix_prefix' : 'feature_prefix')).$this->key;
    }

    public function rememberMatch(?array $match): void
    {
        // Tied by hand: what the words say does not undo what you decided.
        if ($this->casebook_manual) {
            return;
        }
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

        // An issue id is only unique within its site.
        $ticket = static::firstOrNew(['site' => $space->jira_site_id ?? 0, 'jira_id' => (string) $issue['id']]);
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
     * What Jira says now, from the ticket page's own look at the issue: the sync only reads
     * open tickets, so a resolved one would otherwise keep the last status it saw there.
     */
    public function takeLive(array $issue): void
    {
        $this->fill(array_filter([
            'summary' => filled($issue['summary'] ?? null) ? Str::limit($issue['summary'], 990) : null,
            'status' => $issue['status'] ?? null,
            'status_category' => $issue['status_category'] ?? null,
            'priority' => $issue['priority'] ?? null,
        ], fn ($value) => $value !== null) + [
            'assignee' => $issue['assignee'] ?? null,
            'assignee_account_id' => $issue['assignee_account_id'] ?? null,
            'jira_updated_at' => isset($issue['updated']) ? Carbon::parse($issue['updated'])->utc() : $this->jira_updated_at,
        ]);
        if ($this->isDirty()) {
            $this->save();
        }
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
