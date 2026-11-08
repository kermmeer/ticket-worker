<?php

namespace App\Settings;

use App\Models\Setting;
use App\Models\Space;

/**
 * The settings a space can have its own of (docs/TEAM.md, item 3). Each falls back to the
 * defaults on the Setup page, and those to .env: so a new space works the way the rest do,
 * and one project with other customers, another tone or a costlier model differs only there.
 */
class SpaceSettings
{
    /** What a space may set, and what kind of value each is. */
    public const KEYS = [
        'model' => 'string',
        'effort' => 'effort',
        'turn_budget_usd' => 'money',
        'chat_model' => 'string',
        'chat_effort' => 'effort',
        'fresh_after_minutes' => 'minutes',
        'quick_model' => 'string',
        'quick_effort' => 'effort',
        'quick_budget_usd' => 'money',
        'monthly_budget_usd' => 'money',
        'auto_patch' => 'bool',
        'development_types' => 'text',
        'fix_types' => 'text',
        'branch_prefixes' => 'bool',
        'feature_prefix' => 'string',
        'fix_prefix' => 'string',
        'base_branch' => 'string',
        'dev_quick_scan' => 'bool',
        'reply_rules' => 'text',
        'answer_rules' => 'text',
    ];

    /** A comma-separated list of issue types, as lowercase names. */
    public function types(string $key): array
    {
        return array_values(array_filter(array_map(fn ($type) => mb_strtolower(trim($type)), explode(',', (string) $this->get($key)))));
    }

    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** The setting key the app-wide defaults are kept under, beside the rules of old. */
    public const DEFAULTS = 'space_defaults';

    private function __construct(private readonly array $own) {}

    public static function for(?Space $space): self
    {
        return new self(array_filter($space?->settings ?? [], fn ($value) => $value !== null && $value !== ''));
    }

    public function get(string $key): mixed
    {
        return $this->own[$key] ?? self::defaults()[$key];
    }

    /** The app-wide defaults, as the Setup page shows and saves them. */
    public static function defaults(): array
    {
        $saved = json_decode((string) Setting::get(self::DEFAULTS), true) ?: [];
        $saved = array_filter($saved, fn ($value) => $value !== null && $value !== '');

        return [
            'model' => $saved['model'] ?? config('agent.claude.model'),
            'effort' => $saved['effort'] ?? config('agent.claude.effort'),
            'turn_budget_usd' => (float) ($saved['turn_budget_usd'] ?? config('agent.turn_budget_usd')),
            'chat_model' => $saved['chat_model'] ?? config('agent.chat.model'),
            'chat_effort' => $saved['chat_effort'] ?? config('agent.chat.effort'),
            'fresh_after_minutes' => (int) ($saved['fresh_after_minutes'] ?? config('agent.chat.fresh_after_minutes')),
            'quick_model' => $saved['quick_model'] ?? config('agent.quick.model'),
            'quick_effort' => $saved['quick_effort'] ?? config('agent.quick.effort'),
            'quick_budget_usd' => (float) ($saved['quick_budget_usd'] ?? config('agent.quick.budget_usd')),
            // No budget unless someone sets one: the Claude account usually has its own.
            'monthly_budget_usd' => isset($saved['monthly_budget_usd']) ? (float) $saved['monthly_budget_usd'] : null,
            'auto_patch' => Setting::autoPatch(),
            'development_types' => $saved['development_types'] ?? config('agent.development.types'),
            'fix_types' => $saved['fix_types'] ?? config('agent.development.fix_types'),
            'branch_prefixes' => (bool) ($saved['branch_prefixes'] ?? config('agent.development.branch_prefixes')),
            'feature_prefix' => $saved['feature_prefix'] ?? config('agent.development.feature_prefix'),
            'fix_prefix' => $saved['fix_prefix'] ?? config('agent.development.fix_prefix'),
            'base_branch' => $saved['base_branch'] ?? config('agent.development.base_branch'),
            // Quick scans are support triage: development tickets get one only where a space says so.
            'dev_quick_scan' => (bool) ($saved['dev_quick_scan'] ?? false),
            'reply_rules' => Setting::replyRules(),
            'answer_rules' => Setting::answerRules(),
        ];
    }

    /** Validation for a form with any of the keys; empty means "the default". */
    public static function rules(): array
    {
        return [
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9.\-\[\]]+$/i'],
            'effort' => ['nullable', 'in:'.implode(',', self::EFFORTS)],
            'turn_budget_usd' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'chat_model' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9.\-\[\]]+$/i'],
            'chat_effort' => ['nullable', 'in:'.implode(',', self::EFFORTS)],
            'fresh_after_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'quick_model' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9.\-\[\]]+$/i'],
            'quick_effort' => ['nullable', 'in:'.implode(',', self::EFFORTS)],
            'quick_budget_usd' => ['nullable', 'numeric', 'min:0.05', 'max:20'],
            'monthly_budget_usd' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'auto_patch' => ['nullable', 'boolean'],
            'development_types' => ['nullable', 'string', 'max:500'],
            'fix_types' => ['nullable', 'string', 'max:500'],
            'branch_prefixes' => ['nullable', 'boolean'],
            'feature_prefix' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'fix_prefix' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'base_branch' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'dev_quick_scan' => ['nullable', 'boolean'],
            'reply_rules' => ['nullable', 'string', 'max:4000'],
            'answer_rules' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
