<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A switch flipped in the app rather than set in .env.
 */
class Setting extends Model
{
    public const HYPER = 'hyper';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Hyper mode: sync every minute instead of every SYNC_EVERY_MINUTES (CONCEPT.md §6). */
    public static function hyper(): bool
    {
        return static::get(self::HYPER) === '1';
    }
}
