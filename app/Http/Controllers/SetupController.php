<?php

namespace App\Http\Controllers;

use App\Jira\JiraClient;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Settings\SpaceSettings;
use App\Setup\SetupChecks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function __invoke(SetupChecks $checks, JiraClient $jira): Response
    {
        return Inertia::render('Setup', [
            'checks' => $checks->all(),
            'firstName' => $jira->firstName(),
            'replyRules' => Setting::replyRules(),
            'defaultReplyRules' => Setting::DEFAULT_REPLY_RULES,
            'autoPatch' => Setting::autoPatch(),
            'defaults' => SpaceSettings::defaults(),
            'savedDefaults' => (object) (json_decode((string) Setting::get(SpaceSettings::DEFAULTS), true) ?: []),
            'sharedClaude' => ($shared = Setting::secret(Setting::SHARED_CLAUDE)) ? $shared['kind'] : null,
            'envClaude' => filled(config('agent.claude.api_key')) || filled(config('agent.claude.oauth_token')),
            'answerRules' => Setting::answerRules(),
            'defaultAnswerRules' => Setting::DEFAULT_ANSWER_RULES,
        ]);
    }

    /** How the agent talks to you: applies from its next turn, also in open sessions. */
    public function answerRules(Request $request): RedirectResponse
    {
        $data = $request->validate(['rules' => ['nullable', 'string', 'max:4000']]);
        Setting::put(Setting::ANSWER_RULES, $data['rules'] ?? null);
        AuditEvent::record('setup.answer-rules', 'answer rules', filled($data['rules'] ?? null) ? 'changed' : 'back to the default');

        return back()->with('success', 'Saved. The agent answers by them from its next turn.');
    }

    public function autoPatch(Request $request): RedirectResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        Setting::put(Setting::AUTO_PATCH, $data['on'] ? '1' : '0');
        AuditEvent::record('setup.auto-patch', 'automatic patches', $data['on'] ? 'on' : 'off');

        return back();
    }

    /** The defaults every space starts from (docs/TEAM.md, item 3). */
    public function settings(Request $request): RedirectResponse
    {
        $rules = collect(SpaceSettings::rules())->except(['auto_patch', 'reply_rules', 'answer_rules'])->all();
        $data = array_filter($request->validate($rules), fn ($value) => $value !== null && $value !== '');
        Setting::put(SpaceSettings::DEFAULTS, json_encode($data));
        AuditEvent::record('setup.defaults', 'defaults for every space', $data === [] ? 'back to .env' : implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($data), $data)));

        return back()->with('success', 'Saved. Every space without its own setting uses these.');
    }

    /** The shared Claude sign-in, for everyone without their own. */
    public function claude(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:key,token,none'],
            'secret' => ['required_unless:kind,none', 'nullable', 'string', 'max:500'],
        ], ['secret.required_unless' => 'Paste the key or the token.']);

        Setting::putSecret(Setting::SHARED_CLAUDE, $data['kind'] === 'none' ? null : ['kind' => $data['kind'], 'secret' => trim($data['secret'])]);
        AuditEvent::record('setup.claude', 'shared Claude sign-in', $data['kind'] === 'none' ? 'removed' : "set ({$data['kind']}; secret changed)");

        return back()->with('success', $data['kind'] === 'none'
            ? 'Removed. Without one in .env either, only people with their own sign-in can run agents, and new tickets get no automatic quick scan.'
            : 'Saved. Everyone without their own sign-in runs on it, and automatic quick scans do.');
    }

    /** How replies to reporters are written: the agent follows these in every draft. */
    public function replyRules(Request $request): RedirectResponse
    {
        $data = $request->validate(['rules' => ['nullable', 'string', 'max:4000']]);
        Setting::put(Setting::REPLY_RULES, $data['rules'] ?? null);
        AuditEvent::record('setup.reply-rules', 'reply rules', filled($data['rules'] ?? null) ? 'changed' : 'back to the default');

        return back()->with('success', 'Saved. The next reply draft follows the new rules.');
    }
}
