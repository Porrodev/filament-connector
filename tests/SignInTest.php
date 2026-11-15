<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Porro\Core\SiteKey;
use Porro\Filament\Tests\SignInUser;

// The shape Porro makes: 48 letters and digits.
const TICKET = 'Tq7Hq2mV9xR4pL8sN3wK6yB1cD5fG0jZa2Lm4Np6Qr8St0Uv';

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->boolean('can_use_panel')->default(true);
        $table->rememberToken();
        $table->timestamps();
    });

    config(['auth.providers.users.model' => SignInUser::class]);

    $this->key = siteKey();
    // Tests aren't the live site, so sign-in is turned on by hand.
    config(['porro.key' => $this->key, 'porro.sign_in' => true]);

    // As the site's own page posts the ticket on to the second step.
    $this->withHeader('Sec-Fetch-Site', 'same-origin');
});

// What a browser sends with a post from Porro's page.
const FROM_PORRO = ['Origin' => 'https://porro.test'];

// Where Porro's page posts the ticket, from another site.
function startUrl(): string
{
    return '/porro/sign-in';
}

// Where this site's own page posts it on.
function signInUrl(): string
{
    return '/porro/sign-in/continue';
}

function porroAnswers(int $status, array $body): void
{
    Http::fake(['porro.test/api/v1/sign-in/redeem' => Http::response($body, $status)]);
}

function member(string $email = 'daniel@1994.co.uk', bool $canUsePanel = true): SignInUser
{
    return SignInUser::create(['name' => 'Daniel Jones', 'email' => $email, 'can_use_panel' => $canUsePanel]);
}

/**
 * The public half of a site key, as Porro stores it.
 */
function publicKey(string $token): string
{
    preg_match('/_([A-Za-z0-9_-]{43})$/', $token, $m);

    return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(base64_decode(strtr($m[1], '-_', '+/'))));
}

it('lives at /porro/sign-in', function () {
    expect(route('porro.sign-in', absolute: false))->toBe(startUrl())
        ->and(route('porro.sign-in.continue', absolute: false))->toBe(signInUrl());
});

it('starts without a session, and hands the ticket on from this site’s own page', function () {
    Http::fake();
    member();
    $route = Route::getRoutes()->getByName('porro.sign-in');

    // Posted from Porro's page, so the browser holds the session cookie back.
    $response = $this->withHeader('Sec-Fetch-Site', 'cross-site')->post(startUrl(), ['ticket' => TICKET], FROM_PORRO)
        ->assertOk()
        ->assertSee('action="'.route('porro.sign-in.continue').'"', false)
        ->assertSee('name="ticket" value="'.TICKET.'"', false)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");

    // No session started: a new one would replace whoever is signed in.
    expect($response->headers->getCookies())->toBe([])
        ->and($route->middleware())->not->toContain('web')
        ->and(app('router')->gatherRouteMiddleware($route))->not->toContain('Illuminate\Session\Middleware\StartSession')
        ->and(app('router')->gatherRouteMiddleware($route))->toContain('Illuminate\Routing\Middleware\ThrottleRequests:porro-sign-in-start')
        ->and(Auth::check())->toBeFalse();
    Http::assertNothingSent();
});

it('starts nothing for something that isn’t a ticket, or a ticket in the address', function () {
    $this->post(startUrl(), ['ticket' => 'abc'], FROM_PORRO)->assertStatus(410)->assertDontSee('name="ticket"', false);
    $this->post(startUrl().'?ticket='.TICKET, [], FROM_PORRO)->assertStatus(410)->assertDontSee(TICKET);
    $this->post(startUrl().'?ticket='.TICKET, ['ticket' => TICKET], FROM_PORRO)->assertStatus(410)->assertDontSee(TICKET);
});

it('only starts for a ticket posted from Porro’s own page', function (array $headers) {
    // Any other site could post a ticket of its own, to have this site's
    // page sign a visitor in to its account.
    $this->post(startUrl(), ['ticket' => TICKET], $headers)
        ->assertStatus(410)
        ->assertDontSee(TICKET);
})->with([
    'another site' => [['Origin' => 'https://evil.example']],
    'a page that hides its origin' => [['Origin' => 'null']],
    'no origin at all' => [[]],
    'Porro’s host over plain http' => [['Origin' => 'http://porro.test']],
    'Porro’s host on another port' => [['Origin' => 'https://porro.test:8443']],
    'a host that starts like Porro’s' => [['Origin' => 'https://porro.test.evil.example']],
]);

it('only signs in from this site’s own page', function (array $headers, bool $allowed) {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    member();

    $response = $this->withoutHeader('Sec-Fetch-Site')->post(signInUrl(), ['ticket' => TICKET], $headers);

    if ($allowed) {
        $response->assertRedirect(Filament::getPanel('admin')->getUrl());

        return;
    }

    // Turned away before Porro is asked, so the ticket isn't spent.
    $response->assertStatus(410);
    Http::assertNothingSent();
    expect(Auth::check())->toBeFalse();
})->with([
    'another site' => [['Sec-Fetch-Site' => 'cross-site'], false],
    'another site, same registrable domain' => [['Sec-Fetch-Site' => 'same-site'], false],
    'another site claiming this origin' => [['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'http://localhost'], false],
    'no word on where it came from' => [[], false],
    'an older browser, another origin' => [['Origin' => 'https://evil.example'], false],
    'an older browser, this host on another port' => [['Origin' => 'http://localhost:8080'], false],
    'an older browser, this host over https' => [['Origin' => 'https://localhost'], false],
    'an older browser, this origin' => [['Origin' => 'http://localhost'], true],
]);

it('won’t take a ticket from the address', function () {
    Http::fake();

    $this->post(signInUrl().'?ticket='.TICKET)->assertStatus(410);
    $this->post(signInUrl().'?ticket='.TICKET, ['ticket' => TICKET])->assertStatus(410);

    Http::assertNothingSent();
});

it('is off on a copy of the site that isn’t live, unless the copy turns it on', function () {
    Http::fake();
    config(['porro.sign_in' => null]);

    $this->post(startUrl(), ['ticket' => TICKET], FROM_PORRO)->assertForbidden()->assertSee('works on the live site only');
    $this->post(signInUrl(), ['ticket' => TICKET])->assertForbidden()->assertSee('works on the live site only');
    Http::assertNothingSent();

    $this->app['env'] = 'production';
    $this->post(startUrl(), ['ticket' => TICKET], FROM_PORRO)->assertOk();

    $this->app['env'] = 'staging';
    config(['porro.sign_in' => 'true']);
    $this->post(startUrl(), ['ticket' => TICKET], FROM_PORRO)->assertOk();
});

it('signs no one in when the users aren’t Eloquent models, as it can’t rule out a second account', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    member();
    config(['auth.providers.users' => ['driver' => 'database', 'table' => 'users']]);

    $this->post(signInUrl(), ['ticket' => TICKET])
        ->assertForbidden()
        ->assertSee('This site’s accounts can’t be checked', false);

    expect(Auth::check())->toBeFalse();
});

it('signs the member in to the panel and sends them to it', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $user = member();

    $this->post(signInUrl(), ['ticket' => TICKET])->assertRedirect(Filament::getPanel('admin')->getUrl());

    expect(Auth::guard(Filament::getPanel('admin')->getAuthGuard())->id())->toBe($user->id);
});

it('matches the email whatever its case', function (string $fromPorro, string $here) {
    porroAnswers(200, ['ok' => true, 'email' => $fromPorro, 'name' => 'Daniel Jones']);
    $user = member($here);
    member('daniel@1994xco.uk');

    $this->post(signInUrl(), ['ticket' => TICKET])->assertRedirect(Filament::getPanel('admin')->getUrl());

    expect(Auth::id())->toBe($user->id);
})->with([
    'capitals in Porro' => ['Daniel@1994.co.UK', 'daniel@1994.co.uk'],
    'capitals here' => ['daniel@1994.co.uk', 'Daniel@1994.co.UK'],
    'capitals in both' => ['daniel@1994.CO.uk', 'Daniel@1994.co.UK'],
    'an underscore' => ['Daniel_Jones@1994.co.uk', 'daniel_jones@1994.co.uk'],
]);

it('signs no one in when more than one account uses the email, whatever its case', function (array $here) {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    foreach ($here as $email) {
        member($email);
    }

    $this->post(signInUrl(), ['ticket' => TICKET])
        ->assertForbidden()
        ->assertSee('More than one account on this site uses this email, so Porro can’t tell which to sign in. Ask a site admin to fix it.', false);

    expect(Auth::check())->toBeFalse();
})->with([
    // An exact match doesn't win over one that differs only in case.
    'one exact, one not' => [['daniel@1994.co.uk', 'Daniel@1994.co.uk']],
    'neither exact' => [['Daniel@1994.co.uk', 'daniel@1994.CO.UK']],
]);

it('redeems the ticket with a request signed by the site key', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    member();

    $this->post(signInUrl(), ['ticket' => TICKET]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $headers = fn (string $name) => $request->header($name)[0] ?? '';
        $message = SiteKey::message('POST', '/api/v1/sign-in/redeem', $headers('X-Porro-Timestamp'), $headers('X-Porro-Nonce'), $request->body());

        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://porro.test/api/v1/sign-in/redeem')
            ->and(json_decode($request->body(), true))->toBe(['ticket' => TICKET])
            ->and($headers('Content-Type'))->toStartWith('application/json')
            ->and($headers('X-Porro-Site'))->toBe(SiteKey::parse($this->key)->siteId)
            ->and(abs((int) $headers('X-Porro-Timestamp') - time()))->toBeLessThan(5)
            ->and($headers('X-Porro-Nonce'))->not->toBe('')
            ->and(sodium_crypto_sign_verify_detached(base64_decode($headers('X-Porro-Signature')), $message, publicKey($this->key)))->toBeTrue()
            // Bound to this endpoint: it wouldn't pass as a report.
            ->and(sodium_crypto_sign_verify_detached(base64_decode($headers('X-Porro-Signature')), SiteKey::message('POST', '/api/v1/reports', $headers('X-Porro-Timestamp'), $headers('X-Porro-Nonce'), $request->body()), publicKey($this->key)))->toBeFalse();

        return true;
    });
});

it('says the link has expired when Porro has already used it, and signs no one in', function () {
    porroAnswers(410, ['ok' => false, 'error' => 'That sign-in link has expired.']);
    member();

    $this->post(signInUrl(), ['ticket' => TICKET])
        ->assertStatus(410)
        ->assertSee('Links from Porro work once and only for 60 seconds. Go back to the site in Porro and choose Sign in again.')
        ->assertSee(Filament::getPanel('admin')->getLoginUrl())
        ->assertDontSee(TICKET)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    expect(Auth::check())->toBeFalse();
});

it('never asks Porro about something that isn’t a ticket', function (mixed $ticket) {
    Http::fake();

    $this->post(signInUrl(), ['ticket' => $ticket])->assertStatus(410);

    Http::assertNothingSent();
})->with([
    'missing' => [null],
    'too short' => ['abc'],
    'not a string' => [['a' => 'b']],
    'odd characters' => ['<script>alert(1)</script>xxxxxxxx'],
]);

it('says so when there’s no user with the member’s email', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'someone@agency.test', 'name' => 'Someone']);
    member();

    $this->post(signInUrl(), ['ticket' => TICKET])
        ->assertForbidden()
        ->assertSee('No account for you here')
        ->assertSee('someone@agency.test');

    expect(Auth::check())->toBeFalse()
        ->and(SignInUser::where('email', 'someone@agency.test')->exists())->toBeFalse()
        ->and(SignInUser::count())->toBe(1);
});

it('won’t sign in a user whose canAccessPanel() says no', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $user = member(canUsePanel: false);

    $this->post(signInUrl(), ['ticket' => TICKET])
        ->assertForbidden()
        ->assertSee('Your account can’t use the panel', false);

    expect(Auth::check())->toBeFalse()
        ->and($user->fresh()->can_use_panel)->toBeFalse();
});

it('only signs in a user model without FilamentUser on a local app, as Filament does', function (string $env, bool $signedIn) {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    config(['auth.providers.users.model' => User::class, 'app.env' => $env]);
    member();

    $this->post(signInUrl(), ['ticket' => TICKET])->assertStatus($signedIn ? 302 : 403);

    expect(Auth::check())->toBe($signedIn);
})->with([
    'production' => ['production', false],
    'local' => ['local', true],
]);

it('says sign-in is off when Porro has it turned off for the site', function () {
    porroAnswers(403, ['ok' => false, 'error' => 'Sign-in is turned off for this site.']);
    member();

    $this->post(signInUrl(), ['ticket' => TICKET])
        ->assertForbidden()
        ->assertSee('Signing in to client sites is turned off for this site in Porro.');

    expect(Auth::check())->toBeFalse();
});

it('is turned off by PORRO_SIGN_IN=false without asking Porro', function () {
    config(['porro.sign_in' => 'false']);
    Http::fake();
    member();

    $this->post(signInUrl(), ['ticket' => TICKET])
        ->assertForbidden()
        ->assertSee('This site has turned off signing in from Porro');

    Http::assertNothingSent();
    expect(Auth::check())->toBeFalse();
});

it('signs in to the default panel unless the site names another', function () {
    expect(config('porro.sign_in_panel'))->toBeNull()
        // Merged alongside the Laravel connector's own settings.
        ->and(config('porro.url'))->toBe('https://porro.test');
});

it('says Porro couldn’t be reached when it can’t be', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28 while posting '.TICKET));
    member();

    $this->post(signInUrl(), ['ticket' => TICKET])
        ->assertStatus(502)
        ->assertSee('Porro couldn’t be reached', false)
        ->assertDontSee('cURL')
        ->assertDontSee(TICKET);

    expect(Auth::check())->toBeFalse();
});

it('treats a Porro error or an odd answer as Porro being unreachable', function (int $status, array $body) {
    porroAnswers($status, $body);
    member();

    $this->post(signInUrl(), ['ticket' => TICKET])->assertStatus(502)->assertSee('Porro couldn’t be reached', false);

    expect(Auth::check())->toBeFalse();
})->with([
    'server error' => [500, ['message' => 'Server Error']],
    'ok without an email' => [200, ['ok' => true]],
    'not ok' => [200, ['ok' => false, 'email' => 'daniel@1994.co.uk']],
]);

it('says the site key was refused when Porro rejects the signature', function () {
    porroAnswers(401, ['ok' => false, 'error' => 'Bad signature.']);
    member();

    $this->post(signInUrl(), ['ticket' => TICKET])->assertStatus(502)->assertSee('Porro didn’t accept this site’s key', false);

    expect(Auth::check())->toBeFalse();
});

it('says the site isn’t connected when it has no site key', function () {
    config(['porro.key' => null]);
    Http::fake();

    $this->post(signInUrl(), ['ticket' => TICKET])->assertForbidden()->assertSee('This site isn’t connected to Porro', false);

    Http::assertNothingSent();
});

it('signs in to the panel PORRO_SIGN_IN_PANEL names, on that panel’s guard', function () {
    config([
        'auth.guards.staff' => ['driver' => 'session', 'provider' => 'users'],
        'porro.sign_in_panel' => 'staff',
    ]);
    app(PanelRegistry::class)->register(Panel::make()->id('staff')->path('staff')->authGuard('staff'));
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $user = member();

    $this->post(signInUrl(), ['ticket' => TICKET])->assertRedirect(url('staff'));

    expect(Auth::guard('staff')->id())->toBe($user->id)
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('says there’s no panel, without spending the ticket, when PORRO_SIGN_IN_PANEL names none', function () {
    config(['porro.sign_in_panel' => 'nope']);
    Http::fake();
    member();

    $this->post(signInUrl(), ['ticket' => TICKET])->assertForbidden()->assertSee('There’s no panel to sign in to', false);

    Http::assertNothingSent();
    expect(Auth::check())->toBeFalse();
});

it('takes the ticket without a CSRF token, as it comes from Porro', function () {
    $route = Route::getRoutes()->getByName('porro.sign-in.continue');
    $middleware = app('router')->gatherRouteMiddleware($route);

    // Not the class Laravel ships, nor an app's own subclass of it.
    $checksCsrf = collect($middleware)->contains(fn ($class) => is_string($class) && collect([ValidateCsrfToken::class, VerifyCsrfToken::class, PreventRequestForgery::class])
        ->contains(fn (string $csrf) => is_a($class, $csrf, true)));

    expect($route->methods())->toBe(['POST'])
        ->and($route->uri())->toBe('porro/sign-in/continue')
        ->and($route->middleware())->toContain('web')
        ->and($checksCsrf)->toBeFalse()
        ->and($middleware)->toContain('Illuminate\Routing\Middleware\ThrottleRequests:porro-sign-in');
});

it('is limited to 10 tries a minute from one IP', function () {
    porroAnswers(410, ['ok' => false, 'error' => 'expired']);

    foreach (range(1, 10) as $try) {
        $this->post(signInUrl(), ['ticket' => TICKET])->assertStatus(410);
    }

    $this->post(signInUrl(), ['ticket' => TICKET])->assertStatus(429);
    $this->post(signInUrl(), ['ticket' => TICKET], ['REMOTE_ADDR' => '203.0.113.9'])->assertStatus(410);
    Http::assertSentCount(11);
});

it('isn’t turned away for a missing CSRF token outside tests', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $user = member();
    // Laravel skips CSRF checks while running tests; pretend it's live.
    $this->app['env'] = 'production';

    $this->post(signInUrl(), ['ticket' => TICKET])->assertRedirect(Filament::getPanel('admin')->getUrl());

    expect(Auth::id())->toBe($user->id);
});

/**
 * The token in the "switch accounts?" page's form.
 */
function switchToken(string $html): string
{
    preg_match('/name="token" value="([^"]+)"/', $html, $m);

    return html_entity_decode($m[1] ?? '');
}

it('asks before switching when someone else is signed in, and switches only on the yes', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $current = member('ada@example.com');
    $user = member();
    $this->actingAs($current);

    $page = $this->post(signInUrl(), ['ticket' => TICKET])
        ->assertOk()
        ->assertSee('You’re signed in as ada@example.com. Switch to daniel@1994.co.uk?', false)
        ->assertSee(route('porro.sign-in.confirm'))
        ->assertDontSee(TICKET)
        ->assertHeader('Cache-Control', 'no-store, private')
        // Never in another site's frame, with "Switch" dressed up as something else.
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");

    // The ticket is spent, but nobody has been swapped yet.
    Http::assertSentCount(1);
    expect(Auth::id())->toBe($current->id);

    $this->post(route('porro.sign-in.confirm'), ['token' => switchToken($page->getContent())])
        ->assertRedirect(Filament::getPanel('admin')->getUrl());

    expect(Auth::id())->toBe($user->id);
});

it('takes the switch once only', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $current = member('ada@example.com');
    member();
    $this->actingAs($current);
    $token = switchToken($this->post(signInUrl(), ['ticket' => TICKET])->getContent());

    $this->post(route('porro.sign-in.confirm'), ['token' => $token])->assertRedirect();
    $this->actingAs($current);

    $this->post(route('porro.sign-in.confirm'), ['token' => $token])->assertStatus(410);
    expect(Auth::id())->toBe($current->id);
});

it('lets the switch lapse after five minutes', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $current = member('ada@example.com');
    member();
    $this->actingAs($current);
    $token = switchToken($this->post(signInUrl(), ['ticket' => TICKET])->getContent());

    $this->travel(301)->seconds();

    $this->post(route('porro.sign-in.confirm'), ['token' => $token])->assertStatus(410);
    expect(Auth::id())->toBe($current->id);
});

it('won’t switch for a token shown to someone else, or one it didn’t make', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $current = member('ada@example.com');
    $other = member('bea@example.com');
    $user = member();
    $this->actingAs($current);
    $token = switchToken($this->post(signInUrl(), ['ticket' => TICKET])->getContent());

    // Made for a session signed in as someone else.
    $this->actingAs($other);
    $this->post(route('porro.sign-in.confirm'), ['token' => $token])->assertStatus(410);

    // Not made here at all.
    $forged = Crypt::encrypt(['panel' => 'admin', 'from' => $other->id, 'to' => $user->id, 'nonce' => 'made-up', 'expires' => time() + 300]);
    $this->post(route('porro.sign-in.confirm'), ['token' => $forged])->assertStatus(410);
    $this->post(route('porro.sign-in.confirm'), ['token' => 'not-encrypted'])->assertStatus(410);

    expect(Auth::id())->toBe($other->id);
});

it('checks CSRF on the switch, as it’s posted from this site', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $current = member('ada@example.com');
    member();
    $this->actingAs($current);
    $token = switchToken($this->post(signInUrl(), ['ticket' => TICKET])->getContent());
    // Laravel 13 lets a same-origin post through on the browser's word;
    // without it, only the token will do.
    $this->app['env'] = 'production';

    $this->withoutHeader('Sec-Fetch-Site')->post(route('porro.sign-in.confirm'), ['token' => $token])->assertStatus(419);

    expect(Auth::id())->toBe($current->id);
});

it('just opens the panel when the member is already signed in as themselves', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'Daniel@1994.co.uk', 'name' => 'Daniel Jones']);
    $user = member();
    $this->actingAs($user);

    $this->post(signInUrl(), ['ticket' => TICKET])->assertRedirect(Filament::getPanel('admin')->getUrl());

    expect(Auth::id())->toBe($user->id);
});
