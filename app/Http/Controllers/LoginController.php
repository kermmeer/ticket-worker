<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequirePassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (! RequirePassword::enabled() || $request->session()->get(RequirePassword::SESSION_KEY) === true) {
            return to_route('overview');
        }

        return Inertia::render('Login');
    }

    public function attempt(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:500']]);

        if (! RequirePassword::enabled() || ! hash_equals((string) config('app.password'), $data['password'])) {
            throw ValidationException::withMessages(['password' => 'That is not the password.']);
        }

        $request->session()->regenerate();
        $request->session()->put(RequirePassword::SESSION_KEY, true);

        return redirect()->intended(route('overview'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
