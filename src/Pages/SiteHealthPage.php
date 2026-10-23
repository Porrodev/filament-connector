<?php

namespace Porro\Filament\Pages;

use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Porro\Filament\PorroPlugin;
use Porro\Filament\SiteHealthView;
use Porro\Laravel\SiteHealth;

/**
 * The site's health: checks run on the site, and its Porro findings once
 * connected. Written to work on Filament 3, 4 and 5. Their static
 * property types differ, and assigning an inherited static would change
 * every page, so it overrides the getters instead, with return types
 * each version accepts.
 */
class SiteHealthPage extends Page
{
    protected static ?string $porroGroup = null;

    protected static ?int $porroSort = null;

    public static function configure(?string $group, ?int $sort): void
    {
        static::$porroGroup = $group;
        static::$porroSort = $sort;
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'site-health';
    }

    public function getTitle(): string
    {
        return __('Porro Site Health');
    }

    /**
     * The title with the Porro mark in front, so the page always reads as
     * Porro's.
     */
    public function getHeading(): Htmlable
    {
        return new HtmlString(Blade::render(
            '<span style="display: inline-flex; align-items: center; gap: .6rem"><x-filament::icon icon="porro-mark" style="width: 1.75rem; height: 1.75rem" />{{ $title }}</span>',
            ['title' => $this->getTitle()],
        ));
    }

    public static function getNavigationLabel(): string
    {
        return __('Porro Site Health');
    }

    public static function getNavigationIcon(): ?string
    {
        return 'porro-mark';
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$porroGroup;
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
        return 'porro-filament::page';
    }

    public function checkAgain(SiteHealth $health): void
    {
        abort_unless(static::canAccess(), 403);

        $health->get(fresh: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(SiteHealthView::class)->data();
    }
}
