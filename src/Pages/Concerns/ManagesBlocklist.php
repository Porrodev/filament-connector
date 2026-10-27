<?php

namespace Porro\Filament\Pages\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Porro\Core\Activity\Blocklist;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\Refused;
use Porro\Filament\PorroPlugin;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\SiteHealth;
use Throwable;

/**
 * Block, unblock and safelist, for the Site Health and Blocking pages.
 * Every rule about what may be blocked lives in core's Blocklist; this
 * only checks who's asking and turns refusals into notifications.
 */
trait ManagesBlocklist
{
    public static function canManage(): bool
    {
        return (bool) PorroPlugin::current()?->canManage(auth()->user());
    }

    public function blockIp(string $ip, int $days = 0, ?string $reason = null, bool $suggested = false): bool
    {
        $activity = app(Activity::class);

        return $this->changeList(fn (Blocklist $list) => $list->block(
            $ip,
            trim((string) $reason) ?: __('Blocked in the admin panel'),
            $suggested ? Entry::SUGGESTED : Entry::MANUAL,
            $days > 0 ? time() + $days * 86400 : null,
            $this->who(),
            $activity->clientIp(request()),
        ), fn (Entry $entry) => __('Blocked :ip.', ['ip' => $entry->cidr]));
    }

    public function unblockIp(string $ip): void
    {
        $this->changeList(fn (Blocklist $list) => $list->unblock($ip), fn () => __('Unblocked :ip.', ['ip' => $ip]));
    }

    public function safelistIp(string $ip, string $label, bool $confirmWide = false): bool
    {
        return $this->changeList(
            fn (Blocklist $list) => $list->safelist($ip, $label, null, $this->who(), $confirmWide),
            fn (Entry $entry) => __('Safelisted :ip.', ['ip' => $entry->cidr]),
        );
    }

    public function unsafelistIp(string $ip): void
    {
        $this->changeList(fn (Blocklist $list) => $list->unsafelist($ip), fn () => __('Removed :ip from the safelist.', ['ip' => $ip]));
    }

    /**
     * @return bool whether the change went through
     */
    protected function changeList(Closure $change, Closure $message): bool
    {
        abort_unless(static::canManage(), 403);

        $activity = app(Activity::class);

        try {
            $result = $change($activity->blocklist());
        } catch (Refused $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return false;
        }

        $activity->changed();

        try {
            if (config('porro.blocklist.nginx')) {
                Artisan::call('porro:blocklist-sync');
            }

            app(SiteHealth::class)->forget();
        } catch (Throwable $e) {
            report($e);
        }

        Notification::make()->title($message($result))->success()->send();

        return true;
    }

    protected function who(): ?string
    {
        $user = auth()->user();

        return $user ? (string) ($user->name ?? $user->email ?? $user->getAuthIdentifier()) : null;
    }
}
