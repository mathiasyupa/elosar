<?php

namespace App\Http\Controllers;

use App\Models\GoogleAuthHandoff;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * "Continue with Google" — the OAuth half.
 *
 * These two routes live in routes/web.php, not routes/api.php, on purpose: the
 * handshake needs a session to carry the `state` parameter Google echoes back,
 * which is what stops an attacker from replaying their own authorisation code
 * into somebody else's browser. The API routes have no session, and Socialite's
 * ->stateless() would simply skip that check. The SPA never calls these by AJAX;
 * it navigates the browser to them.
 *
 * Neither route logs anybody in. Both end in a redirect to medico-web carrying a
 * single-use code, which the SPA redeems through Api\AuthController::exchangeGoogle.
 */
class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! $this->configured()) {
            return redirect($this->appUrl('/login?google=no_configurado'));
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (! $this->configured()) {
            return redirect($this->appUrl('/login?google=no_configurado'));
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            // Expired state, a denied consent screen, a replayed code: none of
            // these are actionable by the person staring at the browser, and the
            // detail belongs in the log, not in a query string.
            return redirect($this->appUrl('/login?google=fallo'));
        }

        $email = $googleUser->getEmail();

        // Google publishes whether it has actually verified the address. Trusting
        // an unverified one would let anybody claim an account by signing up to
        // Google with somebody else's address.
        if (! $email || ! ($googleUser->user['email_verified'] ?? false)) {
            return redirect($this->appUrl('/login?google=correo_no_verificado'));
        }

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $email)->first();

        if ($user) {
            $this->link($user, $googleUser->getId());
        }

        $handoff = GoogleAuthHandoff::issueFor(
            googleId: $googleUser->getId(),
            email: $email,
            name: $googleUser->getName(),
            user: $user,
        );

        return redirect($this->appUrl('/auth/google/callback?code='.$handoff->code));
    }

    /**
     * Bind this Google identity to an account that already existed, and treat
     * Google's verification as proof of the address — it is the same evidence the
     * confirmation e-mail would have produced, and it already arrived.
     */
    private function link(User $user, string $googleId): void
    {
        if ($user->google_id === null) {
            $user->forceFill(['google_id' => $googleId])->save();
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
    }

    private function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    private function appUrl(string $path): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/').$path;
    }
}
