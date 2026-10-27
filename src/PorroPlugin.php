<?php

namespace Porro\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Porro\Filament\Pages\BlockingPage;
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
 */
class PorroPlugin implements Plugin
{
    public const GATE = 'viewPorroSiteHealth';

    public const MANAGE_GATE = 'managePorroBlocklist';

    public const GROUP = 'Porro';

    protected ?Closure $authorize = null;

    protected ?Closure $authorizeManage = null;

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

        $panel->pages([SiteHealthPage::class, BlockingPage::class, NginxPage::class]);

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
