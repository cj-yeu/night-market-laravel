<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('verification-email', function (Request $request): Limit {
            return Limit::perMinute(1)
                ->by((string) $request->user()?->getKey())
                ->response(function (Request $request, array $headers): RedirectResponse {
                    return redirect()
                        ->route('verification.notice')
                        ->withHeaders($headers)
                        ->with('error', 'Please wait 60 seconds before requesting another verification email.');
                });
        });
    }
}
