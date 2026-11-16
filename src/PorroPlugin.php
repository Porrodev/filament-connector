<?php

namespace Porro\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Porro\Filament\Pages\BlockingPage;
use Porro\Filament\Pages\CloudflarePage;
use Porro\Filament\Pages\IpPage;
use Porro\Filament\Pages\ListPage;
use Porro\Filament\Pages\NginxPage;
use Porro\Filament\Pages\SiteHealthPage;
use Porro\Filament\Widgets\SiteHealthWidget;
use Throwable;

/**
 * Adds the Site health page (and, optionally, a dashboard widget) to a
 * panel:
 *
 *     ->plugin(PorroPlugin::make())
 *
 * Who can see it, in order: your authorize() callback; else a
 * "viewPorroSiteHealth" gate if you define one; else admins, for users
 * with isAdmin(), isSuperAdmin(), an is_admin attribute or a spatie
 * "super_admin" or "admin" role; else nobody, until you say who.
 *
 * Who can block and safelist IPs: your authorizeManage() callback; else
 * a "managePorroBlocklist" gate; else whoever can see it.
 *
 * Who can connect the site to Porro, among those who can see it: your
 * authorizeConnect() callback; else a "connectPorro" gate; else admins,
 * by the same markers. Seeing the page is never enough.
 */
class PorroPlugin implements Plugin
{
    public const GATE = 'viewPorroSiteHealth';

    public const MANAGE_GATE = 'managePorroBlocklist';

    public const CONNECT_GATE = 'connectPorro';

    public const GROUP = 'Porro';

    protected ?Closure $authorize = null;

    protected ?Closure $authorizeManage = null;

    protected ?Closure $authorizeConnect = null;

    protected bool $widget = true;

    protected ?string $navigationGroup = null;

    protected ?int $navigationSort = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * The plugin on the current panel, if it has one.
     */
    public static function current(): ?static
    {
        try {
            $plugin = filament()->getCurrentPanel()?->getPlugin('porro');
        } catch (Throwable) {
            return null;
        }

        return $plugin instanceof static ? $plugin : null;
    }

    public function getId(): string
    {
        return 'porro';
    }

    /**
     * @param  Closure(Authenticatable|null $user): bool  $using
     */
    public function authorize(Closure $using): static
    {
        $this->authorize = $using;

        return $this;
    }

    /**
     * @param  Closure(Authenticatable|null $user): bool  $using
     */
    public function authorizeManage(Closure $using): static
    {
        $this->authorizeManage = $using;

        return $this;
    }

    /**
     * @param  Closure(Authenticatable|null $user): bool  $using
     */
    public function authorizeConnect(Closure $using): static
    {
        $this->authorizeConnect = $using;

        return $this;
    }

    public function widget(bool $show = true): static
    {
        $this->widget = $show;

        return $this;
    }

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function register(Panel $panel): void
    {
        // Both pages sit together, under "Porro" unless you pick a group.
        SiteHealthPage::configure($this->navigationGroup, $this->navigationSort);
        BlockingPage::configure($this->navigationGroup, $this->navigationSort === null ? null : $this->navigationSort + 1);

        $panel->pages([SiteHealthPage::class, BlockingPage::class, NginxPage::class, CloudflarePage::class, IpPage::class, ListPage::class]);

        if ($this->widget) {
            $panel->widgets([SiteHealthWidget::class]);
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public function canView(?Authenticatable $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->authorize) {
            return (bool) ($this->authorize)($user);
        }

        if (Gate::has(self::GATE)) {
            return Gate::forUser($user)->allows(self::GATE);
        }

        // No way to tell who's an admin: keep it closed rather than show
        // every panel user (perhaps a customer) the site's weak spots.
        return $this->isAdmin($user) ?? false;
    }

    public function canManage(?Authenticatable $user): bool
    {
        if (! $this->canView($user)) {
            return false;
        }

        if ($this->authorizeManage) {
            return (bool) ($this->authorizeManage)($user);
        }

        if (Gate::has(self::MANAGE_GATE)) {
            return Gate::forUser($user)->allows(self::MANAGE_GATE);
        }

        return true;
    }

    /**
     * Connecting gives this site a key: never on viewing alone.
     */
    public function canConnect(?Authenticatable $user): bool
    {
        // Always someone with an id: the request to connect is tied to
        // whoever started it.
        if (! $this->canView($user) || (string) $user->getAuthIdentifier() === '') {
            return false;
        }

        if ($this->authorizeConnect) {
            return (bool) ($this->authorizeConnect)($user);
        }

        if (Gate::has(self::CONNECT_GATE)) {
            return Gate::forUser($user)->allows(self::CONNECT_GATE);
        }

        return $this->isAdmin($user) ?? false;
    }

    /**
     * Whether the user is an admin, by the usual conventions; null when
     * the app has no such idea.
     */
    protected function isAdmin(Authenticatable $user): ?bool
    {
        foreach (['isSuperAdmin', 'isAdmin'] as $method) {
            if (method_exists($user, $method) && $user->{$method}()) {
                return true;
            }
        }

        if (method_exists($user, 'hasRole')) {
            try {
                return $user->hasRole(['super_admin', 'admin']);
            } catch (Throwable) {
                // Roles that don't exist yet.
            }
        }

        if (method_exists($user, 'getAttributes') && array_key_exists('is_admin', $user->getAttributes())) {
            return (bool) $user->getAttributes()['is_admin'];
        }

        foreach (['isSuperAdmin', 'isAdmin'] as $method) {
            if (method_exists($user, $method)) {
                return false;
            }
        }

        return null;
    }
}
