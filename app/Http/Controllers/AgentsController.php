<?php

namespace App\Http\Controllers;

use App\Agent\Budget;
use App\Agent\Instructions;
use App\Models\AgentTurn;
use App\Models\Setting;
use App\Models\Space;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use App\Settings\SpaceSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The agents at a glance: what is working now and for whom, what your own work runs on, and
 * which settings are in force in each space you can see.
 */
class AgentsController extends Controller
{
    private const LABELS = ['analysis' => 'Analysis', 'message' => 'Question', 'proposal' => 'Proposal', 'case' => 'Case draft', 'patch' => 'Patch'];

    public function __invoke(Request $request): Response
    {
        $me = $request->user();
        $visible = $me->visibleSpaceIds();
        $name = fn (?User $user) => $user === null ? 'nobody in particular' : ($user->is($me) ? 'you' : $user->firstName());

        $turns = AgentTurn::query()->with(['user:id,name,username', 'session.ticket:id,key,summary,space_id'])
            ->whereIn('state', [AgentTurn::QUEUED, AgentTurn::RUNNING])->orderBy('id')->get()
            ->filter(fn (AgentTurn $turn) => $visible === null || in_array($turn->session->ticket->space_id, $visible, true))
            ->map(fn (AgentTurn $turn) => [
                'id' => "turn-{$turn->id}",
                'what' => self::LABELS[$turn->kind] ?? $turn->kind,
                'state' => $turn->state,
                'ticket' => $turn->session->ticket->key,
                'summary' => $turn->session->ticket->summary,
                'url' => route('tickets.show', $turn->session->ticket_id, false),
                'who' => $name($turn->user),
                'mine' => $turn->user_id === $me->id,
                'since' => ($turn->started_at ?? $turn->created_at)?->toIso8601String(),
                'steps' => $turn->events()->where('type', 'tool')->count(),
            ]);

        $scans = Ticket::query()->with('space:id,label')->whereIn('quick_scan_state', ['queued', 'running', 'pending'])
            ->visibleTo($me)->orderByRaw("quick_scan_state = 'pending'")->orderByDesc('updated_at')->limit(200)->get();
        $askers = User::whereIn('id', $scans->pluck('quick_scan_user_id')->filter()->unique())->get()->keyBy('id');

        $systems = System::query()->where(fn ($query) => $query->whereIn('scan_state', ['queued', 'running'])->orWhereIn('pull_state', ['queued', 'running']))->get();

        $myLimit = $me->monthly_budget_usd;
        $mySpend = Budget::personThisMonth($me->id);
        $shared = Setting::secret(Setting::SHARED_CLAUDE);

        return Inertia::render('Agents', [
            'working' => $turns->values(),
            'quickScans' => [
                'running' => $scans->whereIn('quick_scan_state', ['queued', 'running'])->map(fn (Ticket $ticket) => [
                    'id' => "scan-{$ticket->id}", 'ticket' => $ticket->key, 'summary' => $ticket->summary, 'state' => $ticket->quick_scan_state,
                    'url' => route('tickets.show', $ticket, false),
                    'who' => $ticket->quick_scan_user_id ? $name($askers[$ticket->quick_scan_user_id] ?? null) : 'the shared sign-in (automatic)',
                    'mine' => $ticket->quick_scan_user_id === $me->id,
                ])->values(),
                'waiting' => $scans->where('quick_scan_state', 'pending')->count(),
                'waiting_mine' => $scans->where('quick_scan_state', 'pending')->where('quick_scan_user_id', $me->id)->count(),
            ],
            'systems' => $systems->map(fn (System $system) => [
                'name' => $system->name,
                'what' => in_array($system->scan_state, ['queued', 'running'], true) ? 'Context scan' : 'Pull',
                'state' => in_array($system->scan_state, ['queued', 'running'], true) ? $system->scan_state : $system->pull_state,
            ]),
            'you' => [
                // What your agents sign in with right now, in the order it is chosen.
                'claude' => match (true) {
                    $me->claudeCredentials() !== [] => filled($me->claude_api_key) ? 'your own API key' : 'your own subscription token',
                    filled($shared['secret'] ?? null) => ($shared['kind'] ?? 'token') === 'key' ? 'the shared API key (Setup)' : 'the shared subscription token (Setup)',
                    Instructions::instanceCredentials() !== [] => 'the sign-in in .env',
                    default => null,
                },
                'usage' => $mySpend,
                'budget' => $myLimit,
                'role' => $me->role,
            ],
            'autoScanSignIn' => Instructions::instanceCredentials() !== [],
            // The order a sign-in is chosen in, what is set at each step, and which one wins.
            'signIns' => [
                ['what' => 'Your own (Your account)', 'set' => $me->claudeCredentials() !== [],
                    'kind' => filled($me->claude_api_key) ? 'API key' : (filled($me->claude_oauth_token) ? 'subscription token' : null)],
                ['what' => 'Shared (Setup)', 'set' => filled($shared['secret'] ?? null),
                    'kind' => filled($shared['secret'] ?? null) ? (($shared['kind'] ?? 'token') === 'key' ? 'API key' : 'subscription token') : null],
                ['what' => 'The server (.env)', 'set' => filled(config('agent.claude.api_key')) || filled(config('agent.claude.oauth_token')),
                    'kind' => filled(config('agent.claude.api_key')) ? 'API key' : (filled(config('agent.claude.oauth_token')) ? 'subscription token' : null)],
            ],
            'spaces' => Space::query()->visibleTo($me)->orderBy('label')->get()->map(function (Space $space) {
                $settings = SpaceSettings::for($space);
                $own = array_keys(array_filter($space->settings ?? [], fn ($value) => $value !== null && $value !== ''));

                return [
                    'id' => $space->id,
                    'label' => $space->label,
                    'colour' => $space->colour,
                    'state' => $space->state,
                    'quick_scan' => $space->quick_scan,
                    'has_webhook' => filled($space->notify_webhook),
                    'settings' => collect(array_keys(SpaceSettings::KEYS))->reject(fn ($key) => in_array($key, ['reply_rules', 'answer_rules'], true))
                        ->mapWithKeys(fn ($key) => [$key => $settings->get($key)]),
                    'own' => $own,
                    'own_rules' => array_values(array_intersect($own, ['reply_rules', 'answer_rules'])),
                    'spent' => Budget::spaceThisMonth($space),
                ];
            }),
            'recent' => AgentTurn::query()->with('session.ticket:id,key')->where('user_id', $me->id)
                ->whereIn('state', [AgentTurn::DONE, AgentTurn::FAILED, AgentTurn::STOPPED])->latest('finished_at')->limit(15)->get()
                ->map(fn (AgentTurn $turn) => [
                    'id' => $turn->id,
                    'what' => self::LABELS[$turn->kind] ?? $turn->kind,
                    'state' => $turn->state,
                    'ticket' => $turn->session->ticket->key,
                    'url' => route('tickets.show', $turn->session->ticket_id, false),
                    'at' => $turn->finished_at?->toIso8601String(),
                    'cost' => $turn->cost_usd,
                ]),
        ]);
    }
}
