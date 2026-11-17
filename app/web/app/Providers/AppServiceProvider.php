<?php

namespace App\Providers;

use App\Services\DenyLearningAuthorizationAuthority;
use App\Services\LearningAuthorizationAuthority;
use App\Support\AppUrlWarning;
use App\Support\CanonicalPublicUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LearningAuthorizationAuthority::class, fn () =>
            \App\Services\ConsentLearningAuthorizationAuthority::enabled()
                ? new \App\Services\ConsentLearningAuthorizationAuthority
                : new DenyLearningAuthorizationAuthority);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $host = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));
        if ($this->app->environment('production')
            && in_array($host, ['', 'localhost', 'example.com', 'app.example.org'], true)) {
            $this->app->make(AppUrlWarning::class)->logOnce();
        }

        foreach (['store' => 5, 'show' => 30, 'review' => 20] as $operation => $limit) {
            \Illuminate\Support\Facades\RateLimiter::for('dma-nlp-'.$operation, fn ($request) =>
                \Illuminate\Cache\RateLimiting\Limit::perMinute($limit)->by('dma-nlp-'.$operation.'|'.$request->user()->id));
        }
        foreach (['read' => 30, 'write' => 10, 'export' => 10] as $operation => $limit) {
            \Illuminate\Support\Facades\RateLimiter::for('iro-'.$operation, fn ($request) =>
                \App\Services\Dma\P8Authority::enabled()
                    ? \Illuminate\Cache\RateLimiting\Limit::perMinute($limit)->by('iro-'.$operation.'|'.$request->user()->id)
                    : \Illuminate\Cache\RateLimiting\Limit::none());
        }
        $trustedHosts = (bool) config('app.enforce_trusted_hosts', false)
            ? array_values(array_filter((array) config('app.trusted_hosts', []), 'is_string'))
            : [];
        SymfonyRequest::setTrustedHosts($trustedHosts);

        // Behind the Next proxy Laravel receives the backend's own host (e.g. an
        // internal IP:port) and the frontend is not necessarily a trusted proxy,
        // so every absolute URL it generates — the guest redirect to /login, the
        // redirect after registering, asset() — must come from APP_URL instead.
        // Invalid APP_URL values are already logged above; they do not abort.
        if ($this->app->environment('production')) {
            try {
                URL::forceRootUrl(CanonicalPublicUrl::root());
                URL::forceScheme('https');
            } catch (\LogicException) {
                // Keep request-based URLs until the operator fixes APP_URL.
            }
        }

        ResetPassword::createUrlUsing(static fn ($notifiable, string $token): string => CanonicalPublicUrl::to(
            '/reset-password',
            ['email' => $notifiable->getEmailForPasswordReset()],
        ).'#token='.rawurlencode($token));

        VerifyEmail::createUrlUsing(static function ($notifiable): string {
            $relativeUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
                absolute: false,
            );

            return CanonicalPublicUrl::root().$relativeUrl;
        });
    }
}
