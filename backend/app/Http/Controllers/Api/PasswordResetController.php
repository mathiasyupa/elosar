<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Password recovery for the doctor's app (medico-web).
 *
 * The Breeze routes in routes/auth.php are session + CSRF based and render Blade
 * on the API domain, which a doctor would read as "I am on the wrong site". The
 * SPA needs plain JSON, so these endpoints exist alongside them.
 *
 * Both answers are deliberately uninformative about whether an address has an
 * account: this form must never become a way to find out which doctors use
 * Elosar. That includes failures — a broken mailer used to answer 500 for real
 * addresses and 302 for unknown ones, which leaked the same fact through the
 * status code.
 */
class PasswordResetController extends Controller
{
    /** Sent whatever happens, so the reply carries no information. */
    private const GENERIC_MESSAGE = 'Si el correo corresponde a una cuenta, te enviamos un enlace para restablecer la contraseña.';

    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => 'Ingresa tu correo.', 'email.email' => 'Ingresa un correo válido.'],
        );

        try {
            Password::sendResetLink($request->only('email'));
        } catch (Throwable $e) {
            // The operator finds out; the caller does not.
            report($e);
        }

        return response()->json(['message' => self::GENERIC_MESSAGE]);
    }

    /**
     * Consume a reset token and set the new password.
     *
     * Every session and API token is dropped on success: if the account was
     * taken over, changing the password has to end the intruder's access too,
     * not just stop them signing in again.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'must_change_password' => false,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Tu contraseña fue actualizada. Ya puedes iniciar sesión.']);
        }

        // An expired or already-used token is the common case, and saying so is
        // safe: the caller had to hold a token to get here.
        return response()->json([
            'message' => 'El enlace no es válido o ya expiró. Solicita uno nuevo.',
            'errors' => ['token' => ['El enlace no es válido o ya expiró.']],
        ], 422);
    }

    /** Exchange an operator-issued temporary password for a private one. */
    public function changeTemporary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! $user->must_change_password
            || ! Hash::check($data['current_password'], $user->password)
            || Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'La clave actual no es válida o la nueva clave es igual a la temporal.',
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ])->save();

        $user->tokens()->delete();

        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión con la nueva clave.']);
    }
}
