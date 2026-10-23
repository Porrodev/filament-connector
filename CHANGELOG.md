# Changelog

## 0.2.0 - 2026-10-23

- **Porro Site Health**: `PorroPlugin` adds a Porro Site Health page, with the Porro mark as its icon, and a lazy dashboard widget to a panel. Without a Porro account they check security advisories in `composer.lock`, PHP and Laravel support dates, Filament, Livewire and Laravel updates, the scheduler, debug mode, migrations, failed jobs, backups and health checks. Once connected they add the site's open Porro findings with links into Porro.
- Access: an `authorize()` callback, a `viewPorroSiteHealth` gate, or admins by the usual conventions.
- Works on Filament 3, 4 and 5 with no assets to build.
- The scheduler writes a heartbeat even before the site is connected, so the page can tell whether cron runs.
- Requires `porro/laravel-connector` 0.2.

## 0.1.2

- Requires laravel-connector ^0.1.2 (`porro:cron`).

## 0.1.1

- Connect offers to set up cron.

## 0.1.0

- First release: Filament, Livewire and plugin versions plus panel checks in each Porro report.
