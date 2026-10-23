<?php

namespace Porro\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Porro\Filament\Pages\SiteHealthPage;
use Porro\Filament\PorroPlugin;
use Porro\Filament\SiteHealthView;

/**
 * Dashboard: what needs attention, linking to the Site health page.
 * Lazy, so the dashboard never waits on the checks.
 */
class SiteHealthWidget extends Widget
{
    protected static ?int $sort = 90;

    public static function canView(): bool
    {
        return (bool) PorroPlugin::current()?->canView(auth()->user());
    }

    public function render(): View
    {
        return view('porro-filament::widget', app(SiteHealthView::class)->data(limit: 5) + [
            'pageUrl' => SiteHealthPage::getUrl(),
        ]);
    }
}
