<?php

namespace Porro\Filament\Pages;

use Filament\Pages\Page;
use Filament\Panel;
use Porro\Filament\Pages\Concerns\ManagesBlocklist;
use Porro\Filament\PorroPlugin;
use Porro\Filament\SiteHealthView;

/**
 * The blocklist, the safelist and the ranges that can never be blocked.
 * Like SiteHealthPage, it overrides getters rather than statics so it
 * works on Filament 3, 4 and 5.
 */
class BlockingPage extends Page
{
    use ManagesBlocklist;

    protected static ?string $porroGroup = null;

    protected static ?int $porroSort = null;

    public string $ip = '';

    public string $reason = '';

    public int $days = 7;

    public string $safeIp = '';

    public string $safeLabel = '';

    public bool $confirmWide = false;

    public static function configure(?string $group, ?int $sort): void
    {
        static::$porroGroup = $group;
        static::$porroSort = $sort;
    }

    public function mount(): void
    {
        // "Safelist" on a flagged IP lands here with it filled in.
        $ip = request()->query('safelist');
        $this->safeIp = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'blocking';
    }

    public function getTitle(): string
    {
        return __('Blocking');
    }

    public static function getNavigationLabel(): string
    {
        return static::$porroGroup === null ? __('Blocking') : __('Porro Blocking');
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-no-symbol';
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$porroGroup ?? __(PorroPlugin::GROUP);
    }

    public static function getNavigationSort(): ?int
    {
        return static::$porroSort;
    }

    public static function canAccess(): bool
    {
        return (bool) PorroPlugin::current()?->canView(auth()->user());
    }

    public function getView(): string
    {
        return 'porro-filament::blocking';
    }

    public function addBlock(): void
    {
        if ($this->blockIp($this->ip, $this->days, $this->reason)) {
            $this->ip = $this->reason = '';
        }
    }

    public function addSafe(): void
    {
        if ($this->safelistIp($this->safeIp, $this->safeLabel, $this->confirmWide)) {
            $this->safeIp = $this->safeLabel = '';
            $this->confirmWide = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(SiteHealthView::class)->blocking() + [
            'canManage' => static::canManage(),
            'healthUrl' => SiteHealthPage::getUrl(),
            'nginxUrl' => NginxPage::getUrl(),
        ];
    }
}
