<?php

namespace App\Http\Controllers;

use App\Http\Middleware\IdentifyUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Signing in, your own account, and (for admins) the people who use this instance. */
class AccountController extends Controller
{
    public function signIn(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return to_route('overview');
        }

        return Inertia::render('SignIn', [
            // No accounts yet: the first one is made here, and runs the place.
            'firstAccount' => ! User::query()->exists(),
            'viaHeader' => self::viaHeader(),
        ]);
    }

    /** The sign-in in front really passes the user on: not just configured, but arriving. */
    private static function viaHeader(): bool
    {
        return filled($header = config('auth.trusted_header')) && filled(request()->header($header));
    }

    public function attempt(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:500']]);

        if (! Auth::attempt(['username' => mb_strtolower($data['username']), 'password' => $data['password']], remember: true)) {
            throw ValidationException::withMessages(['username' => 'That username and password do not match.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('overview'));
    }

    public function first(Request $request): RedirectResponse
    {
        abort_if(User::query()->exists(), 409, 'There are accounts already: sign in.');
        $data = $this->validated($request);

        $user = User::create([...$data, 'username' => mb_strtolower($data['username']), 'is_admin' => true,
            'email' => $data['username'].'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost')]);
        IdentifyUser::adoptEarlierWork($user);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return to_route('account.show')->with('success', 'Welcome. Add your own Claude sign-in below, then the people you work with.');
    }

    /** The one-time page an admin's invitation link opens: set your password, and you are in. */
    public function welcome(Request $request, User $user): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'This link has expired or was changed: ask for a new one.');

        return Inertia::render('Welcome', ['name' => $user->name, 'username' => $user->username, 'action' => $request->fullUrl()]);
    }

    public function saveWelcome(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'This link has expired or was changed: ask for a new one.');
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(10)]]);
        $user->update(['password' => $data['password']]);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return to_route('account.show')->with('success', 'Your password is set. Add your own Claude sign-in below.');
    }

    public function signOut(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('account.sign-in');
    }

    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account', [
            'account' => [
                'name' => $user->name, 'username' => $user->username, 'is_admin' => $user->is_admin,
                'claude' => match (true) { filled($user->claude_api_key) => 'key', filled($user->claude_oauth_token) => 'token', default => null },
            ],
            'instanceClaude' => \App\Agent\Instructions::instanceCredentials() !== [],
            'viaHeader' => self::viaHeader(),
            'people' => $user->is_admin ? User::query()->orderBy('name')->get()->map(fn (User $person) => [
                'id' => $person->id, 'name' => $person->name, 'username' => $person->username, 'is_admin' => $person->is_admin,
                'has_claude' => $person->claudeCredentials() !== [], 'last_seen_at' => $person->last_seen_at?->toIso8601String(),
                'you' => $person->is($user),
            ]) : null,
            'invite' => session('invite'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $request->user()->update($data);

        return back()->with('success', 'Saved. Reply drafts you ask for are signed with your first name.');
    }

    /** Your own Claude sign-in: an API key, or a token from `claude setup-token`. */
    public function claude(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:key,token,none'],
            'secret' => ['required_unless:kind,none', 'nullable', 'string', 'max:500'],
        ], ['secret.required_unless' => 'Paste the key or the token.']);

        $request->user()->update(match ($data['kind']) {
            'key' => ['claude_api_key' => trim($data['secret']), 'claude_oauth_token' => null],
            'token' => ['claude_api_key' => null, 'claude_oauth_token' => trim($data['secret'])],
            'none' => ['claude_api_key' => null, 'claude_oauth_token' => null],
        });

        return back()->with('success', $data['kind'] === 'none' ? 'Removed. Your agents now run on the instance\'s sign-in, if it has one.' : 'Saved. Your agents run on it from their next turn.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate(['current' => ['required', 'current_password'], 'password' => ['required', 'confirmed', Password::min(10)]]);
        $request->user()->update(['password' => $data['password']]);

        return back()->with('success', 'Your password is changed.');
    }

    /** An admin adds someone: they get a link, valid for a week, to set their own password. */
    public function invite(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/i', 'unique:users,username'],
            'name' => ['required', 'string', 'max:100'],
        ], ['username.regex' => 'Letters, digits, dots, dashes and underscores.']);

        $person = User::create([...$data, 'username' => mb_strtolower($data['username']),
            'email' => mb_strtolower($data['username']).'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'),
            'password' => str()->random(64)]);

        return back()->with('invite', ['name' => $person->name, 'url' => $this->welcomeLink($person)]);
    }

    public function newLink(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);

        return back()->with('invite', ['name' => $user->name, 'url' => $this->welcomeLink($user)]);
    }

    public function admin(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        abort_if($user->is($request->user()), 409, 'You cannot change your own admin rights.');
        $user->update(['is_admin' => $request->boolean('is_admin')]);

        return back();
    }

    public function remove(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        abort_if($user->is($request->user()), 409, 'You cannot remove yourself.');
        $user->delete();

        return back()->with('success', "Removed {$user->name}. Their work stays, without a name.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'username' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/i'],
            'name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ], ['username.regex' => 'Letters, digits, dots, dashes and underscores.']);
    }

    private function welcomeLink(User $user): string
    {
        return URL::temporarySignedRoute('account.welcome', now()->addWeek(), ['user' => $user->id]);
    }
}
