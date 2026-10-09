<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `role:editor` lets editors and admins through; `role:admin`, admins only. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        abort_unless($request->user()?->atLeast($role), 403, $role === 'admin' ? 'Only an admin can do that.' : 'Only an editor or an admin can do that.');

        return $next($request);
    }
}
