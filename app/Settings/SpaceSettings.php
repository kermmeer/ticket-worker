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
        'quick_model' => 'string',
        'quick_effort' => 'effort',
        'quick_budget_usd' => 'money',
        'monthly_budget_usd' => 'money',
        'auto_patch' => 'bool',
        'reply_rules' => 'text',
        'answer_rules' => 'text',
    ];

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
            'quick_model' => $saved['quick_model'] ?? config('agent.quick.model'),
            'quick_effort' => $saved['quick_effort'] ?? config('agent.quick.effort'),
            'quick_budget_usd' => (float) ($saved['quick_budget_usd'] ?? config('agent.quick.budget_usd')),
            // No budget unless someone sets one: the Claude account usually has its own.
            'monthly_budget_usd' => isset($saved['monthly_budget_usd']) ? (float) $saved['monthly_budget_usd'] : null,
            'auto_patch' => Setting::autoPatch(),
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
            'quick_model' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9.\-\[\]]+$/i'],
            'quick_effort' => ['nullable', 'in:'.implode(',', self::EFFORTS)],
            'quick_budget_usd' => ['nullable', 'numeric', 'min:0.05', 'max:20'],
            'monthly_budget_usd' => ['nullable', 'numeric', 'min:1', 'max:100000'],
            'auto_patch' => ['nullable', 'boolean'],
            'reply_rules' => ['nullable', 'string', 'max:4000'],
            'answer_rules' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
