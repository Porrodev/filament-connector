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
    config(['porro.key' => $this->key]);
});

function signInUrl(): string
{
    return '/porro/sign-in';
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
    expect(route('porro.sign-in', absolute: false))->toBe(signInUrl());
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

it('signs no one in when two accounts differ only in case', function () {
    porroAnswers(200, ['ok' => true, 'email' => 'DANIEL@1994.co.uk', 'name' => 'Daniel Jones']);
    member('Daniel@1994.co.uk');
    member('daniel@1994.CO.UK');

    $this->post(signInUrl(), ['ticket' => TICKET])->assertForbidden()->assertSee('No account for you here');

    expect(Auth::check())->toBeFalse();
});

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

it('is on unless the site turns it off, for the default panel', function () {
    expect(config('porro.sign_in'))->toBeTrue()
        ->and(config('porro.sign_in_panel'))->toBeNull()
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
    $route = Route::getRoutes()->getByName('porro.sign-in');
    $middleware = app('router')->gatherRouteMiddleware($route);

    expect($route->methods())->toBe(['POST'])
        ->and($route->uri())->toBe('porro/sign-in')
        ->and($route->middleware())->toContain('web')
        ->and($middleware)->not->toContain(ValidateCsrfToken::class, VerifyCsrfToken::class, PreventRequestForgery::class)
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
