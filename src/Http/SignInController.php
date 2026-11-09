<?php

namespace Porro\Filament\Http;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
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

    private function signIn(Request $request, Panel $panel, string $email): Response|RedirectResponse
    {
        $guard = Auth::guard($panel->getAuthGuard());

        if (! $guard instanceof StatefulGuard) {
            return $this->page('no_panel', 403, panel: $panel);
        }

        $user = $this->findUser($this->provider($guard, $panel), $email);

        if ($user === null) {
            return $this->page('no_account', 403, $email, $panel);
        }

        // The rule Filament's own Authenticate middleware applies: the
        // user's canAccessPanel(), or, for a user model that doesn't
        // implement FilamentUser, only a local app. Checked before logging
        // in, so a refused member isn't left signed in on the guard.
        $allowed = $user instanceof FilamentUser
            ? $user->canAccessPanel($panel)
            : config('app.env') === 'local';

        if (! $allowed) {
            return $this->page('no_panel_access', 403, $email, $panel);
        }

        $guard->login($user);

        // A fresh session id and CSRF token, so nothing from before the
        // sign-in carries over. The session isn't elevated: anything that
        // asks for the password again still does.
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->to($panel->getUrl() ?? url($panel->getPath()));
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
     * Porro's email may differ in case from the one on the account here, and
     * whether the database minds depends on the database: MySQL doesn't,
     * Postgres and SQLite do.
     */
    private function findUser(?UserProvider $provider, string $email): ?Authenticatable
    {
        if ($provider === null) {
            return null;
        }

        foreach (array_unique([$email, strtolower($email)]) as $candidate) {
            $user = $provider->retrieveByCredentials(['email' => $candidate]);

            // The provider's own query decides what matched; this settles it.
            if ($user !== null && $this->emailMatches($user, $email)) {
                return $user;
            }
        }

        if (! $provider instanceof EloquentUserProvider) {
            return null;
        }

        // Neither spelling matched exactly. Two accounts that differ only in
        // case would make the answer a guess, so that signs no one in.
        $users = $provider->createModel()->newQuery()
            ->whereRaw('lower(email) = ?', [strtolower($email)])
            ->limit(2)
            ->get()
            ->filter(fn ($user) => $user instanceof Authenticatable && $this->emailMatches($user, $email));

        return $users->count() === 1 ? $users->first() : null;
    }

    private function emailMatches(Authenticatable $user, string $email): bool
    {
        $theirs = data_get($user, 'email');

        return is_string($theirs) && strcasecmp(trim($theirs), $email) === 0;
    }

    private function page(string $reason, int $status, ?string $email = null, ?Panel $panel = null): Response
    {
        $login = null;

        try {
            $login = $panel?->hasLogin() ? $panel->getLoginUrl() : null;
        } catch (Throwable) {
            // No login route to offer; the page reads fine without one.
        }

        return response()
            ->view('porro-filament::sign-in', ['reason' => $reason, 'email' => $email, 'login' => $login], $status)
            // A sign-in answer is for this visitor once, never for a cache.
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
