<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * E-mail confirmation for self-registered accounts.
 *
 * The Breeze equivalents in routes/auth.php sit behind `auth`, which assumes a
 * session on the API domain. Someone clicking a link in their inbox has no such
 * session, so those routes would bounce them to a login page on a domain they
 * have never seen. These are public, verify by signature, and hand the doctor
 * back to elosar.site.
 */
class EmailVerificationController extends Controller
{
    /** Same answer whether or not the address exists, like password recovery. */
    private const GENERIC_MESSAGE = 'Si la cuenta existe y aún no está confirmada, te enviamos un enlace nuevo.';

    public function resend(Request $request): JsonResponse
    {
        $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => 'Ingresa tu correo.', 'email.email' => 'Ingresa un correo válido.'],
        );

        $user = User::where('email', $request->string('email'))->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $e) {
                // A broken mailer must not tell the caller whether the account
                // exists — the same trap password recovery already fell into.
                report($e);
            }
        }

        return response()->json(['message' => self::GENERIC_MESSAGE]);
    }

    /**
     * Consume the signed link and send the doctor back to the app.
     *
     * The signature is checked by hand rather than with the `signed` middleware
     * so an expired link lands on a page that explains itself instead of a bare
     * 403 — expiry is the ordinary case here, not an attack.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect($this->appUrl('/login?verificacion=expirada'));
        }

        $user = User::find($id);

        // hash_equals, not ==: comparing digests in constant time keeps the
        // check from leaking through timing.
        if (! $user || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return redirect($this->appUrl('/login?verificacion=invalida'));
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        // Confirming twice is a success, not an error: people click the link
        // again, or their mail client prefetches it.
        return redirect($this->appUrl('/login?verificacion=ok'));
    }

    private function appUrl(string $path): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/').$path;
    }
}
