<?php

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
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->role === 'admin'
            ? route('admin.dashboard')
            : route('client.home'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
