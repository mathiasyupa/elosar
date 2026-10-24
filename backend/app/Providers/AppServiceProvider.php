<?php

namespace App\Providers;

use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\ActiveWorkspace;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Sentry\State\Scope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Active workspace service shared throughout the request.
        $this->app->scoped(ActiveWorkspace::class, fn () => new ActiveWorkspace());

        // Billing services — per-request singletons.
        $this->app->scoped(InvoiceService::class);
        $this->app->scoped(PaymentService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configurePasswordResetNotification();
        $this->configureEmailVerificationNotification();
        $this->configureErrorMonitoring();
        $this->configureSessionLifetime();
    }

    /**
     * A session ends by signing out, or after config('sanctum.idle_days') with
     * no request at all — never at a fixed date while it is in use (ADR-021).
     */
    private function configureSessionLifetime(): void
    {
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid): bool {
            $lastSeen = $token->last_used_at ?? $token->created_at;

            return $isValid && ($lastSeen === null || $lastSeen->gt(now()->subDays((int) config('sanctum.idle_days', 60))));
        });
    }

    /**
     * Tell Sentry WHO hit an error, and nothing more.
     *
     * An account id is enough to find the user in the SuperAdmin panel. A name
     * or an e-mail would put a real person's identity into a third-party system
     * for no diagnostic gain — see App\Support\SentryScrubber, which enforces
     * the same rule on the way out.
     *
     * Hooked to the Authenticated event so it covers every guard and every entry
     * point (API tokens, Filament sessions, impersonation) without a middleware
     * to remember to register.
     */
    private function configureErrorMonitoring(): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        Event::listen(Authenticated::class, function (Authenticated $event): void {
            \Sentry\configureScope(function (Scope $scope) use ($event): void {
                $scope->setUser(['id' => $event->user->getAuthIdentifier()]);
            });
        });
    }

    /**
     * Point verification at the public API route and write the e-mail in Spanish.
     *
     * Laravel's default targets `verification.verify` in routes/auth.php, which
     * sits behind `auth` — but the person clicking has no session on the API
     * domain, so they would be bounced to a login page instead of being verified.
     *
     * Note the asymmetry with password reset above: VerifyEmail::toMailUsing
     * receives the URL, ResetPassword::toMailUsing receives the TOKEN. That is
     * Laravel's own inconsistency, and it is easy to get burned by it.
     */
    private function configureEmailVerificationNotification(): void
    {
        VerifyEmail::createUrlUsing(fn (object $notifiable): string => URL::temporarySignedRoute(
            'api.verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        ));

        VerifyEmail::toMailUsing(fn (object $notifiable, string $url): MailMessage => (new MailMessage)
            ->subject('Confirma tu correo en Elosar')
            ->greeting('Hola,')
            ->line('Confirma esta dirección para activar tu cuenta y poder entrar a Elosar.')
            ->action('Confirmar mi correo', $url)
            ->line('Lo pedimos por una razón práctica: si algún día olvidas tu contraseña, este es el correo al que enviaremos el enlace para recuperarla.')
            ->line('Si no creaste ninguna cuenta en Elosar, puedes ignorar este mensaje.')
            ->salutation('— El equipo de Elosar'));
    }

    /**
     * Point the reset link at medico-web and write the e-mail in Spanish.
     *
     * Laravel's default link targets the Blade page Breeze serves on the API
     * domain — a doctor who lands there sees a different-looking site at an
     * unfamiliar address, which is exactly what a phishing attempt looks like.
     * The link has to end on elosar.site, where they already work.
     */
    private function configurePasswordResetNotification(): void
    {
        // Defined once and used twice on purpose. toMailUsing() receives the
        // TOKEN, not the URL — passing it straight to action() would render a
        // button linking to a bare token string.
        $resetUrl = fn (object $notifiable, string $token): string => sprintf(
            '%s/restablecer-contrasena?token=%s&email=%s',
            rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/'),
            $token,
            urlencode($notifiable->getEmailForPasswordReset()),
        );

        ResetPassword::createUrlUsing($resetUrl);

        // Spanish, like every message that reaches a user (see the language
        // convention in .claude/CLAUDE.md — code in English, product in Spanish).
        ResetPassword::toMailUsing(fn (object $notifiable, string $token): MailMessage => (new MailMessage)
            ->subject('Restablece tu contraseña de Elosar')
            ->greeting('Hola,')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta de Elosar.')
            ->action('Crear una contraseña nueva', $resetUrl($notifiable, $token))
            ->line(sprintf(
                'El enlace caduca en %d minutos y solo se puede usar una vez.',
                (int) config('auth.passwords.users.expire', 60),
            ))
            ->line('Si no fuiste tú, puedes ignorar este correo: tu contraseña actual sigue siendo válida.')
            ->salutation('— El equipo de Elosar'));
    }

    /**
     * Named rate limiters for the token-based API.
     *
     *  - `login` protects credential endpoints (login/register) against
     *    brute-force and credential stuffing. Keyed by email+IP so one attacker
     *    can't lock out a whole clinic behind a shared NAT, with a per-IP ceiling.
     *  - `api` is a blanket per-user (or per-IP) ceiling for authenticated tenant
     *    traffic, applied globally to the `api` middleware group in bootstrap/app.php.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request): Limit =>
            Limit::perMinute(120)->by($request->user()?->id ?: $request->ip())
        );
    }
}
