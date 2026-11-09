<?php

namespace Porro\Filament;

use BladeUI\Icons\Factory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Porro\Filament\Http\SignInController;

class FilamentConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/porro.php', 'porro');

        $this->app->tag([FilamentCollector::class], 'porro.collectors');
        $this->app->tag([FilamentHealth::class], 'porro.health');

        // The Porro mark as an icon, "porro-mark", for the navigation, the
        // page heading and the widget.
        $this->callAfterResolving(Factory::class, fn (Factory $icons) => $icons->add('porro', [
            'path' => __DIR__.'/../resources/svg',
            'prefix' => 'porro',
        ]));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'porro-filament');

        // Ten tries a minute from one IP, whoever is signed in already.
        RateLimiter::for('porro-sign-in', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        if (! $this->app->routesAreCached()) {
            // Sign in from Porro. Porro's page posts the ticket from another
            // origin, so there's no CSRF token to check; the ticket, redeemed
            // with the site's own key, is what proves the request. Laravel
            // 13's web group checks with PreventRequestForgery, 11 and 12's
            // with ValidateCsrfToken; naming a class that isn't there is
            // harmless.
            Route::middleware('web')
                ->post('porro/sign-in', SignInController::class)
                ->middleware('throttle:porro-sign-in')
                ->withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class])
                ->name('porro.sign-in');

            // The yes from the "switch accounts?" page. That page is on
            // this site, so unlike the first post this one keeps CSRF.
            Route::middleware('web')
                ->post('porro/sign-in/confirm', [SignInController::class, 'confirm'])
                ->middleware('throttle:porro-sign-in')
                ->name('porro.sign-in.confirm');
        }
    }
}
