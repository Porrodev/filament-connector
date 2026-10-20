<?php

namespace Porro\Filament;

use Illuminate\Support\ServiceProvider;

class FilamentConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([FilamentCollector::class], 'porro.collectors');
    }
}
