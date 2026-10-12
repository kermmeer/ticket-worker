<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyUser;
use App\Http\Middleware\RequirePassword;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            RequirePassword::class,
            IdentifyUser::class,
        ]);
        $middleware->alias(['role' => \App\Http\Middleware\EnsureRole::class]);
        // Behind a reverse proxy that ends TLS, name it here so links come out as https.
        if (filled($proxies = env('TRUSTED_PROXIES'))) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A page action that is refused or breaks answers in words the page shows, never with an
        // error page: Inertia would put that in a modal over everything, and the page looked
        // stuck until a refresh. Page loads and validation keep their own handling.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->header('X-Inertia')
                || $e instanceof Illuminate\Validation\ValidationException
                || $e instanceof Illuminate\Auth\AuthenticationException) {
                return null;
            }

            [$status, $message] = match (true) {
                $e instanceof Illuminate\Session\TokenMismatchException => [419, 'The page had expired; it reloads.'],
                $e instanceof Illuminate\Database\Eloquent\ModelNotFoundException => [404, 'That is gone, or not yours to see.'],
                $e instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => [
                    $e->getStatusCode(),
                    $e->getMessage() ?: (Symfony\Component\HttpFoundation\Response::$statusTexts[$e->getStatusCode()] ?? 'That did not work.'),
                ],
                default => [500, 'Something went wrong on the server. Try again; if it keeps happening, the log says why.'],
            };

            return response()->json(['message' => $message], $status);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
