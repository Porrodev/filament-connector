<?php

namespace Porro\Filament\Pages;

use Filament\Pages\Page;
use Filament\Panel;
use Porro\Filament\Pages\Concerns\ManagesBlocklist;
use Porro\Filament\PorroPlugin;
use Porro\Laravel\Activity\Presenter;

/**
 * The whole blocklist or safelist (?list=…): searchable, filterable, 50 a
 * page. Opened from the Blocking page, so not in the navigation. Search,
 * filters and pages are plain links, so each view has its own address.
 */
class ListPage extends Page
{
    use ManagesBlocklist;

    public string $list = 'blocklist';

    public string $search = '';

    public string $filter = 'all';

    public int $pageNumber = 1;

    public function mount(): void
    {
        $list = request()->query('list');
        $this->list = in_array($list, array_keys(Presenter::LISTS), true) ? $list : 'blocklist';
        ['search' => $this->search, 'filter' => $this->filter, 'page' => $this->pageNumber] = Presenter::listQuery(request()->query());
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'blocking-list';
    }

    public function getTitle(): string
    {
        return $this->list === 'safelist' ? __('Safelist') : __('Blocklist');
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
        return 'porro-filament::list';
    }

    /**
     * The address of this list with $change applied to the search, filter
     * and page.
     *
     * @param  array<string, mixed>  $change
     */
    public function linkTo(array $change): string
    {
        return static::getUrl(array_filter([
            'list' => $this->list,
            'q' => $this->search,
            'filter' => $this->filter === 'all' ? null : $this->filter,
            ...$change,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $entries = app(Presenter::class)->entries($this->list, $this->search, $this->filter, $this->pageNumber);
        $entries['currentPage'] = $entries['page'];
        unset($entries['page']);

        return $entries + [
            'canManage' => static::canManage(),
            'blockingUrl' => BlockingPage::getUrl(),
            'remove' => $this->list === 'safelist' ? 'unsafelistIp' : 'unblockIp',
        ];
    }
}
