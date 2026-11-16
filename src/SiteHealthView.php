<?php

namespace Porro\Filament;

use Carbon\Carbon;
use Porro\Core\Health\Issue;
use Porro\Laravel\Activity\Presenter;
use Porro\Laravel\Connect\PanelConnect;
use Porro\Laravel\SiteHealth;
use Porro\Laravel\SiteKeys;
use Throwable;

/**
 * View data for the Site Health and Blocking pages and the widget.
 */
class SiteHealthView
{
    public const SEVERITIES = [
        Issue::CRITICAL => ['label' => 'Act now', 'color' => 'danger'],
        Issue::WARNING => ['label' => 'Needs attention', 'color' => 'warning'],
        Issue::NOTICE => ['label' => 'Notice', 'color' => 'gray'],
    ];

    public function __construct(private SiteHealth $health, private Presenter $presenter, private PanelConnect $connect, private SiteKeys $keys) {}

    /**
     * @param  int|null  $limit  how many issues to list, for the widget
     * @return array<string, mixed>
     */
    public function data(?int $limit = null): array
    {
        try {
            $health = $this->health->get();
        } catch (Throwable $e) {
            report($e);
            $health = null;
        }

        $porro = $health['porro'] ?? null;
        $all = Issue::sort(array_map(fn ($i) => Issue::fromArray($i), [...($porro['findings'] ?? []), ...($health['issues'] ?? [])]));

        return [
            'failed' => $health === null,
            'connected' => (bool) ($health['connected'] ?? false),
            'porro' => $porro,
            'porroOk' => (bool) ($porro['ok'] ?? false),
            'porroUrl' => $porro['site_url'] ?? null,
            'local' => $this->present($health['issues'] ?? []),
            'found' => $this->present($porro['findings'] ?? []),
            'top' => $this->present(array_map(fn (Issue $i) => $i->toArray(), $limit ? array_slice($all, 0, $limit) : $all)),
            'more' => $limit ? max(0, count($all) - $limit) : 0,
            'counts' => $health['counts'] ?? ['critical' => 0, 'warning' => 0, 'notice' => 0],
            'checked' => $health['checked'] ?? [],
            'unavailable' => $health['unavailable'] ?? [],
            'checkedAgo' => self::ago($health['checked_at'] ?? null),
            'commands' => $health['commands'] ?? ['connect' => 'php artisan porro:connect <site key>', 'cron' => 'php artisan porro:cron'],
            'activity' => $this->presenter->activity($health['activity'] ?? null),
        ];
    }

    /**
     * The "Connect to Porro" panel at the top of Site Health.
     *
     * The live site with no key shows the button, to whoever may connect.
     * A copy that isn't production shows why it doesn't connect instead.
     * A site connected from here says who to, and whoever may connect can
     * disconnect it. A site with PORRO_KEY in its environment has no panel:
     * it's connected the way it always was, and only reads how to undo that.
     * A site whose key Porro turned away says so until it connects again.
     *
     * @return array{panel: bool, upsell: bool, state: string, environment: string, porroHost: string, workspace: string|null, host: string|null, approvedAgo: string|null, openUrl: string, canConnect: bool, canDisconnect: bool, envKey: bool, turnedAway: array{workspace: string|null, date: string|null, time: string|null}|null}
     */
    public function connect(bool $connected, ?string $siteUrl, bool $canConnect): array
    {
        $state = $this->connect->state();
        $stored = $state === PanelConnect::CONNECTED ? $this->keys->stored() : null;
        $turnedAway = in_array($state, [PanelConnect::READY, PanelConnect::DEVELOPMENT, PanelConnect::OFF], true) ? self::turnedAway($this->keys->turnedAway()) : null;
        $panel = match ($state) {
            PanelConnect::READY => $canConnect || $turnedAway !== null,
            PanelConnect::DEVELOPMENT, PanelConnect::CONNECTED => true,
            PanelConnect::OFF => $turnedAway !== null,
            default => false,
        };

        return [
            'panel' => $panel,
            // The "run this command" copy, for whoever sees no panel and isn't connected.
            'upsell' => ! $panel && ! $connected,
            'state' => $state,
            'environment' => (string) app()->environment(),
            'porroHost' => self::porroHost(),
            'workspace' => $stored['workspace'] ?? null,
            'host' => $stored['host'] ?? null,
            'approvedAgo' => self::since($stored['connected_at'] ?? null),
            'openUrl' => $siteUrl ?? rtrim((string) config('porro.url'), '/'),
            'canConnect' => $canConnect,
            // Only a key kept here can be removed here, by whoever may connect.
            'canDisconnect' => $canConnect && $state === PanelConnect::CONNECTED,
            'envKey' => $state === PanelConnect::ENV && $connected,
            'turnedAway' => $turnedAway,
        ];
    }

    /**
     * When Porro turned the stored key away, in the site's own timezone.
     *
     * @param  array{workspace: string|null, at: string|null}|null  $turnedAway
     * @return array{workspace: string|null, date: string|null, time: string|null}|null
     */
    private static function turnedAway(?array $turnedAway): ?array
    {
        if ($turnedAway === null) {
            return null;
        }

        $at = $turnedAway['at'] !== null && strtotime($turnedAway['at']) !== false
            ? Carbon::parse($turnedAway['at'])->setTimezone((string) config('app.timezone', 'UTC'))
            : null;

        return [
            'workspace' => $turnedAway['workspace'],
            'date' => $at?->translatedFormat('j F'),
            'time' => $at?->format('H:i'),
        ];
    }

    /**
     * Where the button takes the browser, as people know it: "porro.dev".
     */
    private static function porroHost(): string
    {
        $host = (string) parse_url((string) config('porro.url'), PHP_URL_HOST);

        return $host === 'app.porro.dev' || $host === '' ? 'porro.dev' : $host;
    }

    private static function since(?string $at): ?string
    {
        if (! $at || strtotime($at) === false) {
            return null;
        }

        $at = Carbon::parse($at);

        return $at->gt(now()->subMinute()) ? __('a moment ago') : $at->diffForHumans();
    }

    /**
     * The Blocking page.
     *
     * @return array<string, mixed>
     */
    public function blocking(): array
    {
        return $this->presenter->blocking();
    }

    private static function ago(?string $at): ?string
    {
        if (! $at) {
            return null;
        }

        $at = Carbon::parse($at);

        return $at->gt(now()->subMinute()) ? __('just now') : $at->diffForHumans();
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return list<array<string, mixed>>
     */
    private function present(array $issues): array
    {
        return array_map(fn (array $issue) => $issue + ['style' => self::SEVERITIES[$issue['severity']] ?? self::SEVERITIES[Issue::NOTICE]], $issues);
    }
}
