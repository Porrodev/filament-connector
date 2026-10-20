# porro/filament-connector

[Porro](https://porro.dev) connector for Filament 3–5 sites. It includes [`porro/laravel-connector`](https://github.com/Porrodev/laravel-connector) and adds Filament's own details to each report. Read-only: it never changes the site and never accepts commands.

## Install

```bash
composer require porro/filament-connector
php artisan porro:connect <site key>
```

Copy the site key from the site's page in Porro. Reports then go every 15 minutes on the scheduler; see the [Laravel connector](https://github.com/Porrodev/laravel-connector) for config and commands.

## What it adds

- Filament and Livewire versions
- Installed Filament plugins and their versions
- Each panel's id, path, whether it has a login, and (from Filament 4) whether multi-factor authentication is required

Everything the Laravel connector sends is included too. Run `php artisan porro:status --json` to see the exact report.

## Developing

```bash
composer install
composer test
```

## Security

Please email security@porro.dev rather than opening an issue. We reply within one working day.

## License

MIT
