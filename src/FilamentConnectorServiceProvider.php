<?php

namespace Porro\Filament;

use Illuminate\Support\ServiceProvider;

class FilamentConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([FilamentCollector::class], 'porro.collectors');
        $this->app->tag([FilamentHealth::class], 'porro.health');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'porro-filament');
    }
}
