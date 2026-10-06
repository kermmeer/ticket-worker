<?php

namespace App\Http\Controllers;

use App\Jira\JiraClient;
use App\Models\Setting;
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
        ]);
    }

    public function autoPatch(Request $request): RedirectResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        Setting::put(Setting::AUTO_PATCH, $data['on'] ? '1' : '0');

        return back();
    }

    /** How replies to reporters are written: the agent follows these in every draft. */
    public function replyRules(Request $request): RedirectResponse
    {
        $data = $request->validate(['rules' => ['nullable', 'string', 'max:4000']]);
        Setting::put(Setting::REPLY_RULES, $data['rules'] ?? null);

        return back()->with('success', 'Saved. The next reply draft follows the new rules.');
    }
}
