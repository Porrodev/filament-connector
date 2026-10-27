# Changelog

## Unreleased

- **The Blocking page shows Cloudflare WAF mode:** whether it's set up and connected, the zone, the last sync, IPs already blocked by another site on the account, and any error. Until it's connected, it shows the setup steps. Requires `porro/laravel-connector`'s Cloudflare WAF mode.
- **Navigation:** a **Porro** navigation group with **Site Health** and a new **Blocking** page. `navigationGroup()` still moves both; in your own group they're called "Porro Site Health" and "Porro Blocking".
- **Suspicious activity on Site Health:** probes, failed logins, 404 bursts and form floods, with each flagged IP's rough country and buttons to block or safelist it. An exposed `/.env` or `/.git` shows as **Act now**.
- **The Blocking page:** the blocklist, safelist and protected ranges. Blocks that would break the site are refused with the reason, shown as a notification.
- **Permissions:** `authorizeManage()` or a `managePorroBlocklist` gate limits who can change the lists. By default it's whoever can see the pages.
- Requires `porro/laravel-connector` 0.3. Run `php artisan migrate` for its blocklist tables.

## 0.2.1

- **Access is closed by default.** When there's no `authorize()` callback, no `viewPorroSiteHealth` gate and your users have no admin marker the plugin knows, nobody sees the Site Health page or widget. 0.2.0 showed it to every panel user in that case. See "Who can see it" in the README to open it up.
- Requires `porro/laravel-connector` 0.2.1 (requests to Porro signed for their method and path).

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
