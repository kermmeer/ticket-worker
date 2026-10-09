<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * A person using this instance. They sign in with a password, or are known by the header
 * a sign-in in front of the app sets (AUTH_HEADER). Their agents run on their own Claude
 * sign-in when they have one (CONCEPT.md §13).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['username', 'name', 'email', 'password', 'role', 'all_spaces', 'claude_api_key', 'claude_oauth_token', 'last_seen_at'];

    protected $hidden = ['password', 'remember_token', 'claude_api_key', 'claude_oauth_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'all_spaces' => 'boolean',
            'claude_api_key' => 'encrypted',
            'claude_oauth_token' => 'encrypted',
        ];
    }

    /** The spaces this person follows: their overview starts with these. */
    public function followedSpaces(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Space::class);
    }

    public const ROLES = ['member', 'editor', 'admin'];

    /**
     * Admin: everything, people and the global setup included. Editor: spaces, systems and
     * the casebook as well as tickets. Member: works tickets.
     */
    public function atLeast(string $role): bool
    {
        return array_search($this->role ?? 'member', self::ROLES, true) >= array_search($role, self::ROLES, true);
    }

    /** The spaces this person may see when not all of them. */
    public function spaceAccess(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Space::class, 'space_access');
    }

    /** The ids of the spaces this person may see and work, or null for all of them. */
    public function visibleSpaceIds(): ?array
    {
        // Unset (a new account not yet read back) is the default: every space.
        if ($this->is_admin || $this->all_spaces !== false) {
            return null;
        }

        return $this->spaceAccess()->pluck('spaces.id')->all();
    }

    public function canSee(Space|int $space): bool
    {
        $ids = $this->visibleSpaceIds();

        return $ids === null || in_array($space instanceof Space ? $space->id : $space, $ids, true);
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->role === 'admin';
    }

    /** What reply drafts are signed with: the first word of the name. */
    public function firstName(): string
    {
        return Str::before(trim($this->name), ' ') ?: $this->username;
    }

    /** This person's own Claude sign-in, as the CLI's environment; empty when none. */
    public function claudeCredentials(): array
    {
        return match (true) {
            filled($this->claude_api_key) => ['ANTHROPIC_API_KEY' => $this->claude_api_key],
            filled($this->claude_oauth_token) => ['CLAUDE_CODE_OAUTH_TOKEN' => $this->claude_oauth_token],
            default => [],
        };
    }

    /** The account a header names, made on first sight: the sign-in in front already vouched. */
    public static function fromHeader(string $username, ?string $name = null, ?string $email = null): self
    {
        $user = static::firstOrNew(['username' => $username]);
        if (! $user->exists) {
            $user->fill([
                'name' => $name ?: $username,
                'email' => $email ?: $username.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'),
                // Never used: a header account has no password of its own.
                'password' => Str::random(64),
                'role' => static::query()->exists() ? 'member' : 'admin',
            ])->save();
        }

        return $user;
    }
}
