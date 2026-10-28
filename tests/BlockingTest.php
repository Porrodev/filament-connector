<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Porro\Core\Activity\AbuseIpdb;
use Porro\Core\Activity\Cloudflare;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\IpDetails;
use Porro\Core\Activity\Recorder;
use Porro\Filament\Pages\BlockingPage;
use Porro\Filament\Pages\CloudflarePage;
use Porro\Filament\Pages\IpPage;
use Porro\Filament\Pages\ListPage;
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

it('opens each always-protected list to show its addresses and why', function () {
    $this->actingAs(panelUser());

    Livewire::test(BlockingPage::class)
        ->assertSee('Search engine crawlers')
        ->assertSee('drop the site from search')
        ->assertSee('173.245.48.0/20')
        ->assertSee('PORRO_PROTECT_CRAWLERS=false');
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
            ->toContain("wire:click=\"mountAction('confirmBlock', JSON.parse('");

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

    Livewire::test(BlockingPage::class)->assertSee('Cloudflare WAF')->assertSee('Not set up')->assertSee('Set up Cloudflare')->assertDontSee('Account Filter Lists');

    config(['porro.blocklist.cloudflare_token' => 'token']);
    Livewire::test(BlockingPage::class)->assertSee('Token set, not connected')->assertSee('Finish setting up');

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
            ->assertSeeInOrder(['1 · Turn on nginx mode', '2 · Add one line', '3 · Reload nginx'])
            ->assertSee('data-copy="include '.$this->file.';"', false)
            ->assertSee('add this line')
            ->assertSee('No server has checked in yet');

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

describe('IP details', function () {
    beforeEach(function () {
        // A fresh fake: the file's catch-all stub would otherwise answer first.
        Http::swap(new Factory);
        Http::fake([
            IpDetails::TOR_URL => Http::response("185.220.101.4\n"),
            'www.spamhaus.org/*' => Http::response(''),
            AbuseIpdb::URL.'*' => Http::response(['data' => ['abuseConfidenceScore' => 100, 'totalReports' => 2048]]),
            '*' => Http::response([], 200),
        ]);
        $this->app->instance('porro.reverse-dns', fn () => null);
        $this->actingAs(panelUser());
        $recorder = app(Activity::class)->recorder();

        foreach (['/.env', '/.git/config', '/backup.zip'] as $path) {
            $recorder->record('185.220.101.4', Recorder::PROBE, 'GET', $path, 404, country: 'DE', userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1)');
        }
    });

    it('opens from the activity table and explains a fake crawler', function () {
        $page = Livewire::test(SiteHealthPage::class)->assertSee(IpPage::getUrl().'?ip=185.220.101.4');

        // Ranked by what's known: the Tor list marks it in the table.
        expect(preg_replace('/\s+/', ' ', strip_tags($page->html())))->toContain('185.220.101.4 Tor');

        Livewire::withQueryParams(['ip' => '185.220.101.4'])
            ->test(IpPage::class)
            ->assertSee('🇩🇪 DE')
            ->assertSee('Tor exit')
            ->assertSee('Fake Googlebot')
            ->assertSee('Pretending to be Googlebot')
            ->assertSee('/backup.zip');
    });

    it('blocks from the details page', function () {
        Livewire::withQueryParams(['ip' => '185.220.101.4'])
            ->test(IpPage::class)
            ->call('blockIp', '185.220.101.4', 7, 'Flagged', true)
            ->assertNotified('Blocked 185.220.101.4.')
            ->assertSee('blocked')
            ->assertDontSee('Block permanently');
    });

    it('asks in Filament’s own modal before every block and safelist', function () {
        $page = Livewire::withQueryParams(['ip' => '185.220.101.4'])->test(IpPage::class);

        expect(substr_count($page->html(), 'wire:click="mountAction(\'confirm'))->toBe(3)
            ->and(substr_count(Livewire::test(SiteHealthPage::class)->html(), 'wire:click="mountAction(\'confirm'))->toBe(3);

        $page->mountAction('confirmBlock', ['ip' => '185.220.101.4', 'days' => 7, 'reason' => 'Flagged', 'suggested' => true])
            ->assertActionMounted('confirmBlock');

        $action = $page->instance()->getMountedAction();
        expect($action->getModalHeading())->toBe('Block 185.220.101.4 for 7 days?')
            ->and($action->getModalSubmitActionLabel())->toBe('Block for 7 days')
            ->and($action->getColor())->toBe('danger');

        // Nothing changes until it's confirmed.
        expect(DB::table('porro_blocklist')->where('cidr', '185.220.101.4')->exists())->toBeFalse();

        $page->callMountedAction()->assertNotified('Blocked 185.220.101.4.');
        expect(DB::table('porro_blocklist')->where('cidr', '185.220.101.4')->exists())->toBeTrue();
    });

    it('safelists in one click once confirmed', function () {
        Livewire::withQueryParams(['ip' => '185.220.101.4'])->test(IpPage::class)
            ->callAction('confirmSafelist', arguments: ['ip' => '185.220.101.4', 'label' => 'From the IP details page'])
            ->assertNotified('Safelisted 185.220.101.4.')
            ->assertSee('safelisted')
            ->assertDontSee('Block permanently');
    });

    it('marks a protected IP and offers no way to block it', function () {
        Livewire::withQueryParams(['ip' => '66.249.66.1'])->test(IpPage::class)
            ->assertSee('always protected: Search engine crawlers')
            ->assertDontSee('Block permanently');
    });

    it('checks AbuseIPDB only when asked, and only for managers', function () {
        config(['porro.activity.abuseipdb_key' => 'k3y']);

        $page = Livewire::withQueryParams(['ip' => '185.220.101.4'])->test(IpPage::class)->assertSee('Check on AbuseIPDB');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'abuseipdb'));

        $page->call('checkReputation')->assertSee('100%')->assertSee('2,048 reports');

        Gate::define(PorroPlugin::MANAGE_GATE, fn () => false);
        Livewire::withQueryParams(['ip' => '185.220.101.4'])->test(IpPage::class)
            ->assertDontSee('Check on AbuseIPDB')
            ->call('checkReputation')
            ->assertForbidden();
    });

    it('404s without a real IP and stays out of the navigation', function () {
        Livewire::withQueryParams(['ip' => '1.2.3'])->test(IpPage::class)->assertNotFound();

        expect(IpPage::shouldRegisterNavigation())->toBeFalse();
    });
});

describe('the full lists', function () {
    beforeEach(function () {
        $this->actingAs(panelUser());
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = (new Entry("5.188.10.{$i}", $i % 2 ? 'Probing for .env' : 'Failed logins', $i % 3 ? Entry::SUGGESTED : Entry::MANUAL, 1000 + $i, $i <= 5 ? time() + 3 * 86400 : null, 'Ops'))->toArray();
        }
        DB::table('porro_blocklist')->insert($rows);
    });

    it('shows the newest ten on Blocking, and links to the rest', function () {
        $html = Livewire::test(BlockingPage::class)->assertSee('Show all 60, with search')->html();

        expect(substr_count($html, 'wire:click="unblockIp(\'5.188.10.'))->toBe(10);
    });

    it('searches, filters and pages the whole list', function () {
        $first = Livewire::withQueryParams(['list' => 'blocklist'])->test(ListPage::class)->assertSee('60 entries')->assertSee('Page 1 of 2')->html();
        expect(substr_count($first, 'wire:click="unblockIp(\'5.188.10.'))->toBe(50);

        Livewire::withQueryParams(['list' => 'blocklist', 'page' => 2])->test(ListPage::class)->assertSee("unblockIp('5.188.10.1')", false);
        Livewire::withQueryParams(['list' => 'blocklist', 'q' => '5.188.10.7'])->test(ListPage::class)->assertSee('5.188.10.7')->assertDontSee('5.188.10.8');
        expect(substr_count(Livewire::withQueryParams(['list' => 'blocklist', 'filter' => 'expiring'])->test(ListPage::class)->html(), 'wire:click="unblockIp(\'5.188.10.'))->toBe(5);
        Livewire::withQueryParams(['list' => 'safelist'])->test(ListPage::class)->assertSee('Nothing is safelisted.');
    });

    it('ignores a query string that isn’t plain values', function () {
        Livewire::withQueryParams(['list' => ['x'], 'q' => ['x'], 'filter' => ['manual'], 'page' => ['2']])->test(ListPage::class)->assertSee('Page 1 of 2');
    });

    it('removes from the list page, and keeps it out of the navigation', function () {
        Livewire::withQueryParams(['list' => 'blocklist'])->test(ListPage::class)
            ->call('unblockIp', '5.188.10.60')
            ->assertNotified('Unblocked 5.188.10.60.');

        expect(DB::table('porro_blocklist')->count())->toBe(59)
            ->and(ListPage::shouldRegisterNavigation())->toBeFalse();
    });
});

describe('Cloudflare setup', function () {
    beforeEach(function () {
        $this->actingAs(panelUser());
        $this->rules = [];
        $this->app->instance(Cloudflare::class, new Cloudflare('token', function (string $method, string $url, array $headers, ?string $body) {
            $path = (string) parse_url(substr($url, strlen(Cloudflare::API)), PHP_URL_PATH);
            $ok = fn ($result) => [200, json_encode(['success' => true, 'result' => $result, 'result_info' => []])];

            return match (true) {
                $path === '/user/tokens/verify' => $ok(['status' => 'active']),
                $path === '/zones' => $ok(str_contains($url, 'dogearbooks.example') ? [['id' => 'z1', 'name' => 'dogearbooks.example', 'account' => ['id' => 'a1']]] : []),
                $path === '/accounts/a1/rules/lists' && $method === 'GET' => $ok([]),
                $path === '/accounts/a1/rules/lists' => $ok(['id' => 'l1', 'name' => 'porro_blocklist', 'kind' => 'ip']),
                $path === '/accounts/a1/rules/lists/l1/items' && $method === 'GET' => $ok([]),
                str_starts_with($path, '/accounts/a1/rules/lists/') => $ok(['operation_id' => 'op', 'status' => 'completed']),
                str_ends_with($path, '/entrypoint') => $this->rules ? $ok(['id' => 'rs1', 'rules' => $this->rules]) : [404, json_encode(['success' => false, 'errors' => [['message' => 'not found']]])],
                $path === '/zones/z1/rulesets' => $ok(['id' => 'rs1', 'rules' => $this->rules = [['id' => 'r1'] + json_decode((string) $body, true)['rules'][0]]]),
                default => [404, json_encode(['success' => false, 'errors' => [['message' => "no route {$method} {$path}"]]])],
            };
        }, fn () => null));
        config(['app.url' => 'https://www.dogearbooks.example']);
    });

    it('is reached from Blocking, not the navigation', function () {
        expect(Filament::getPanel('admin')->getPages())->toContain(CloudflarePage::class)
            ->and(CloudflarePage::shouldRegisterNavigation())->toBeFalse();
    });

    it('walks through the token, the environment and connecting', function () {
        Livewire::test(CloudflarePage::class)
            ->assertSeeInOrder(['1 · Create a Cloudflare API token', '2 · Add it to the site’s environment', '3 · Connect'])
            ->assertSee('Zone WAF Rules')
            ->assertSee('Account Rule Lists')
            ->assertSee('Specified Domains → www.dogearbooks.example')
            ->assertSee('data-copy="PORRO_CLOUDFLARE_TOKEN="', false)
            ->assertSee('One list for the whole Cloudflare account.')
            ->assertSee('To do');
    });

    it('connects from the page and syncs', function () {
        config(['porro.blocklist.cloudflare_token' => 'token']);
        $this->app['env'] = 'production';

        Livewire::test(CloudflarePage::class)
            ->call('connect')
            ->assertNotified('Connected to dogearbooks.example and synced the blocklist.')
            ->assertSee('Connected to dogearbooks.example');

        expect($this->rules)->toHaveCount(1)
            ->and(Cache::get(CloudflareMode::STATE)['connection']['zone_name'])->toBe('dogearbooks.example');
    });

    it('asks for the token before connecting', function () {
        Livewire::test(CloudflarePage::class)
            ->call('connect')
            ->assertNotified('Set PORRO_CLOUDFLARE_TOKEN first, then connect.');

        expect($this->rules)->toBe([]);
    });

    it('shows what Cloudflare said when connecting fails', function () {
        config(['porro.blocklist.cloudflare_token' => 'token', 'app.url' => 'https://elsewhere.example']);

        Livewire::test(CloudflarePage::class)->call('connect');

        expect(Cache::get(CloudflareMode::STATE)['connection'] ?? null)->toBeNull();
    });
});
