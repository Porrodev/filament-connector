<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\Recorder;
use Porro\Filament\Pages\BlockingPage;
use Porro\Filament\Pages\NginxPage;
use Porro\Filament\Pages\SiteHealthPage;
use Porro\Filament\PorroPlugin;
use Porro\Laravel\Activity\Activity;
use Porro\Laravel\Activity\CloudflareMode;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['*' => Http::response([], 200)]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('sits next to Site Health under Porro', function () {
    expect(Filament::getPanel('admin')->getPages())->toContain(BlockingPage::class)
        ->and(BlockingPage::getNavigationLabel())->toBe('Blocking')
        ->and(BlockingPage::getNavigationGroup())->toBe('Porro')
        ->and(BlockingPage::getSlug())->toBe('blocking');
});

it('names both pages after Porro in a group you pick', function () {
    SiteHealthPage::configure('Settings', null);
    BlockingPage::configure('Settings', null);

    expect(SiteHealthPage::getNavigationLabel())->toBe('Porro Site Health')
        ->and(BlockingPage::getNavigationLabel())->toBe('Porro Blocking')
        ->and(BlockingPage::getNavigationGroup())->toBe('Settings');

    SiteHealthPage::configure(null, null);
    BlockingPage::configure(null, null);
});

it('lists the blocklist, safelist and protected ranges', function () {
    config(['porro.blocklist.safelist' => [['cidr' => '81.2.69.0/24', 'note' => 'Office']]]);
    DB::table('porro_blocklist')->insert((new Entry('103.75.190.0/24', 'Scanner network', Entry::MANUAL, time(), null, 'Ada'))->toArray());
    $this->actingAs(panelUser());

    Livewire::test(BlockingPage::class)
        ->assertSuccessful()
        ->assertSee('Where blocks are enforced')
        ->assertSee('103.75.190.0/24')
        ->assertSee('Scanner network')
        ->assertSee('81.2.69.0/24')
        ->assertSee('From config')
        ->assertSee('Laravel Forge')
        ->assertSee('Set up nginx');
});

it('blocks from the form and turns the IP away', function () {
    $this->actingAs(panelUser());

    Livewire::test(BlockingPage::class)
        ->set('ip', '185.220.101.4')
        ->set('reason', 'Scanner')
        ->set('days', 7)
        ->call('addBlock')
        ->assertNotified('Blocked 185.220.101.4.')
        ->assertSet('ip', '')
        ->assertSee('185.220.101.4');

    expect(DB::table('porro_blocklist')->value('note'))->toBe('Scanner');

    $this->withServerVariables(['REMOTE_ADDR' => '185.220.101.4'])->get('/')->assertForbidden();
});

it('explains a refusal and keeps what was typed', function () {
    $this->actingAs(panelUser());

    Livewire::test(BlockingPage::class)
        ->set('ip', '159.203.150.232')
        ->call('addBlock')
        ->assertNotified('159.203.150.232 is a Laravel Forge address, which has to reach this site.')
        ->assertSet('ip', '159.203.150.232');

    expect(DB::table('porro_blocklist')->count())->toBe(0);
});

it('safelists with a label and asks before a wide range', function () {
    $this->actingAs(panelUser());

    Livewire::test(BlockingPage::class)
        ->set('safeIp', '5.0.0.0/8')->set('safeLabel', 'ISP')
        ->call('addSafe')
        ->assertNotified('5.0.0.0/8 covers 16,777,216 addresses. Confirm to safelist all of them.')
        ->set('confirmWide', true)
        ->call('addSafe')
        ->assertNotified('Safelisted 5.0.0.0/8.');

    expect(DB::table('porro_safelist')->pluck('cidr')->all())->toBe(['5.0.0.0/8']);
});

it('removes entries, but not safelist entries from config', function () {
    config(['porro.blocklist.safelist' => ['81.2.69.142']]);
    DB::table('porro_blocklist')->insert((new Entry('185.220.101.4', 'x', Entry::MANUAL, time()))->toArray());
    $this->actingAs(panelUser());

    Livewire::test(BlockingPage::class)
        ->call('unblockIp', '185.220.101.4')
        ->assertNotified('Unblocked 185.220.101.4.')
        ->call('unsafelistIp', '81.2.69.142')
        ->assertNotified('81.2.69.142 comes from the site’s config, so it can only be removed there.');

    expect(DB::table('porro_blocklist')->count())->toBe(0);
});

it('prefills the safelist from a flagged IP', function () {
    $this->actingAs(panelUser());

    // The component, not the full panel page: Filament 3's layout needs
    // a whole app around it to render.
    Livewire::withQueryParams(['safelist' => '81.2.69.142'])
        ->test(BlockingPage::class)
        ->assertSet('safeIp', '81.2.69.142');

    Livewire::withQueryParams(['safelist' => 'not-an-ip'])
        ->test(BlockingPage::class)
        ->assertSet('safeIp', '');
});

it('lets viewers look but only managers change the lists', function () {
    Gate::define(PorroPlugin::MANAGE_GATE, fn ($user) => $user->email === 'boss@example.com');
    $this->actingAs(panelUser());

    Livewire::test(BlockingPage::class)
        ->assertSuccessful()
        ->assertDontSee('Wide range is OK')
        ->call('blockIp', '185.220.101.4')
        ->assertForbidden();

    $this->actingAs(panelUser(['email' => 'boss@example.com']));
    Livewire::test(BlockingPage::class)->assertSee('Wide range is OK');
});

it('is hidden from people who can’t see Site Health', function () {
    $this->actingAs(plainUser());

    expect(BlockingPage::canAccess())->toBeFalse();
});

describe('activity on Site Health', function () {
    beforeEach(function () {
        $recorder = app(Activity::class)->recorder();

        foreach (['/.env', '/.git/config', '/backup.zip'] as $path) {
            $recorder->record('185.220.101.4', Recorder::PROBE, 'GET', $path, 404, country: 'DE');
        }
    });

    it('lists flagged IPs with their country and block buttons', function () {
        $this->actingAs(panelUser());

        Livewire::test(SiteHealthPage::class)
            ->assertSee('Suspicious activity')
            ->assertSee('185.220.101.4')
            ->assertSee('🇩🇪 DE')
            ->assertSee('probe 3')
            ->assertSee('Block 7 days')
            ->assertSee('1 IP probing for secrets and admin tools');
    });

    it('renders buttons the browser can run', function () {
        $this->actingAs(panelUser());

        // Blade doesn't compile @js inside a component's attributes; it
        // would reach the browser as text and Livewire couldn't parse it.
        $html = html_entity_decode(Livewire::test(SiteHealthPage::class)->html(), ENT_QUOTES);

        expect($html)->not->toContain('@js(')
            // The reason's ×, and how it's escaped, varies by Laravel version.
            ->toContain("wire:click=\"blockIp('185.220.101.4', 7, '");

        DB::table('porro_blocklist')->insert((new Entry('103.75.190.11', 'x', Entry::MANUAL, time()))->toArray());

        expect(html_entity_decode(Livewire::test(BlockingPage::class)->html(), ENT_QUOTES))
            ->not->toContain('@js(')
            ->toContain("wire:click=\"unblockIp('103.75.190.11')\"");
    });

    it('blocks for 7 days as a suggestion, then shows it as blocked', function () {
        $this->actingAs(panelUser());

        Livewire::test(SiteHealthPage::class)
            ->call('blockIp', '185.220.101.4', 7, 'Flagged: probe ×3', true)
            ->assertNotified('Blocked 185.220.101.4.')
            ->assertSee('Blocked')
            ->assertDontSee('Block 7 days');

        $row = DB::table('porro_blocklist')->first();

        expect($row->source)->toBe('suggested')
            ->and((int) $row->expires_at)->toBeGreaterThan(time() + 6 * 86400);
    });
});

it('shows Cloudflare WAF mode on the Blocking page', function () {
    $this->actingAs(panelUser());

    Livewire::test(BlockingPage::class)->assertSee('Cloudflare WAF')->assertSee('Not set up')->assertSee('PORRO_CLOUDFLARE_TOKEN=your-token-here');

    config(['porro.blocklist.cloudflare_token' => 'token']);
    Livewire::test(BlockingPage::class)->assertSee('Token set, not connected')->assertSee('php artisan porro:cloudflare connect');

    $this->app['env'] = 'production';
    Cache::forever(CloudflareMode::STATE, [
        'connection' => ['zone_name' => 'dogearbooks.example'],
        'synced_at' => time() - 120,
        'result' => ['added' => 1, 'removed' => 0, 'shared' => 2],
        'error' => null,
    ]);
    Livewire::test(BlockingPage::class)->assertSee('Synced 2 minutes ago')->assertSee('zone dogearbooks.example')->assertSee('2 already blocked by other sites');

    Cache::forever(CloudflareMode::STATE, [
        'connection' => ['zone_name' => 'dogearbooks.example'],
        'error' => 'Cloudflare refused the token <b>',
    ]);
    Livewire::test(BlockingPage::class)->assertSee('Sync failed')->assertSee('Cloudflare refused the token <b>')->assertDontSeeHtml('token <b>');
});

describe('nginx setup', function () {
    beforeEach(function () {
        $this->dir = sys_get_temp_dir().'/porro-nginx-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/sites-enabled', 0755, true);
        $this->file = $this->dir.'/nginx-blocklist.conf';
        config([
            'porro.blocklist.nginx' => true,
            'porro.blocklist.nginx_path' => $this->file,
            'porro.blocklist.nginx_config_dirs' => [$this->dir.'/sites-enabled'],
            'porro.blocklist.nginx_stamp' => $this->dir.'/stamp',
        ]);
        $this->actingAs(panelUser());
    });

    it('is reached from Blocking, not the navigation', function () {
        expect(Filament::getPanel('admin')->getPages())->toContain(NginxPage::class)
            ->and(NginxPage::shouldRegisterNavigation())->toBeFalse();

        Livewire::test(BlockingPage::class)->assertSee('Set up nginx');
    });

    it('shows the steps with this site’s paths, and each server', function () {
        Livewire::test(NginxPage::class)
            ->assertSee("include {$this->file};")
            ->assertSee('No server has reported yet');

        $this->app['env'] = 'production';
        file_put_contents($this->dir.'/sites-enabled/site', "server {\n    include {$this->file};\n}\n");
        $this->artisan('porro:blocklist-sync')->assertSuccessful();
        touch($this->dir.'/stamp', time() + 5);
        $this->artisan('porro:blocklist-sync')->assertSuccessful();

        Livewire::test(NginxPage::class)->assertSee('Loaded')->assertSee('reloaded');
        Livewire::test(BlockingPage::class)->assertSee('1 of 1 server loaded')->assertSee('Setup and servers');
    });

    it('is hidden from people who can’t see Site Health', function () {
        $this->actingAs(plainUser());

        expect(NginxPage::canAccess())->toBeFalse();
    });
});
