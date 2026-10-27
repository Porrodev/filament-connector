<?php

use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Porro\Filament\Pages\SiteHealthPage;
use Porro\Filament\PorroPlugin;
use Porro\Filament\Widgets\SiteHealthWidget;

beforeEach(function () {
    Http::fake([
        'endoflife.date/*' => Http::response([['cycle' => '13', 'eol' => '2028-03-01'], ['cycle' => '12', 'eol' => '2027-02-24'], ['cycle' => '8.5', 'eol' => '2029-12-31'], ['cycle' => '8.4', 'eol' => '2028-12-31'], ['cycle' => '8.3', 'eol' => '2027-12-31'], ['cycle' => '8.2', 'eol' => '2026-12-31']]),
        'packagist.org/api/security-advisories/' => Http::response(['advisories' => []]),
        'repo.packagist.org/p2/filament/filament.json' => Http::response(['packages' => ['filament/filament' => [['version' => 'v99.0.0'], ['version' => 'v3.99.0'], ['version' => 'v4.99.0'], ['version' => 'v5.99.0']]]]),
        'repo.packagist.org/*' => Http::response(['packages' => []]),
        'porro.test/api/v1/findings' => Http::response(['ok' => true, 'site' => ['url' => 'https://porro.test/acme/sites/example'], 'findings' => [
            ['kind' => 'ssl_expiring', 'rank' => 3, 'title' => 'SSL certificate expires in 9 days', 'scope' => 'site', 'url' => 'https://porro.test/acme/sites/example'],
        ]]),
    ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * An admin by default (is_admin), so they can see the page.
 */
function panelUser(array $attributes = []): User
{
    $user = new User;
    $user->forceFill($attributes + ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.com', 'is_admin' => true]);

    return $user;
}

/**
 * A user with none of the admin markers the plugin knows.
 */
function plainUser(): User
{
    $user = new User;
    $user->forceFill(['id' => 2, 'name' => 'Bea', 'email' => 'bea@example.com']);

    return $user;
}

it('adds the Site health page to the panel', function () {
    expect(Filament::getPanel('admin')->getPages())->toContain(SiteHealthPage::class)
        ->and(Filament::getPanel('admin')->getWidgets())->toContain(SiteHealthWidget::class)
        ->and(SiteHealthPage::getSlug())->toBe('site-health')
        ->and(SiteHealthPage::getNavigationLabel())->toBe('Site Health')
        ->and(SiteHealthPage::getNavigationGroup())->toBe('Porro')
        ->and(SiteHealthPage::getNavigationIcon())->toBe('porro-mark');
});

it('leaves the panel’s other pages alone', function () {
    expect(Dashboard::getNavigationLabel())->not->toBe('Porro Site Health')
        ->and(Dashboard::getNavigationIcon())->not->toBe('porro-mark');
});

it('shows the site’s health without a Porro account, with the connect command', function () {
    config(['porro.key' => null]);
    $this->actingAs(panelUser());

    Livewire::test(SiteHealthPage::class)
        ->assertSuccessful()
        ->assertSee('Porro Site Health')
        ->assertSee('Checked on this site')
        ->assertSee('Filament '.strtok((string) InstalledVersions::getVersion('filament/filament'), '.').'.99.0 is available')
        ->assertSee('Scheduler isn’t running')
        ->assertSee('Watch this site from outside too')
        ->assertSee('php artisan porro:connect <site key>')
        ->assertDontSee('From Porro');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'porro.test'));
});

it('adds the site’s Porro findings once connected', function () {
    config(['porro.key' => siteKey()]);
    $this->actingAs(panelUser());

    Livewire::test(SiteHealthPage::class)
        ->assertSee('From Porro')
        ->assertSee('SSL certificate expires in 9 days')
        ->assertSee('https://porro.test/acme/sites/example')
        ->assertDontSee('Watch this site from outside too');
});

it('checks again on request', function () {
    config(['porro.key' => siteKey()]);
    $this->actingAs(panelUser());

    $page = Livewire::test(SiteHealthPage::class);
    $before = count(Http::recorded());

    $page->call('checkAgain')->assertSuccessful();

    // Porro is asked again; the public data stays cached.
    expect(count(Http::recorded()))->toBe($before + 1);
});

it('lets admins in by default and keeps other users out', function () {
    $this->actingAs(panelUser(['is_admin' => false]));
    expect(SiteHealthPage::canAccess())->toBeFalse()
        ->and(SiteHealthWidget::canView())->toBeFalse();

    $this->actingAs(panelUser(['is_admin' => true]));
    expect(SiteHealthPage::canAccess())->toBeTrue();

    auth()->logout();
    expect(SiteHealthPage::canAccess())->toBeFalse();
});

it('keeps everyone out when it can’t tell who’s an admin', function () {
    $this->actingAs(plainUser());

    expect(SiteHealthPage::canAccess())->toBeFalse()
        ->and(SiteHealthWidget::canView())->toBeFalse();

    Livewire::test(SiteHealthPage::class)->assertForbidden();
});

it('lets the app open it up with the gate or authorize()', function () {
    $this->actingAs(plainUser());

    Gate::define(PorroPlugin::GATE, fn ($user) => $user->email === 'bea@example.com');
    expect(SiteHealthPage::canAccess())->toBeTrue();

    PorroPlugin::current()->authorize(fn () => false);
    expect(SiteHealthPage::canAccess())->toBeFalse();

    PorroPlugin::current()->authorize(fn () => true);
    expect(SiteHealthPage::canAccess())->toBeTrue()
        ->and(SiteHealthWidget::canView())->toBeTrue();
});

it('uses a viewPorroSiteHealth gate when the app defines one', function () {
    Gate::define(PorroPlugin::GATE, fn ($user) => $user->email === 'ops@example.com');

    $this->actingAs(panelUser(['is_admin' => true]));
    expect(SiteHealthPage::canAccess())->toBeFalse();

    $this->actingAs(panelUser(['email' => 'ops@example.com']));
    expect(SiteHealthPage::canAccess())->toBeTrue();
});

it('uses the authorize callback over everything else', function () {
    PorroPlugin::current()->authorize(fn ($user) => $user->name === 'Grace');

    $this->actingAs(panelUser(['is_admin' => true]));
    expect(SiteHealthPage::canAccess())->toBeFalse();

    $this->actingAs(panelUser(['name' => 'Grace']));
    expect(SiteHealthPage::canAccess())->toBeTrue();
});

it('blocks checking again for users who can’t see the page', function () {
    $this->actingAs(panelUser(['is_admin' => true]));
    $page = Livewire::test(SiteHealthPage::class);

    $this->actingAs(panelUser(['is_admin' => false]));
    $page->call('checkAgain')->assertForbidden();
});

it('shows a dashboard widget with the top issues', function () {
    config(['porro.key' => siteKey()]);
    $this->actingAs(panelUser());

    Livewire::test(SiteHealthWidget::class)
        ->assertSee('Porro Site Health')
        ->assertSee('SSL certificate expires in 9 days')
        ->assertSee(SiteHealthPage::getUrl());
});
