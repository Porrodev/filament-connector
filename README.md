# Porro Site Health for Filament

A Porro Site Health page for Filament 3, 4 and 5 panels. It checks the app for the things that quietly go wrong between projects (security advisories in your Composer packages, PHP and Laravel versions running out of security support, Filament, Livewire and Laravel updates, a scheduler that never runs) and tells you what to do about each.

It's free and works on its own. If you use [Porro](https://porro.dev) to look after your sites, connecting it adds what can only be seen from outside: uptime, SSL certificates, domain expiry and the server's operating system.

![The Porro Site Health page in a Filament panel on a site that isn't connected to Porro: a migration not run, the scheduler not running and three failed jobs, with the commands that fix them](docs/images/filament-site-health.png)

## Install

```bash
composer require porro/filament-connector
```

Add the plugin to the panel you want it in, usually your admin panel:

```php
use Porro\Filament\PorroPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(PorroPlugin::make());
}
```

That adds a **Porro Site Health** page, with the Porro mark in the navigation, and a lazy-loaded dashboard widget. No assets to publish or build.

<img src="docs/images/filament-widget.png" alt="The Porro Site Health dashboard widget" width="400">

### Options

```php
PorroPlugin::make()
    ->widget(false)                 // no dashboard widget
    ->navigationGroup('Settings')   // where the page sits in the navigation
    ->navigationSort(90)
    ->authorize(fn ($user) => $user->can('view site health'));
```

### Who can see it

In order:

1. Your `authorize()` callback, if you give one.
2. A `viewPorroSiteHealth` gate, if your app defines one: `Gate::define('viewPorroSiteHealth', fn (User $user) => $user->is_admin);`
3. Admins, when your users have the usual markers: an `isAdmin()` or `isSuperAdmin()` method, an `is_admin` attribute, or a spatie/laravel-permission `super_admin` or `admin` role.
4. Otherwise, everyone who can use the panel. Only add the plugin to panels for your own team, not to a customer-facing panel, unless you restrict it as above.

The page, the widget and **Check again** all check this on the server.

## What it checks

On the app itself, with no account:

| Check | How |
|---|---|
| Security advisories in `composer.lock` | [Packagist's security advisories API](https://packagist.org/apidoc#list-security-advisories), the one `composer audit` uses. Only package names are sent; versions are matched on your server. High and critical advisories show as **Act now**. |
| PHP and Laravel support | Past, or within 90 days of, the end of security support, from [endoflife.date](https://endoflife.date). A copy ships with the package for when it can't be reached. |
| Updates | Newer Filament, Livewire and Laravel releases on the major versions you run, from Packagist |
| Scheduler | Whether `schedule:run` is running, from a heartbeat the package schedules every 15 minutes, and whether the crontab has the line. `php artisan porro:cron` adds it. |
| Debug mode, migrations, failed jobs | `APP_DEBUG` on in production, migrations not run, failed jobs in the last 24 hours |
| Backups and health checks | Stale backups from `spatie/laravel-backup` and failing `spatie/laravel-health` checks, if you use them |

Results are cached for five minutes, and the public data behind them for between six hours and a day. **Check again** runs everything now.

## Connect Porro (optional)

Porro watches every site you look after from outside: uptime from several regions, SSL and domain expiry, the server's OS and security updates, with alerts by email or Slack. It's a separate service with its own account; see [porro.dev](https://porro.dev) for plans. Nothing in this package needs it, and nothing is sent to Porro until you connect. Add the site in Porro, then:

```bash
php artisan porro:connect <site key>
```

The page then shows the site's open findings in Porro alongside the checks above, each linking to Porro.

![Porro Site Health connected to Porro, in dark mode: an end-of-life server, an expiring certificate and domain, and a slow region, above the checks run on the site](docs/images/filament-site-health-dark.png)

Connecting also sends Porro a signed report every 15 minutes on Laravel's scheduler: Filament, Livewire, plugin and package versions, each panel's id, path, whether it has a login and (from Filament 4) whether multi-factor authentication is required, plus the health facts above. Never content, users or settings values. Run `php artisan porro:status --json` to see exactly what would be sent, and see the [Laravel connector](https://github.com/Porrodev/laravel-connector) for config and commands.

`porro:connect` offers to add the scheduler's cron line; `php artisan porro:cron` adds it on its own.

## Requirements

- Filament 3.2, 4 or 5, Laravel 11–13, PHP 8.2+
- Outbound HTTPS to `packagist.org`, `repo.packagist.org` and `endoflife.date` (and `app.porro.dev` once connected). Without it, the checks that need them say so and the rest still run.

## Demo

[Dog-ear Books](https://github.com/Porrodev/filament-demo) is a demo app with the plugin set up and things for it to find, a stand-in Porro for the connected view, and the end-to-end checks and screenshot script behind these images. `docs/images/filament-thumbnail.png` is the plugin directory's 2560 × 1440 thumbnail.

## External requests

| To | When | What's sent |
|---|---|---|
| `packagist.org` | Advisory check, at most every 6 hours or when `composer.lock` changes | Package names from `composer.lock` |
| `repo.packagist.org` | Update check, at most twice a day per package | Nothing beyond the request |
| `endoflife.date` | Support dates, at most once a day | Nothing beyond the request |
| `app.porro.dev` | Only once connected: reports every 15 minutes, findings when the page is open (cached 5 minutes) | Signed with the site key |

## Support

Questions and bugs: [GitHub issues](https://github.com/Porrodev/filament-connector/issues). Security issues: please email security@porro.dev rather than opening an issue. We reply within one working day.

## Developing

```bash
composer install
composer test
```

## License

MIT. See [CHANGELOG.md](CHANGELOG.md) for what changed.
