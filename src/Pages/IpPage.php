<?php

namespace Porro\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Porro\Filament\Pages\Concerns\ManagesBlocklist;
use Porro\Filament\PorroPlugin;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\Presenter;

/**
 * IP details: who's behind a flagged IP, what it did here, and what can
 * be done. Opened from Site Health's activity table (?ip=…), so not in the
 * navigation. Like the other pages, it overrides getters rather than
 * statics so it works on Filament 3, 4 and 5.
 */
class IpPage extends Page
{
    use ManagesBlocklist;

    public string $ip = '';

    /** @var array<string, mixed>|null */
    public ?array $reputation = null;

    public function mount(): void
    {
        $ip = request()->query('ip');
        abort_unless(is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false, 404);

        $this->ip = $ip;
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return 'activity-ip';
    }

    public function getTitle(): string
    {
        return $this->ip ?: __('IP details');
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
        return 'porro-filament::ip';
    }

    /**
     * AbuseIPDB, on request only: it's the one lookup that sends the IP to
     * a third party, so it needs the manage permission too.
     */
    public function checkReputation(): void
    {
        abort_unless(static::canManage(), 403);

        $this->reputation = app(Activity::class)->reputation($this->ip);

        if ($this->reputation === null) {
            Notification::make()->title(__('AbuseIPDB couldn’t be reached just now, or the key was refused.'))->danger()->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'details' => app(Presenter::class)->ip($this->ip, $this->reputation),
            'canManage' => static::canManage(),
            'healthUrl' => SiteHealthPage::getUrl(),
            'blockingUrl' => BlockingPage::getUrl(),
        ];
    }
}
