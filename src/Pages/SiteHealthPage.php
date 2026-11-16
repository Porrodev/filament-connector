<?php

namespace Porro\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Porro\Filament\Pages\Concerns\ManagesBlocklist;
use Porro\Filament\PorroPlugin;
use Porro\Filament\SiteHealthView;
use Porro\Laravel\Connect\ConnectFailure;
use Porro\Laravel\Connect\Disconnect;
use Porro\Laravel\Connect\PanelConnect;
use Porro\Laravel\SiteHealth;
use Porro\Laravel\SiteKeys;

/**
 * The site's health: checks run on the site, and its Porro findings once
 * connected. Written to work on Filament 3, 4 and 5. Their static
 * property types differ, and assigning an inherited static would change
 * every page, so it overrides the getters instead, with return types
 * each version accepts.
 */
class SiteHealthPage extends Page
{
    use ManagesBlocklist;

    protected static ?string $porroGroup = null;

    protected static ?int $porroSort = null;

    /**
     * Set by disconnecting, for the notice above the connect card. It
     * lasts for this view of the page and is gone on the next.
     *
     * @var array{workspace: string|null, told: bool}|null
     */
    #[Locked]
    public ?array $disconnected = null;

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

    /**
     * "Site Health" under Porro; "Porro Site Health" in a group you chose.
     */
    public static function getNavigationLabel(): string
    {
        return static::$porroGroup === null ? __('Site Health') : __('Porro Site Health');
    }

    public static function getNavigationIcon(): ?string
    {
        return 'porro-mark';
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

    /**
     * Connecting has its own say-so: seeing the page isn't enough.
     */
    public static function canConnect(): bool
    {
        return (bool) PorroPlugin::current()?->canConnect(filament()->auth()->user());
    }

    /**
     * Porro sends the browser back to this page with a grant, and this
     * finishes connecting. The page's own address is the return address,
     * so it's behind the panel's sign-in and guard like any other visit.
     * Whatever happens, the browser moves on to an address without the
     * grant in it.
     */
    public function mount(PanelConnect $connect): void
    {
        if (! $connect->returning(request())) {
            return;
        }

        abort_unless(static::canConnect(), 403);

        try {
            $next = $connect->finish(request(), static::connectUser());
        } catch (ConnectFailure $e) {
            static::couldNotConnect($e);
            $this->redirect(static::getUrl());

            return;
        }

        if ($next === null) {
            Notification::make()->title(__('Connected to Porro. The first report has been sent.'))->success()->send();
        }

        $this->redirect($next ?? static::getUrl());
    }

    /**
     * "Connect to Porro": ask Porro, then send the browser there to approve.
     */
    public function connectToPorro(PanelConnect $connect): void
    {
        abort_unless(static::canConnect(), 403);

        try {
            // The return address as a path: the host comes from the one
            // the browser is on, not from APP_URL.
            $this->redirect($connect->start(request(), (string) parse_url(static::getUrl(), PHP_URL_PATH), static::connectUser()));
        } catch (ConnectFailure $e) {
            static::couldNotConnect($e);
        }
    }

    /**
     * Disconnecting is undoing "Connect to Porro", so it's for the same
     * people, and only for a key kept on this site: never PORRO_KEY, and
     * never from a copy that isn't the live site.
     */
    public static function canDisconnect(): bool
    {
        return static::canConnect() && app(PanelConnect::class)->state() === PanelConnect::CONNECTED;
    }

    /**
     * "Disconnect" on the connected card opens this with
     * mountAction('disconnectFromPorro'): Filament's own confirmation
     * modal, saying what stops and what carries on.
     */
    public function disconnectFromPorroAction(): Action
    {
        $stored = fn (): array => app(SiteKeys::class)->stored() ?? [];
        $workspace = fn (): string => $stored()['workspace'] ?? __('Porro');

        return Action::make('disconnectFromPorro')
            ->label(__('Disconnect'))
            ->requiresConfirmation()
            ->color('danger')
            ->modalWidth('lg')
            ->modalAlignment(Alignment::Start)
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalHeading(fn () => __('Disconnect :host from Porro?', ['host' => $stored()['host'] ?? __('this site')]))
            ->modalDescription(fn () => __('This site stops sending reports to :workspace, and its key is deleted from this site.', ['workspace' => $workspace()]))
            ->modalContent(fn () => view('porro-filament::disconnect', ['workspace' => $workspace()]))
            ->modalSubmitActionLabel(__('Disconnect'))
            ->modalCancelActionLabel(__('Cancel'))
            ->visible(fn () => static::canDisconnect())
            ->action(fn () => $this->disconnectFromPorro());
    }

    /**
     * Tell Porro and delete the key, whatever Porro answered. Only ever
     * reached through the confirmation above.
     */
    private function disconnectFromPorro(): void
    {
        abort_unless(static::canDisconnect(), 403);

        $result = app(Disconnect::class)->disconnect();

        if ($result !== null) {
            $this->disconnected = ['workspace' => $result['workspace'], 'told' => $result['told']];
        }
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
        $view = app(SiteHealthView::class);
        $data = $view->data();

        return $data + [
            'connect' => $view->connect($data['connected'], $data['porroUrl'], static::canConnect()),
            'disconnected' => $this->disconnected,
            'canManage' => static::canManage(),
            'blockingUrl' => BlockingPage::getUrl(),
            'ipUrl' => IpPage::getUrl(),
        ];
    }

    /**
     * Whoever is signed in to the panel. canConnect() has already turned
     * away anyone without an id.
     */
    private static function connectUser(): string
    {
        return (string) filament()->auth()->user()?->getAuthIdentifier();
    }

    /**
     * The reason, in words, and it stays until it's closed. Escaped: it
     * can carry what Porro said, and notifications render HTML.
     */
    private static function couldNotConnect(ConnectFailure $e): void
    {
        Notification::make()->title(__('Couldn’t connect to Porro'))->body(e($e->getMessage()))->danger()->persistent()->send();
    }
}
