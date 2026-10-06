<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The optional gate: with APP_PASSWORD set, every page asks for it once per browser
 * session. Without it the app is open, for an instance that sits behind a login of its
 * own (a reverse proxy with SSO, a VPN). INSTALL.md says which to pick.
 */
class RequirePassword
{
    public const SESSION_KEY = 'unlocked';

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enabled() || $request->session()->get(self::SESSION_KEY) === true || $request->routeIs('login', 'login.attempt')) {
            return $next($request);
        }

        return redirect()->guest(route('login'));
    }

    public static function enabled(): bool
    {
        return filled(config('app.password'));
    }
}
