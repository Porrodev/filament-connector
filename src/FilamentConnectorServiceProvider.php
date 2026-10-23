<?php

namespace Porro\Filament;

use BladeUI\Icons\Factory;
use Illuminate\Support\ServiceProvider;

class FilamentConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
    }
}
