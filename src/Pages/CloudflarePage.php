<?php

namespace Porro\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Porro\Core\Activity\CloudflareError;
use Porro\Filament\PorroPlugin;
use Porro\Laravel\Activity\CloudflareMode;
use Porro\Laravel\Activity\Presenter;

/**
 * Setting up Cloudflare: the token and its two permission policies, where
 * it goes, and a button that connects. Reached from Blocking, so not in
 * the navigation.
 */
class CloudflarePage extends Page
{
    public static function getSlug(?Panel $panel = null): string
    {
        return 'blocking-cloudflare';
    }

    public function getTitle(): string
    {
        return __('Set up Cloudflare');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return (bool) PorroPlugin::current()?->canView(auth()->user());
    }

    public static function canManage(): bool
    {
        return (bool) PorroPlugin::current()?->canManage(auth()->user());
    }

    public function getView(): string
    {
        return 'porro-filament::cloudflare';
    }

    /**
     * Finds the zone, creates or reuses the list and adds the rule, then
     * syncs, so nobody needs a terminal.
     */
    public function connect(CloudflareMode $cloudflare): void
    {
        abort_unless(static::canManage(), 403);

        if (! $cloudflare->configured()) {
            Notification::make()->title(__('Set PORRO_CLOUDFLARE_TOKEN first, then connect.'))->danger()->send();

            return;
        }

        try {
            $connection = $cloudflare->connect();
        } catch (CloudflareError $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $result = $cloudflare->sync();

        Notification::make()->title($result !== null
            ? __('Connected to :zone and synced the blocklist.', ['zone' => $connection['zone_name']])
            : __('Connected to :zone. Only production syncs the blocklist.', ['zone' => $connection['zone_name']]))->success()->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(Presenter::class)->cloudflare() + [
            'canManage' => static::canManage(),
            'blockingUrl' => BlockingPage::getUrl(),
        ];
    }
}
