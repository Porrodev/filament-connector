<?php

namespace Porro\Filament;

use Carbon\Carbon;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\NginxBlocklist;
use Porro\Core\Activity\ProtectedRanges;
use Porro\Core\Activity\Recorder;
use Porro\Core\Health\Issue;
use Porro\Laravel\Activity\Activity;
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

    /** How each signal reads on a badge. */
    public const SIGNALS = [Recorder::PROBE => 'probe', Recorder::LOGIN_FAILED => 'login', Recorder::NOT_FOUND => '404', Recorder::POST_FLOOD => 'form'];

    public function __construct(private SiteHealth $health, private Activity $activity) {}

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
            'activity' => $this->activity($health['activity'] ?? null),
        ];
    }

    /**
     * The Suspicious activity section: totals, and each flagged IP ready
     * to show.
     *
     * @return array<string, mixed>|null
     */
    private function activity(mixed $activity): ?array
    {
        if (! is_array($activity)) {
            return null;
        }

        $flagged = [];

        foreach (is_array($activity['flagged'] ?? null) ? $activity['flagged'] : [] as $ip) {
            if (! is_array($ip) || ! is_string($ip['ip'] ?? null) || filter_var($ip['ip'], FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $signals = [];

            foreach (self::SIGNALS as $signal => $label) {
                if (($n = (int) ($ip['counts'][$signal] ?? 0)) > 0) {
                    $signals[] = ['label' => $label, 'count' => $n];
                }
            }

            usort($signals, fn ($a, $b) => $b['count'] <=> $a['count']);

            $flagged[] = [
                'ip' => $ip['ip'],
                'country' => self::country($ip['country'] ?? null),
                'signals' => $signals,
                'request' => trim(((string) ($ip['last_method'] ?? '')).' '.((string) ($ip['last_path'] ?? ''))),
                'seen' => isset($ip['last_seen']) ? Carbon::createFromTimestamp((int) $ip['last_seen'])->diffForHumans() : null,
                'blocked' => $this->isBlocked($ip['ip']),
                'reason' => __('Flagged: :signals', ['signals' => implode(', ', array_map(fn ($s) => "{$s['label']} ×{$s['count']}", $signals))]),
            ];
        }

        $totals = is_array($activity['totals'] ?? null) ? $activity['totals'] : [];

        return [
            'recording' => (bool) ($activity['recording'] ?? false),
            'hours' => (int) ($activity['hours'] ?? 24),
            'totals' => [
                ['label' => __('Probes for secrets and admin tools'), 'value' => (int) ($totals[Recorder::PROBE] ?? 0)],
                ['label' => __('Failed logins'), 'value' => (int) ($totals[Recorder::LOGIN_FAILED] ?? 0)],
                ['label' => __('404s'), 'value' => (int) ($totals[Recorder::NOT_FOUND] ?? 0)],
                ['label' => __('Form posts'), 'value' => (int) ($totals[Recorder::POST_FLOOD] ?? 0)],
            ],
            'flagged' => $flagged,
            'blocked' => (int) ($activity['blocked'] ?? 0),
            'safelisted' => (int) ($activity['safelisted'] ?? 0),
            'countries' => is_array($activity['countries'] ?? null) ? $activity['countries'] : null,
        ];
    }

    /**
     * The Blocking page.
     *
     * @return array<string, mixed>
     */
    public function blocking(): array
    {
        $now = time();

        try {
            $list = $this->activity->blocklist();
            $blocks = $list->blocks($now);
            $safe = $list->safe($now);
            $failed = false;
        } catch (Throwable $e) {
            report($e);
            [$blocks, $safe, $failed] = [[], [], true];
        }

        $ranges = $this->activity->ranges()->ranges();
        $nginxPath = (string) config('porro.blocklist.nginx_path');

        return [
            'failed' => $failed,
            'blocks' => array_map(fn (Entry $e) => $this->entry($e), $blocks),
            'safe' => array_map(fn (Entry $e) => $this->entry($e), $safe),
            'enforcement' => [
                'app' => $this->activity->enforcing(),
                'nginx' => (bool) config('porro.blocklist.nginx'),
                'production' => $this->activity->isProduction(),
                'nginxWritten' => is_file($nginxPath) ? Carbon::createFromTimestamp((int) filemtime($nginxPath))->diffForHumans() : null,
                'include' => "include {$nginxPath};",
                'realIp' => NginxBlocklist::realIp($this->activity->ranges()),
                'reload' => sprintf('f=%s; s=/var/tmp/porro-nginx.stamp; [ "$f" -nt "$s" ] && nginx -t -q && systemctl reload nginx && touch "$s"', $nginxPath),
            ],
            'protected' => array_values(array_filter(array_map(fn (string $source) => [
                'label' => ProtectedRanges::label($source),
                'count' => count($ranges[$source] ?? []),
            ], array_keys($ranges)), fn ($row) => $row['count'] > 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Entry $entry): array
    {
        return [
            'cidr' => $entry->cidr,
            'note' => $entry->note,
            'source' => match ($entry->source) {
                Entry::SUGGESTED => __('Suggested'),
                Entry::CONFIG => __('From config'),
                default => __('Manual'),
            },
            'by' => $entry->addedBy,
            'expires' => $entry->expiresAt ? Carbon::createFromTimestamp($entry->expiresAt)->diffForHumans(['parts' => 1]) : __('never'),
            'fromConfig' => $entry->source === Entry::CONFIG,
        ];
    }

    private function isBlocked(string $ip): bool
    {
        try {
            return $this->activity->blocked($ip) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * "🇩🇪 DE", or "Tor" for Cloudflare's T1. Null when unknown.
     *
     * @return array{code: string, label: string}|null
     */
    public static function country(mixed $code): ?array
    {
        $code = Recorder::country(is_string($code) ? $code : null);

        if ($code === null) {
            return null;
        }

        if ($code === 'T1') {
            return ['code' => 'T1', 'label' => 'Tor'];
        }

        $flag = ctype_alpha($code) ? mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65) : '';

        return ['code' => $code, 'label' => trim("{$flag} {$code}")];
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
