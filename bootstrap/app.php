<?php

use App\Http\Middleware\EnsureAuthenticatedUserIsActive;
use App\Http\Middleware\EnsureUserHasRole;
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
    ->withMiddleware(function (Middleware $middleware) {
        // Railway terminates TLS at its proxy. Trust forwarded protocol headers so the
        // application retains the original HTTPS scheme for routes, redirects and forms.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            EnsureAuthenticatedUserIsActive::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            $request->session()->flash(
                'error',
                'Please log in or register to continue. You will return to your requested page after login.',
            );

            return route('login');
        });
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->hasAdminAccess()
            ? route('admin.dashboard')
            : route('client.home'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
