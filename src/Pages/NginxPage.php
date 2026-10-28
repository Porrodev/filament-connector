<?php

namespace Porro\Filament\Pages;

use Filament\Pages\Page;
use Filament\Panel;
use Porro\Filament\PorroPlugin;
use Porro\Laravel\Activity\Presenter;

/**
 * Setting up nginx: the three one-time steps, one for the site and two for each server, and how far
 * each server has got. Reached from Blocking, so not in the navigation.
 */
class NginxPage extends Page
{
    public static function getSlug(?Panel $panel = null): string
    {
        return 'blocking-nginx';
    }

    public function getTitle(): string
    {
        return __('Set up nginx');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return (bool) PorroPlugin::current()?->canView(auth()->user());
    }

    public function getView(): string
    {
        return 'porro-filament::nginx';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(Presenter::class)->nginx((string) request()->server('REMOTE_ADDR')) + [
            'blockingUrl' => BlockingPage::getUrl(),
        ];
    }
}
