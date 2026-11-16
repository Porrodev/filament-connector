<?php

use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Porro\Core\SiteKey;
use Porro\Filament\Pages\SiteHealthPage;
use Porro\Filament\PorroPlugin;
use Porro\Filament\Tests\SignInUser;
use Porro\Laravel\SiteKeys;

uses(RefreshDatabase::class);

const CONNECT_REQUEST = '0196f3a2-7c41-7e0b-9d55-2f6a8c1e4b70';
const CONNECT_SITE = '01jabcdefghjkmnpqrstvwxyz0';
const CONNECT_NEXT = 'https://porro.test/connect/'.CONNECT_REQUEST;
const HARBOUR = 'https://harbour-arts.org';
const HARBOUR_HEALTH = HARBOUR.'/admin/site-health';

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->boolean('can_use_panel')->default(true);
        $table->boolean('is_admin')->default(false);
        $table->rememberToken();
        $table->timestamps();
    });

    config(['auth.providers.users.model' => SignInUser::class]);

    $this->app['env'] = 'production';
    config(['porro.key' => null, 'porro.enabled' => null, 'app.url' => HARBOUR]);
    URL::forceRootUrl(HARBOUR);
    URL::forceScheme('https');

    // Livewire's test requests skip middleware, so nothing gives them the
    // session a real one has.
    $this->app->rebinding('request', fn ($app, $request) => $request->hasSession() || $request->setLaravelSession($app['session.store']));

    // Anyone on the panel may see Site Health here, so the tests can tell
    // seeing it from connecting.
    Gate::define(PorroPlugin::GATE, fn () => true);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * Porro, faked. The connect calls must be signed by the public key the
 * site sent when it asked.
 *
 * @param  array<string, array{0: int, 1: array<string, mixed>}>  $answers
 */
function porroFake(array $answers = []): ArrayObject
{
    $porro = new ArrayObject(['public_key' => null, 'starts' => [], 'completes' => [], 'reports' => [], 'disconnects' => []]);

    $signed = function (Sent $request, string $path) use ($porro): bool {
        $headers = array_map(fn ($v) => $v[0], $request->headers());
        $message = "connector-v1\nPOST\n{$path}\n".$headers['X-Porro-Timestamp']."\n".$headers['X-Porro-Nonce']."\n".hash('sha256', $request->body());

        return sodium_crypto_sign_verify_detached(base64_decode($headers['X-Porro-Signature']), $message, base64_decode((string) $porro['public_key']));
    };

    Http::fake([
        'porro.test/api/v1/connect' => function (Sent $request) use ($porro, $answers, $signed) {
            $porro['starts'][] = $request;
            $porro['public_key'] = $request['public_key'];
            [$status, $body] = $answers['start'] ?? [201, ['ok' => true, 'id' => CONNECT_REQUEST, 'approve_url' => CONNECT_NEXT]];

            return $signed($request, '/api/v1/connect') ? Http::response($body, $status) : Http::response(['ok' => false, 'error' => 'Invalid signature.'], 401);
        },
        'porro.test/api/v1/connect/*/complete' => function (Sent $request) use ($porro, $answers, $signed) {
            $porro['completes'][] = $request;
            [$status, $body] = $answers['complete'] ?? [200, ['ok' => true, 'site' => CONNECT_SITE, 'host' => 'harbour-arts.org', 'workspace' => 'Marlowe Studio']];

            return $signed($request, '/api/v1/connect/'.CONNECT_REQUEST.'/complete') ? Http::response($body, $status) : Http::response(['ok' => false, 'error' => 'Invalid signature.'], 401);
        },
        'porro.test/api/v1/reports' => function (Sent $request) use ($porro) {
            $porro['reports'][] = $request;

            return Http::response(['ok' => true], 202);
        },
        'porro.test/api/v1/disconnect' => function (Sent $request) use ($porro, $answers) {
            $porro['disconnects'][] = $request;
            [$status, $body] = $answers['disconnect'] ?? [200, ['ok' => true]];

            return Http::response($body, $status);
        },
        'porro.test/api/v1/findings' => Http::response(['ok' => true, 'site' => ['url' => 'https://porro.test/marlowe/sites/harbour-arts'], 'findings' => []]),
        'porro.test/api/v1/sign-in/redeem' => Http::response(['ok' => true, 'email' => 'sam@example.com', 'name' => 'Sam Patel']),
        '*' => Http::response([], 200),
    ]);

    return $porro;
}

/**
 * An admin (is_admin), so they may connect.
 */
function sam(): SignInUser
{
    return SignInUser::create(['name' => 'Sam Patel', 'email' => 'sam@example.com', 'can_use_panel' => true, 'is_admin' => true]);
}

/**
 * On the panel and able to see Site Health, but no admin.
 */
function viewer(string $email = 'priya@example.com'): SignInUser
{
    return SignInUser::create(['name' => 'Priya Shah', 'email' => $email, 'can_use_panel' => true]);
}

function siteHealth($test, SignInUser $user): string
{
    $test->actingAs($user);

    return html_entity_decode(Livewire::test(SiteHealthPage::class)->assertSuccessful()->html(), ENT_QUOTES);
}

/**
 * Press "Connect to Porro".
 */
function press($test, SignInUser $user, array $headers = []): Testable
{
    $test->actingAs($user);

    return Livewire::withHeaders($headers)->test(SiteHealthPage::class)->call('connectToPorro');
}

/**
 * The browser coming back from Porro, to the panel's own page.
 */
function returnFromPorro($test, SignInUser $user, array $query = []): TestResponse
{
    $query += ['porro_request' => CONNECT_REQUEST, 'porro_grant' => 'the-grant', 'porro_next' => CONNECT_NEXT];

    return $test->actingAs($user)->get(HARBOUR_HEALTH.'?'.http_build_query($query));
}

/**
 * A site connected from the panel, with its key kept in the database.
 */
function connected(?string $workspace = 'Marlowe Studio', ?string $host = 'harbour-arts.org'): SiteKey
{
    $key = SiteKey::generate()->withSite(CONNECT_SITE);
    app(SiteKeys::class)->store($key, $workspace, $host);

    return $key;
}

/**
 * Press "Disconnect", then the modal's own Disconnect.
 */
function disconnect($test, SignInUser $user): Testable
{
    $test->actingAs($user);

    return Livewire::test(SiteHealthPage::class)->call('mountAction', 'disconnectFromPorro')->call('callMountedAction');
}

/**
 * What Filament's notifications will say on the next page, read the way
 * Filament's own assertNotified() reads them on 3, 4 and 5.
 */
function notices(): string
{
    if (class_exists(NotificationMailbox::class)) {
        app(NotificationMailbox::class)->flush();
    }

    $component = new Notifications;
    $component->mount();

    if (method_exists($component, 'pullNotifications')) {
        $component->pullNotifications();
    }

    return collect($component->notifications)->map(fn (Notification $n) => $n->getStatus().': '.$n->getTitle().' '.$n->getBody())->implode("\n");
}

describe('what Site Health shows', function () {
    it('shows the button on the live site to someone who may connect', function () {
        porroFake();

        expect(siteHealth($this, sam()))
            ->toContain('Not connected to Porro')
            ->toContain('Have this site checked every hour')
            ->toContain('The checks below ran on this site just now. Connect it to Porro for hourly advisory checks, uptime from outside, and an alert the moment something changes.')
            ->toContain('Opens porro.test to sign in and approve. No key to copy.')
            ->toContain('Connect to Porro')
            ->toContain('wire:click="connectToPorro"')
            // The mark in Porro's own colours.
            ->toContain('#C9F26A')
            // The button replaces "run this command".
            ->not->toContain('Watch this site from outside too')
            ->not->toContain('porro:connect');
    });

    it('sits above the checks run on the site', function () {
        porroFake();

        $html = siteHealth($this, sam());

        expect(strpos($html, 'Not connected to Porro'))->toBeLessThan(strpos($html, 'Checked on this site'));
    });

    it('calls app.porro.dev porro.dev, as people know it', function () {
        porroFake();
        config(['porro.url' => 'https://app.porro.dev']);

        expect(siteHealth($this, sam()))->toContain('Opens porro.dev to sign in and approve.');
    });

    it('shows someone who can only view the command, as before', function () {
        porroFake();

        expect(siteHealth($this, viewer()))
            ->toContain('Watch this site from outside too')
            ->toContain('php artisan porro:connect <site key>')
            ->not->toContain('wire:click="connectToPorro"')
            ->not->toContain('Not connected to Porro');
    });

    it('lets the panel say who may connect', function () {
        porroFake();
        PorroPlugin::current()->authorizeConnect(fn ($user) => $user->email === 'priya@example.com');

        expect(siteHealth($this, viewer()))->toContain('wire:click="connectToPorro"')
            // The callback decides, even for an admin.
            ->and(siteHealth($this, sam()))->not->toContain('wire:click="connectToPorro"');
    });

    it('uses the connectPorro gate when there’s no callback', function () {
        porroFake();
        Gate::define(PorroPlugin::CONNECT_GATE, fn ($user) => $user->email === 'priya@example.com');

        expect(siteHealth($this, viewer()))->toContain('wire:click="connectToPorro"')
            ->and(siteHealth($this, sam()))->not->toContain('wire:click="connectToPorro"');
    });

    it('never lets someone connect who can’t see the page', function () {
        Gate::define(PorroPlugin::GATE, fn ($user) => $user->is_admin);
        PorroPlugin::current()->authorizeConnect(fn () => true);

        expect(PorroPlugin::current()->canConnect(viewer()))->toBeFalse()
            ->and(PorroPlugin::current()->canConnect(sam()))->toBeTrue()
            ->and(PorroPlugin::current()->canConnect(null))->toBeFalse();
    });

    it('shows developer copy, not the button, on a copy that isn’t the live site', function (string $env) {
        porroFake();
        $this->app['env'] = $env;
        // Even one that has been told to report.
        config(['porro.enabled' => true]);

        expect(siteHealth($this, sam()))
            ->toContain('Not connected to Porro')
            ->toContain('This is a development copy')
            ->toContain("Its environment is “{$env}”, so it does not connect and sends no reports. Deploy, then connect from the live site’s control panel. The checks below still run here.")
            ->toContain('Is this the live site? Its APP_ENV should say production. You can also set a site key.')
            ->not->toContain('wire:click="connectToPorro"')
            ->not->toContain('Have this site checked every hour')
            ->not->toContain('Watch this site from outside too');
    })->with(['local', 'staging']);

    it('shows nothing new when the site key is in the environment', function (string $env) {
        porroFake();
        $this->app['env'] = $env;
        config(['porro.key' => siteKey(), 'porro.enabled' => true]);

        expect(siteHealth($this, sam()))
            ->toContain('From Porro')
            // Only how to undo it, which the panel can't do.
            ->toContain('To disconnect, remove PORRO_KEY from the environment and deploy. The control panel can’t change it.')
            ->not->toContain("mountAction('disconnectFromPorro')")
            ->not->toContain('Connect to Porro')
            ->not->toContain('Connected to Porro')
            ->not->toContain('Not connected to Porro')
            ->not->toContain('This is a development copy')
            ->not->toContain('Watch this site from outside too');
    })->with(['production', 'local']);

    it('says who the site is connected to once it’s connected from here', function () {
        porroFake();
        app(SiteKeys::class)->store(SiteKey::generate()->withSite(CONNECT_SITE), 'Marlowe Studio', 'harbour-arts.org');

        // Anyone who can see Site Health sees it, whether or not they may connect.
        expect(siteHealth($this, viewer()))
            ->toContain('Connected to Porro')
            ->toContain('Connected to Marlowe Studio')
            ->toContain('Approved a moment ago. The first report has been sent, and Porro now checks this site every hour.')
            ->toContain('The key was made on this site and is kept encrypted in its database.')
            ->toContain('Open in Porro')
            ->toContain('href="https://porro.test/marlowe/sites/harbour-arts"')
            ->toContain('From Porro')
            ->not->toContain('wire:click="connectToPorro"')
            ->not->toContain('Watch this site from outside too');
    });

    it('escapes a workspace name', function () {
        porroFake();
        app(SiteKeys::class)->store(SiteKey::generate()->withSite(CONNECT_SITE), '<script>alert("Marlowe")</script>');
        $this->actingAs(sam());

        Livewire::test(SiteHealthPage::class)
            ->assertSee('Connected to <script>alert("Marlowe")</script>')
            ->assertDontSeeHtml('<script>alert("Marlowe")</script>');
    });
});

describe('connecting', function () {
    it('goes to Porro to approve, then finishes and keeps the key', function () {
        $porro = porroFake();
        $user = sam();

        press($this, $user)->assertRedirect(CONNECT_NEXT);

        expect($porro['starts'][0]['address'])->toBe(HARBOUR)
            // Back to the panel's own page.
            ->and($porro['starts'][0]['return_url'])->toBe(HARBOUR_HEALTH)
            ->and($porro['starts'][0]['connector']['name'])->toBe('porro/filament-connector')
            ->and($porro['starts'][0]['platform'])->toMatchArray(['name' => 'filament', 'environment' => 'production'])
            ->and((new SiteKeys)->token())->toBeNull();

        returnFromPorro($this, $user)->assertRedirect(CONNECT_NEXT);

        $key = SiteKey::parse((string) (new SiteKeys)->token());

        expect($key->publicKey())->toBe($porro['public_key'])
            ->and($key->siteId)->toBe(strtoupper(CONNECT_SITE))
            ->and($porro['reports'])->toHaveCount(1)
            ->and($porro['reports'][0]['trigger'])->toBe('connect');

        // Porro's page sends the person back; the panel now says connected.
        expect(siteHealth($this, $user))->toContain('Connected to Marlowe Studio');
    });

    it('says so on Site Health when Porro has nowhere to send the browser on to', function () {
        porroFake();
        $user = sam();

        press($this, $user);

        returnFromPorro($this, $user, ['porro_next' => 'https://elsewhere.example/'])->assertRedirect(HARBOUR_HEALTH);

        expect(notices())->toContain('success: Connected to Porro. The first report has been sent.');
    });

    it('needs more than viewing to start or finish', function () {
        $porro = porroFake();
        $user = viewer();

        press($this, $user)->assertForbidden();
        returnFromPorro($this, $user)->assertForbidden();

        expect($porro['starts'])->toBeEmpty()
            ->and($porro['completes'])->toBeEmpty();
    });

    it('sends guests to sign in', function () {
        $porro = porroFake();

        $this->get(HARBOUR_HEALTH.'?porro_request='.CONNECT_REQUEST.'&porro_grant=the-grant')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

        expect($porro['completes'])->toBeEmpty();
    });

    it('checks who’s signed in on the panel’s own guard', function () {
        config(['auth.guards.staff' => ['driver' => 'session', 'provider' => 'users']]);
        Filament::getPanel('admin')->authGuard('staff');

        // Signed in to the app, but not to the panel.
        $this->actingAs(sam(), 'web');
        Auth::shouldUse('web');

        expect(SiteHealthPage::canConnect())->toBeFalse();

        $this->actingAs(Auth::guard('web')->user(), 'staff');

        expect(SiteHealthPage::canConnect())->toBeTrue();
    });

    it('won’t let another signed-in person finish someone’s request', function () {
        $porro = porroFake();
        Gate::define(PorroPlugin::CONNECT_GATE, fn () => true);

        press($this, sam());

        returnFromPorro($this, viewer())->assertRedirect(HARBOUR_HEALTH);

        expect(notices())->toContain('different request')
            ->and($porro['completes'])->toBeEmpty()
            ->and((new SiteKeys)->token())->toBeNull();
    });

    it('lands back on Site Health with the reason when it can’t connect', function (array $answers, string $says) {
        porroFake($answers);
        $user = sam();

        $pressed = press($this, $user);

        if (($pressed->effects['redirect'] ?? null) === CONNECT_NEXT) {
            // Back on Site Health, at an address without the grant.
            returnFromPorro($this, $user)->assertRedirect(HARBOUR_HEALTH);
        } else {
            $pressed->assertNoRedirect();
        }

        expect(notices())->toContain('danger: Couldn’t connect to Porro')->toContain($says)
            ->and((new SiteKeys)->token())->toBeNull();
    })->with([
        'turned down' => [['complete' => [403, ['ok' => false, 'error' => 'The request was turned down in the browser.']]], 'turned down'],
        'expired' => [['complete' => [410, ['ok' => false, 'error' => 'This request has expired or was already used.']]], 'ran out of time'],
        'wrong grant' => [['complete' => [403, ['ok' => false, 'error' => 'That grant doesn’t match this request.']]], 'accept that approval'],
        'Porro down' => [['start' => [503, []]], 'reach Porro'],
    ]);

    it('escapes what Porro says about why', function () {
        porroFake(['start' => [422, ['ok' => false, 'error' => 'The address “a & b” isn’t one Porro can reach.']]]);

        press($this, sam())->assertNoRedirect();

        // Notifications render HTML, so the reason goes in escaped.
        expect(notices())->toContain('danger: Couldn’t connect to Porro')->toContain('“a &amp; b”')->not->toContain('a & b');
    });

    it('doesn’t start from a development copy or over an environment key', function () {
        $porro = porroFake();
        $user = sam();

        $this->app['env'] = 'local';
        press($this, $user)->assertNoRedirect();
        expect(notices())->toContain('“local”');

        $this->app['env'] = 'production';
        config(['porro.key' => siteKey()]);
        press($this, $user)->assertNoRedirect();
        expect(notices())->toContain('PORRO_KEY')
            ->and($porro['starts'])->toBeEmpty();
    });

    it('explains a proxy it doesn’t trust rather than ask for http', function () {
        $porro = porroFake();
        URL::forceRootUrl('http://harbour-arts.org');
        URL::forceScheme('http');

        press($this, sam(), ['Origin' => HARBOUR])->assertNoRedirect();

        expect(notices())->toContain('proxy')
            ->and($porro['starts'])->toBeEmpty();
    });
});

describe('disconnecting', function () {
    it('offers a quiet Disconnect beside Open in Porro to whoever may connect', function () {
        porroFake();
        connected();

        $html = siteHealth($this, sam());

        expect($html)->toContain('wire:click="mountAction(\'disconnectFromPorro\')"')
            ->and(strpos($html, "mountAction('disconnectFromPorro')"))->toBeLessThan(strpos($html, 'Open in Porro'))
            // Seeing the page isn't enough, as with connecting.
            ->and(siteHealth($this, viewer()))->toContain('Open in Porro')->not->toContain('disconnectFromPorro');
    });

    it('asks first, in Filament’s own modal, saying what stops and what carries on', function () {
        $porro = porroFake();
        connected();
        $this->actingAs(sam());

        $page = Livewire::test(SiteHealthPage::class)->call('mountAction', 'disconnectFromPorro');

        $action = $page->instance()->getMountedAction();

        expect($action->getModalHeading())->toBe('Disconnect harbour-arts.org from Porro?')
            ->and($action->getModalDescription())->toBe('This site stops sending reports to Marlowe Studio, and its key is deleted from this site.')
            ->and($action->getModalSubmitActionLabel())->toBe('Disconnect')
            ->and($action->getModalCancelActionLabel())->toBe('Cancel')
            ->and($action->getColor())->toBe('danger')
            ->and(html_entity_decode($action->getModalContent()->render(), ENT_QUOTES))
            ->toContain('Stops')
            ->toContain('Hourly reports of what is installed here, and signing in to this site from Porro.')
            ->toContain('Carries on')
            ->toContain('The checks on this page. They run on this site and need no account.')
            ->toContain('In Porro')
            ->toContain('Marlowe Studio keeps the site and its history. Uptime, SSL and domain checks go on from outside until someone removes the site there.')
            ->toContain('You can connect again at any time.');

        // Nothing changes until it's confirmed.
        expect($porro['disconnects'])->toBeEmpty()
            ->and((new SiteKeys)->token())->not->toBeNull();
    });

    it('tells Porro, deletes the key and says so above the connect card', function () {
        $porro = porroFake();
        $key = connected();
        $user = sam();

        $html = html_entity_decode(disconnect($this, $user)->assertSuccessful()->html(), ENT_QUOTES);

        expect($porro['disconnects'])->toHaveCount(1)
            // Signed as the site, and nothing about who pressed it.
            ->and($porro['disconnects'][0]->header('X-Porro-Site')[0])->toBe($key->siteId)
            ->and($porro['disconnects'][0]->body())->toBe('{}')
            ->and((new SiteKeys)->token())->toBeNull()
            ->and((new SiteKeys)->turnedAway())->toBeNull()
            ->and($html)
            ->toContain('Disconnected from Marlowe Studio')
            ->toContain('This site no longer sends reports, and its key has been deleted. Marlowe Studio still has the site in Porro; someone in that workspace can remove it there.')
            ->toContain('Not connected to Porro')
            ->toContain('wire:click="connectToPorro"')
            ->not->toContain('Connected to Marlowe Studio')
            ->not->toContain('From Porro')
            ->and(strpos($html, 'Disconnected from Marlowe Studio'))->toBeLessThan(strpos($html, 'Not connected to Porro'));

        // The notice is for that view only.
        expect(siteHealth($this, $user))
            ->toContain('Not connected to Porro')
            ->not->toContain('Disconnected from Marlowe Studio');
    });

    it('deletes the key even when Porro doesn’t answer, and says Porro wasn’t told', function () {
        $porro = porroFake(['disconnect' => [503, []]]);
        connected();

        $html = html_entity_decode(disconnect($this, sam())->assertSuccessful()->html(), ENT_QUOTES);

        expect($porro['disconnects'])->not->toBeEmpty()
            ->and((new SiteKeys)->token())->toBeNull()
            ->and($html)
            ->toContain('Disconnected on this site')
            ->toContain('The key has been deleted and reports have stopped. Porro didn’t answer, so it hasn’t been told: Marlowe Studio will see this site as not reporting, not as disconnected.')
            ->toContain('Not connected to Porro')
            ->not->toContain('Disconnected from Marlowe Studio');
    });

    it('counts a key Porro already turns away as told', function () {
        porroFake(['disconnect' => [401, ['ok' => false, 'error' => 'Invalid signature.']]]);
        connected();

        expect(html_entity_decode(disconnect($this, sam())->html(), ENT_QUOTES))->toContain('Disconnected from Marlowe Studio')
            ->and((new SiteKeys)->token())->toBeNull();
    });

    it('says Porro when the workspace isn’t known', function () {
        porroFake();
        connected(workspace: null, host: null);
        $this->actingAs(sam());

        $page = Livewire::test(SiteHealthPage::class)->call('mountAction', 'disconnectFromPorro');
        $action = $page->instance()->getMountedAction();

        expect($action->getModalHeading())->toBe('Disconnect this site from Porro?')
            ->and($action->getModalDescription())->toBe('This site stops sending reports to Porro, and its key is deleted from this site.')
            ->and($action->getModalContent()->render())->toContain('Porro keeps the site and its history.')
            ->and(html_entity_decode($page->call('callMountedAction')->html(), ENT_QUOTES))
            ->toContain('Disconnected from Porro')
            ->toContain('Porro still has the site in Porro; someone in that workspace can remove it there.');
    });

    it('needs more than viewing', function () {
        $porro = porroFake();
        connected();
        $user = viewer();

        disconnect($this, $user);

        expect($porro['disconnects'])->toBeEmpty()
            ->and((new SiteKeys)->token())->not->toBeNull()
            ->and(siteHealth($this, $user))->toContain('Connected to Marlowe Studio');
    });

    it('lets the panel say who may disconnect, as it does for connecting', function () {
        $porro = porroFake();
        connected();
        PorroPlugin::current()->authorizeConnect(fn ($user) => $user->email === 'priya@example.com');

        disconnect($this, sam());
        expect($porro['disconnects'])->toBeEmpty()
            ->and((new SiteKeys)->token())->not->toBeNull();

        disconnect($this, viewer());
        expect($porro['disconnects'])->toHaveCount(1)
            ->and((new SiteKeys)->token())->toBeNull();
    });

    it('is never offered for a key from the environment', function () {
        $porro = porroFake();
        // Even with a key in the database underneath it.
        connected();
        config(['porro.key' => siteKey()]);
        $user = sam();

        disconnect($this, $user);

        expect(SiteHealthPage::canDisconnect())->toBeFalse()
            ->and($porro['disconnects'])->toBeEmpty()
            ->and(app(SiteKeys::class)->stored())->not->toBeNull()
            ->and(siteHealth($this, $user))
            ->toContain('To disconnect, remove PORRO_KEY from the environment and deploy. The control panel can’t change it.')
            ->not->toContain('disconnectFromPorro')
            ->not->toContain('Disconnected');
    });

    it('isn’t offered on a copy that isn’t the live site', function () {
        $porro = porroFake();
        // A copy of the live database: its key is the live site's.
        connected();
        $this->app['env'] = 'staging';
        $user = sam();

        expect(siteHealth($this, $user))->toContain('This is a development copy')->not->toContain('disconnectFromPorro');

        disconnect($this, $user);

        expect($porro['disconnects'])->toBeEmpty()
            ->and((new SiteKeys)->token())->not->toBeNull();
    });
});

describe('a key Porro turned away', function () {
    beforeEach(function () {
        porroFake();
        config(['app.timezone' => 'Europe/London']);
        Carbon::setTestNow(Carbon::parse('2026-11-16 14:02:00', 'Europe/London'));

        connected();
        app(SiteKeys::class)->turnAway();
    });

    afterEach(fn () => Carbon::setTestNow());

    it('says what happened and when, with the button to connect again', function () {
        expect(siteHealth($this, sam()))
            ->toContain('No longer connected to Porro')
            ->toContain('Porro turned this site’s key away')
            ->toContain('The site was removed from Marlowe Studio, or given a new key there.')
            ->toContain('Reports stopped on 16 November at 14:02.')
            ->toContain('The old key has been deleted from this site.')
            ->toContain('Connect again to carry on. Opens porro.test to sign in and approve.')
            ->toContain('wire:click="connectToPorro"')
            ->not->toContain('Not connected to Porro')
            ->not->toContain('Have this site checked every hour')
            ->not->toContain('disconnectFromPorro')
            ->not->toContain('Watch this site from outside too');
    });

    it('keeps the wording and leaves the button out for someone who can only view', function () {
        expect(siteHealth($this, viewer()))
            ->toContain('No longer connected to Porro')
            ->toContain('Porro turned this site’s key away')
            ->not->toContain('wire:click="connectToPorro"')
            ->not->toContain('Watch this site from outside too');
    });

    it('keeps the wording and leaves the button out on a copy that isn’t the live site', function () {
        $this->app['env'] = 'staging';

        expect(siteHealth($this, sam()))
            ->toContain('No longer connected to Porro')
            ->toContain('Porro turned this site’s key away')
            ->not->toContain('wire:click="connectToPorro"')
            ->not->toContain('This is a development copy');
    });

    it('says Porro when the workspace isn’t known', function () {
        connected(workspace: null);
        app(SiteKeys::class)->turnAway();

        expect(siteHealth($this, sam()))->toContain('The site was removed from Porro, or given a new key there.');
    });

    it('stays until the site connects again', function () {
        $user = sam();

        expect(siteHealth($this, $user))->toContain('No longer connected to Porro');

        connected();

        expect(siteHealth($this, $user))
            ->toContain('Connected to Marlowe Studio')
            ->not->toContain('No longer connected to Porro')
            ->not->toContain('Porro turned this site’s key away');
    });

    it('says nothing of it once the key is in the environment', function () {
        config(['porro.key' => siteKey()]);

        expect(siteHealth($this, sam()))
            ->toContain('From Porro')
            ->not->toContain('No longer connected to Porro');
    });
});

describe('sign in from Porro', function () {
    it('signs in with a key made in the panel', function () {
        porroFake();
        config(['porro.sign_in' => true]);
        $key = SiteKey::generate()->withSite(CONNECT_SITE);
        app(SiteKeys::class)->store($key, 'Marlowe Studio', 'harbour-arts.org');
        $user = sam();

        $this->withHeader('Sec-Fetch-Site', 'same-origin')
            ->post('/porro/sign-in/continue', ['ticket' => 'Tq7Hq2mV9xR4pL8sN3wK6yB1cD5fG0jZa2Lm4Np6Qr8St0Uv'])
            ->assertRedirect(Filament::getPanel('admin')->getUrl());

        expect(Auth::id())->toBe($user->id);

        // Redeemed with the stored key, as its site.
        Http::assertSent(fn (Sent $request) => str_ends_with($request->url(), '/api/v1/sign-in/redeem')
            && $request->header('X-Porro-Site')[0] === $key->siteId);
    });

    it('still says the site isn’t connected when there’s no key at all', function () {
        porroFake();
        config(['porro.sign_in' => true]);
        sam();

        $this->withHeader('Sec-Fetch-Site', 'same-origin')
            ->post('/porro/sign-in/continue', ['ticket' => 'Tq7Hq2mV9xR4pL8sN3wK6yB1cD5fG0jZa2Lm4Np6Qr8St0Uv'])
            ->assertForbidden();

        expect(Auth::check())->toBeFalse();
    });
});
