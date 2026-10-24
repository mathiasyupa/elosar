<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * The answer is deliberately identical whether or not the address belongs to
     * an account. Breeze's default replies "We can't find a user with that email
     * address", which turns this form into an oracle for discovering which
     * doctors use Elosar — one address at a time. The token API was already
     * hardened against exactly this (see SecurityHardeningTest, "returns an
     * identical error for unknown email and wrong password"); this web route had
     * been left behind.
     *
     * RESET_THROTTLED is folded into the same reply for the same reason: it only
     * ever occurs for an address that does have an account, so surfacing it would
     * leak the very fact we are hiding. The user is told to check their inbox,
     * which is true — a link was sent moments ago.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );
        } catch (Throwable $e) {
            // A broken mailer must not become the oracle we just closed. Before
            // this, an unknown address redirected with a message while a real one
            // threw a 500 — so the status code alone told an attacker which
            // addresses have accounts, no wording required. Ask Railway: that is
            // exactly what happened the day SMTP was blocked on port 587.
            //
            // The operator is told through report(); the visitor is not.
            report($e);

            $status = Password::RESET_LINK_SENT;
        }

        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return back()->with('status', __(Password::RESET_LINK_SENT));
        }

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }
}
