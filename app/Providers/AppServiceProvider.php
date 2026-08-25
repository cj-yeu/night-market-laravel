<?php

namespace App\Providers;

use App\Contracts\HostnameResolver;
use App\Contracts\RecommendationExplanationProvider;
use App\Services\DeterministicRecommendationExplanationProvider;
use App\Services\NativeHostnameResolver;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Notifications\Messages\MailMessage;

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
        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            return (new MailMessage)
                ->subject('Verify Email Address')
                ->greeting('Hello!')
                ->line('Please click the button below to verify your email address.')
                ->action('Verify Email Address', $url)
                ->line('If you did not create an account, no further action is required.')
                ->salutation("Regards,\nNight Market Selangor");
        });

        if (parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https') {
            URL::forceScheme('https');
        }

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
