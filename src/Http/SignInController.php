<?php

namespace Porro\Filament\Http;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Porro\Core\SiteKey;
use Porro\Filament\SignIn;
use Throwable;

/**
 * "Sign in to Filament" from the site page in Porro. Porro's page posts a
 * one-time ticket here; the site checks it with Porro using its own key and
 * signs in the panel's user with the member's email. No password changes
 * hands, no account is ever made and no role is ever changed: a member
 * without an account here gets a page saying so.
 */
class SignInController extends Controller
{
    private const SWITCH_CACHE = 'porro:sign-in-switch:';

    private const SWITCH_SECONDS = 300;

    public function __invoke(Request $request, SignIn $signIn): Response|RedirectResponse
    {
        // The site owner's switch comes first, and Porro is never asked.
        if (! SignIn::enabled()) {
            return $this->page('turned_off_here', 403);
        }

        try {
            $key = SiteKey::parse((string) config('porro.key'));
        } catch (InvalidArgumentException) {
            return $this->page('not_connected', 403);
        }

        // Before the ticket is spent: a panel that isn't there would use it
        // up for nothing.
        $panel = $this->panel();

        if ($panel === null) {
            return $this->page('no_panel', 403);
        }

        // Only the shape Porro makes. Anything else can't be a live ticket,
        // so it isn't worth a request to Porro.
        $ticket = $request->input('ticket');

        if (! is_string($ticket) || ! preg_match('/^[A-Za-z0-9_.~:-]{16,512}$/', $ticket)) {
            return $this->page('expired', 410, panel: $panel);
        }

        $result = $signIn->redeem($key, (string) config('porro.url'), $ticket);

        return match ($result['status']) {
            SignIn::OK => $this->signIn($request, $panel, (string) $result['email']),
            SignIn::EXPIRED => $this->page('expired', 410, panel: $panel),
            SignIn::TURNED_OFF => $this->page('turned_off_in_porro', 403, panel: $panel),
            SignIn::KEY_REFUSED => $this->page('key_refused', 502, panel: $panel),
            default => $this->page('unreachable', 502, panel: $panel),
        };
    }

    /**
     * The second step when someone else was already signed in: the
     * confirmation page's own form, same-origin and CSRF-checked, carrying
     * the token that names who to switch to.
     */
    public function confirm(Request $request): Response|RedirectResponse
    {
        if (! SignIn::enabled()) {
            return $this->page('turned_off_here', 403);
        }

        try {
            $switch = Crypt::decrypt((string) $request->input('token'));
        } catch (DecryptException) {
            return $this->page('expired', 410, panel: $this->panel());
        }

        $panel = is_array($switch) ? (Filament::getPanels()[(string) ($switch['panel'] ?? '')] ?? null) : null;

        // Five minutes, once, and only from the session it was shown to:
        // the user signed in then must still be the one signed in now.
        if ($panel === null
            || ! is_int($switch['expires'] ?? null) || $switch['expires'] < time()
            || ! is_string($switch['nonce'] ?? null)
            || ! Cache::pull(self::SWITCH_CACHE.$switch['nonce'])) {
            return $this->page('expired', 410, panel: $panel);
        }

        $guard = Auth::guard($panel->getAuthGuard());

        if (! $guard instanceof StatefulGuard || (string) $guard->id() !== (string) ($switch['from'] ?? '')) {
            return $this->page('expired', 410, panel: $panel);
        }

        $user = $this->provider($guard, $panel)?->retrieveById($switch['to'] ?? null);

        if ($user === null) {
            return $this->page('expired', 410, panel: $panel);
        }

        // Access is checked again: it may have changed in five minutes.
        if (! $this->canUse($user, $panel)) {
            return $this->page('no_panel_access', 403, $this->email($user), $panel);
        }

        return $this->logIn($request, $guard, $user, $panel);
    }

    private function signIn(Request $request, Panel $panel, string $email): Response|RedirectResponse
    {
        $guard = Auth::guard($panel->getAuthGuard());

        if (! $guard instanceof StatefulGuard) {
            return $this->page('no_panel', 403, panel: $panel);
        }

        $users = $this->findUsers($this->provider($guard, $panel), $email);

        if ($users === []) {
            return $this->page('no_account', 403, $email, $panel);
        }

        // Two accounts with the same email in different case would make the
        // answer a guess, so that signs no one in.
        if (count($users) > 1) {
            return $this->page('ambiguous', 403, $email, $panel);
        }

        $user = $users[0];

        if (! $this->canUse($user, $panel)) {
            return $this->page('no_panel_access', 403, $email, $panel);
        }

        $current = $guard->user();

        if ($current !== null) {
            if ((string) $current->getAuthIdentifier() === (string) $user->getAuthIdentifier()) {
                return redirect()->to($this->home($panel));
            }

            // Someone else is signed in here. A ticket posted from another
            // site could otherwise swap this browser into a different
            // account without its owner noticing, so they're asked first.
            return $this->switchPage($panel, $current, $user);
        }

        return $this->logIn($request, $guard, $user, $panel);
    }

    /**
     * The rule Filament's own Authenticate middleware applies: the user's
     * canAccessPanel(), or, for a user model that doesn't implement
     * FilamentUser, only a local app. Checked before logging in, so a
     * refused member isn't left signed in on the guard.
     */
    private function canUse(Authenticatable $user, Panel $panel): bool
    {
        return $user instanceof FilamentUser
            ? $user->canAccessPanel($panel)
            : config('app.env') === 'local';
    }

    private function logIn(Request $request, StatefulGuard $guard, Authenticatable $user, Panel $panel): RedirectResponse
    {
        $guard->login($user);

        // A fresh session id and CSRF token, so nothing from before the
        // sign-in carries over. The session isn't elevated: anything that
        // asks for the password again still does.
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->to($this->home($panel));
    }

    private function home(Panel $panel): string
    {
        return $panel->getUrl() ?? url($panel->getPath());
    }

    private function switchPage(Panel $panel, Authenticatable $current, Authenticatable $user): Response
    {
        $nonce = Str::random(40);
        Cache::put(self::SWITCH_CACHE.$nonce, true, self::SWITCH_SECONDS);

        $token = Crypt::encrypt([
            'panel' => $panel->getId(),
            'from' => $current->getAuthIdentifier(),
            'to' => $user->getAuthIdentifier(),
            'nonce' => $nonce,
            'expires' => time() + self::SWITCH_SECONDS,
        ]);

        return $this->page('switch', 200, $this->email($user), $panel, [
            'current' => $this->email($current),
            'token' => $token,
            'stay' => $this->home($panel),
        ]);
    }

    /**
     * PORRO_SIGN_IN_PANEL names the panel; unset, it's the default one.
     */
    private function panel(): ?Panel
    {
        $id = config('porro.sign_in_panel');

        try {
            return filled($id) ? (Filament::getPanels()[(string) $id] ?? null) : Filament::getDefaultPanel();
        } catch (Throwable) {
            // No default panel set.
            return null;
        }
    }

    private function provider(StatefulGuard $guard, Panel $panel): ?UserProvider
    {
        if (method_exists($guard, 'getProvider')) {
            return $guard->getProvider();
        }

        return Auth::createUserProvider(config('auth.guards.'.$panel->getAuthGuard().'.provider'));
    }

    /**
     * Every user whose email matches, whatever its case. Porro's may differ
     * in case from the one here, and whether the database minds depends on
     * the database: MySQL doesn't, Postgres and SQLite do. So the query
     * compares lower case on every one, and more than one match is
     * reported, never resolved.
     *
     * @return list<Authenticatable>
     */
    private function findUsers(?UserProvider $provider, string $email): array
    {
        if ($provider instanceof EloquentUserProvider) {
            return $provider->createModel()->newQuery()
                ->whereRaw('lower(email) = ?', [strtolower($email)])
                ->limit(2)
                ->get()
                ->filter(fn ($user) => $user instanceof Authenticatable && $this->emailMatches($user, $email))
                ->values()
                ->all();
        }

        // Other providers can't be asked for every match, only for one.
        foreach (array_unique([$email, strtolower($email)]) as $candidate) {
            $user = $provider?->retrieveByCredentials(['email' => $candidate]);

            // The provider's own query decides what matched; this settles it.
            if ($user !== null && $this->emailMatches($user, $email)) {
                return [$user];
            }
        }

        return [];
    }

    private function emailMatches(Authenticatable $user, string $email): bool
    {
        return strcasecmp(trim($this->email($user)), $email) === 0;
    }

    private function email(Authenticatable $user): string
    {
        $email = data_get($user, 'email');

        return is_string($email) ? $email : '';
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function page(string $reason, int $status, ?string $email = null, ?Panel $panel = null, array $extra = []): Response
    {
        $login = null;

        try {
            $login = $panel?->hasLogin() ? $panel->getLoginUrl() : null;
        } catch (Throwable) {
            // No login route to offer; the page reads fine without one.
        }

        return response()
            ->view('porro-filament::sign-in', ['reason' => $reason, 'email' => $email, 'login' => $login, ...$extra], $status)
            // A sign-in answer is for this visitor once, never for a cache.
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
