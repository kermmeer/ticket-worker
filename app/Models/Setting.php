<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A switch flipped in the app rather than set in .env.
 */
class Setting extends Model
{
    public const HYPER = 'hyper';

    public const REPLY_RULES = 'reply_rules';

    public const ANSWER_RULES = 'answer_rules';

    /** How the agent talks to you, until you change it on the Setup page. */
    public const DEFAULT_ANSWER_RULES = <<<'TEXT'
    - Short and to the point. Lead with the answer, then the evidence. No preamble, no restating the question, no summary at the end.
    - A few short paragraphs or a tight list at most. Leave out what does not change the conclusion.
    - Never an open suggestion ("you could check the logs", "verify the configuration"). Whatever should be checked or done, give the exact command, SQL query, API call or code change, ready to copy and run, in a code block.
    - Use real names from the code and the ticket: tables, columns, routes, IDs, file paths. Mark only what you cannot know as <placeholder>.
    - Say where each command runs (which system, which database or server) and whether it only reads or changes data. Read-only first; anything that changes data comes with how to undo it.
    - Not sure? Say so in one line, with the one command that would settle it.
    TEXT;

    /** Prepare a patch by itself after an analysis whose fix is a code change. On unless set to 0. */
    public const AUTO_PATCH = 'auto_patch';

    /** How replies to reporters are written, until you change it on the Setup page. */
    public const DEFAULT_REPLY_RULES = <<<'TEXT'
    - Short and to the point: a few sentences, no padding, no long apologies.
    - Say what we found or did, then what happens next or what we need from them.
    - Plain words. No file names, code, commits or internal system names unless the reporter is technical.
    - Start with a short greeting using their first name when the ticket shows it.
    - End on a friendly line, in the reply's language (for example "Fijne dag nog!" or "Have a great day!").
    - Sign off as {first_name}.
    TEXT;

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

    public static function replyRules(): string
    {
        return filled($rules = static::get(self::REPLY_RULES)) ? $rules : self::DEFAULT_REPLY_RULES;
    }

    /**
     * The rules as the agent gets them: {first_name} becomes the first name of the Jira
     * account the app reads with, so each instance signs with its own owner's name.
     */
    public static function replyRulesFor(?string $firstName): string
    {
        return str_replace('{first_name}', $firstName ?? 'the support team', static::replyRules());
    }

    public static function answerRules(): string
    {
        return filled($rules = static::get(self::ANSWER_RULES)) ? $rules : self::DEFAULT_ANSWER_RULES;
    }

    public static function autoPatch(): bool
    {
        return static::get(self::AUTO_PATCH, '1') === '1';
    }

    /** Hyper mode: sync every minute instead of every SYNC_EVERY_MINUTES (CONCEPT.md §6). */
    public static function hyper(): bool
    {
        return static::get(self::HYPER) === '1';
    }
}
