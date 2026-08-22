<?php

namespace App\Providers;

use App\Contracts\HostnameResolver;
use App\Contracts\RecommendationExplanationProvider;
use App\Services\DeterministicRecommendationExplanationProvider;
use App\Services\NativeHostnameResolver;
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
        $this->app->bind(HostnameResolver::class, NativeHostnameResolver::class);
        $this->app->bind(
            RecommendationExplanationProvider::class,
            DeterministicRecommendationExplanationProvider::class,
        );
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
