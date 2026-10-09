<?php

namespace App\Http\Middleware;

use App\Models\AgentSession;
use App\Models\AgentTurn;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is using the app (CONCEPT.md §12). A sign-in in front of it that passes the user on
 * in a header (AUTH_HEADER, e.g. X-authentik-username) decides; otherwise you sign in here
 * with your own password. Only a proxy may set that header: the app must not be reachable
 * around it, or anyone could claim to be anyone.
 */
class IdentifyUser
{
    /** Pages for getting in, which cannot ask who you are first. */
    private const OPEN = ['login', 'login.attempt', 'logout', 'account.sign-in', 'account.sign-in.attempt', 'account.first', 'account.welcome', 'account.welcome.save'];

    public function handle(Request $request, Closure $next): Response
    {
        $header = config('auth.trusted_header');
        if (filled($header) && filled($username = $request->header($header))) {
            $user = User::fromHeader(
                (string) $username,
                $request->header((string) config('auth.trusted_header_name')) ?: null,
                $request->header((string) config('auth.trusted_header_email')) ?: null,
            );
            if (Auth::id() !== $user->id) {
                Auth::login($user);
                $request->session()->regenerate();
            }
        }

        if (($user = $request->user()) !== null) {
            if ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(5))) {
                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            }

            return $next($request);
        }

        if ($request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        return redirect()->guest(route('account.sign-in'));
    }

    /** The first account inherits what was done before there were accounts. */
    public static function adoptEarlierWork(User $user): void
    {
        AgentSession::whereNull('user_id')->update(['user_id' => $user->id]);
        AgentTurn::whereNull('user_id')->update(['user_id' => $user->id]);
    }
}
