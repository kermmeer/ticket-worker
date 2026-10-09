<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                // Shown next to the name everywhere but production, so dev is never mistaken for it.
                'env' => app()->environment(),
                // APP_PASSWORD is set: the header offers to sign out.
                'gate' => RequirePassword::enabled(),
            ],
            'hyper' => fn () => Setting::hyper(),
            // Who you are; what of it the pages need, never a secret.
            'me' => fn () => ($user = $request->user()) ? [
                'id' => $user->id, 'name' => $user->name, 'first_name' => $user->firstName(), 'is_admin' => $user->is_admin,
                'has_claude' => $user->claudeCredentials() !== [],
                'can_run_agents' => \App\Agent\Instructions::credentials($user) !== [],
            ] : null,
            // The name on the link that opens a ticket: Jira, or the app JIRA_OPEN_URL points to.
            'openLabel' => config('services.jira.open_label'),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Where a draft just handed to the outbox can be opened.
                'outbox_url' => fn () => $request->session()->get('outbox_url'),
            ],
        ];
    }
}
