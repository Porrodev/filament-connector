<?php

namespace Porro\Filament;

use Carbon\Carbon;
use Porro\Core\Health\Issue;
use Porro\Laravel\Activity\Presenter;
use Porro\Laravel\SiteHealth;
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

    public function __construct(private SiteHealth $health, private Presenter $presenter) {}

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
