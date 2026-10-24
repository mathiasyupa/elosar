<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Checks a deployed environment for the mistakes that are cheap to make, cheap
 * to check, and expensive to discover through a doctor losing data.
 *
 * Every check here exists because the thing it looks for has a way of passing
 * silently: an app that boots fine while its uploads evaporate on the next
 * deploy, seeded demo accounts whose password is "password", a mailer that
 * accepts everything and delivers nothing.
 *
 * Run it against production from the Railway shell after each deploy. It exits
 * non-zero when something is actually broken, so it can gate a pipeline.
 */
class PreflightCommand extends Command
{
    protected $signature = 'elosar:preflight';

    protected $description = 'Verify a deployed environment before letting real doctors near it';

    /** Seeded demo logins (DEPLOY.md). All share the password "password". */
    private const DEMO_EMAILS = [
        'test@example.com',
        'medico@clinica.com',
        'enfermera@clinica.com',
        'recepcion@clinica.com',
        'caja@clinica.com',
        'admin@clinica.com',
    ];

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>Elosar preflight</>  env='.config('app.env').'  url='.config('app.url'));
        $this->newLine();

        $this->checkAppKey();
        $this->checkDebug();
        $this->checkMailer();
        $this->checkDemoAccounts();
        $this->checkStoragePersistence();
        $this->checkSriCertificateFiles();
        $this->checkCors();
        $this->checkFrontendUrl();
        $this->checkEntitlementInvariant();
        $this->checkGoogleOAuth();

        $this->newLine();
        $this->line(sprintf(
            '  %d failure(s), %d warning(s)',
            $this->failures,
            $this->warnings,
        ));

        // Things no process can confirm about itself. Listed rather than faked
        // as checks, so a green run never reads as "everything is verified".
        $this->newLine();
        $this->line('  <options=bold>Not checkable from here — confirm by hand:</>');
        $this->line('    · PostgreSQL backups enabled AND a restore actually rehearsed');
        $this->line('    · A real e-mail received (sign-up or password reset), not just accepted');
        $this->line('    · DMARC record on _dmarc.elosar.site');
        $this->newLine();

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkAppKey(): void
    {
        $key = config('app.key');

        if (blank($key)) {
            $this->failure('APP_KEY', 'not set — encrypted values (SRI certificate password) cannot be read');

            return;
        }

        $this->ok('APP_KEY', 'set');
    }

    private function checkDebug(): void
    {
        if (config('app.debug') && app()->environment('production')) {
            $this->failure('APP_DEBUG', 'true in production — stack traces expose credentials and patient data');

            return;
        }

        $this->ok('APP_DEBUG', config('app.debug') ? 'true (non-production)' : 'false');
    }

    private function checkMailer(): void
    {
        $mailer = config('mail.default');
        $from = (string) config('mail.from.address');

        if ($mailer === 'log') {
            $this->failure('Mail', 'MAIL_MAILER=log — nothing is delivered: no verification links, no team invitations, no appointment reminders');

            return;
        }

        if ($mailer === 'resend' && blank(config('services.resend.key'))) {
            $this->failure('Mail', 'mailer is resend but RESEND_API_KEY is empty');

            return;
        }

        if ($from === '' || str_contains($from, 'example.com')) {
            $this->failure('Mail', "sender is \"{$from}\" — must be an address on a domain verified with the provider");

            return;
        }

        $this->ok('Mail', "{$mailer}, from {$from}");
    }

    private function checkDemoAccounts(): void
    {
        $found = User::query()
            ->whereIn('email', self::DEMO_EMAILS)
            // suspended_at included on purpose: isSuspended() reads the attribute,
            // so leaving it out of the select made every account look reachable.
            ->get(['id', 'email', 'password', 'suspended_at']);

        if ($found->isEmpty()) {
            $this->ok('Demo accounts', 'none present');

            return;
        }

        // What makes these dangerous is not existing, it is being usable: the
        // e-mails and the password are printed in DEPLOY.md, so an account that
        // still accepts "password" and is not suspended is a published login.
        // Once suspended or rotated the door is shut, and reporting a permanent
        // failure for a closed door only teaches everyone to ignore this line.
        $open = $found->filter(
            fn (User $u) => ! $u->isSuspended()
                && $u->password !== null
                && Hash::check('password', $u->password)
        );

        if ($open->isNotEmpty()) {
            $this->failure('Demo accounts', sprintf(
                '%d account(s) reachable with the published password "password": %s',
                $open->count(),
                $open->pluck('email')->implode(', '),
            ));

            return;
        }

        $this->warning('Demo accounts', sprintf(
            '%d seeded account(s) still exist but are disabled (suspended or password rotated) — delete them once you are sure nothing you need hangs off their workspace',
            $found->count(),
        ));
    }

    private function checkStoragePersistence(): void
    {
        $path = storage_path('app');
        $probe = 'preflight-'.uniqid().'.tmp';

        try {
            Storage::disk('local')->put($probe, 'ok');
            $writable = Storage::disk('local')->exists($probe);
            Storage::disk('local')->delete($probe);
        } catch (\Throwable $e) {
            $this->failure('Storage', 'not writable: '.$e->getMessage());

            return;
        }

        if (! $writable) {
            $this->failure('Storage', "{$path} is not writable");

            return;
        }

        if (! app()->environment('production')) {
            $this->ok('Storage', 'writable');

            return;
        }

        // On Railway a volume shows up as its own mount point. Without one the
        // directory still works perfectly — right up until the next deploy
        // replaces the container and takes the SRI certificate, the signatures
        // and every attachment with it.
        if (! $this->isMountPoint($path)) {
            $this->failure('Storage', "{$path} is writable but NOT a mounted volume — uploads are lost on every deploy");

            return;
        }

        $this->ok('Storage', 'writable and on a persistent volume');
    }

    private function checkSriCertificateFiles(): void
    {
        $configured = Workspace::query()->whereNotNull('sri_certificate_path')->get(['id', 'name', 'sri_certificate_path']);

        if ($configured->isEmpty()) {
            $this->ok('SRI certificate', 'none uploaded yet');

            return;
        }

        $missing = $configured->filter(
            fn (Workspace $w) => ! Storage::disk('local')->exists($w->sri_certificate_path)
        );

        if ($missing->isNotEmpty()) {
            $this->failure('SRI certificate', sprintf(
                '%d workspace(s) reference a certificate file that is gone (%s) — the classic symptom of a missing volume',
                $missing->count(),
                $missing->pluck('name')->implode(', '),
            ));

            return;
        }

        $this->ok('SRI certificate', "{$configured->count()} workspace(s), file present");
    }

    private function checkCors(): void
    {
        $origins = config('cors.allowed_origins', []);

        if (in_array('*', (array) $origins, true)) {
            if (app()->environment('production')) {
                $this->failure('CORS', 'allows any origin (*) — must be the exact front-end domain');

                return;
            }

            $this->warning('CORS', 'allows any origin (*) — fine locally, never in production');

            return;
        }

        $this->ok('CORS', implode(', ', (array) $origins) ?: '(none)');
    }

    private function checkFrontendUrl(): void
    {
        $url = (string) config('app.frontend_url');

        if (blank($url)) {
            $this->failure('FRONTEND_URL', 'empty — verification links and the Google callback have nowhere to return to');

            return;
        }

        // A stale value here does not break anything visibly: links are built,
        // sent, and quietly land on the wrong site.
        if (str_contains($url, 'vercel.app') && app()->environment('production')) {
            $this->warning('FRONTEND_URL', "{$url} — still a default Vercel domain; confirm this is really where users should land");

            return;
        }

        $this->ok('FRONTEND_URL', $url);
    }

    private function checkEntitlementInvariant(): void
    {
        $orphans = Workspace::query()->doesntHave('entitlement')->count();

        if ($orphans > 0) {
            $this->failure('Entitlements', "{$orphans} workspace(s) without an entitlement — those are read-only and nobody was told why");

            return;
        }

        $this->ok('Entitlements', 'every workspace has one');
    }

    private function checkGoogleOAuth(): void
    {
        $id = config('services.google.client_id');
        $redirect = (string) config('services.google.redirect');

        if (blank($id)) {
            $this->warning('Google sign-in', 'not configured — the button is inert (harmless, but nobody signs in with Google)');

            return;
        }

        if (blank($redirect)) {
            $this->failure('Google sign-in', 'client configured but GOOGLE_REDIRECT_URI is empty');

            return;
        }

        $expected = rtrim((string) config('app.url'), '/').'/auth/google/callback';

        if ($redirect !== $expected) {
            $this->warning('Google sign-in', "redirect is {$redirect} but APP_URL suggests {$expected} — Google compares these character for character");

            return;
        }

        $this->ok('Google sign-in', 'configured');
    }

    private function isMountPoint(string $path): bool
    {
        $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($mounts as $line) {
            $target = preg_split('/\s+/', $line)[1] ?? null;

            if ($target !== null && rtrim($target, '/') === rtrim($path, '/')) {
                return true;
            }
        }

        return false;
    }

    private function ok(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=green>OK   </> %-18s %s', $label, $detail));
    }

    private function warning(string $label, string $detail): void
    {
        $this->warnings++;
        $this->line(sprintf('  <fg=yellow>WARN </> %-18s %s', $label, $detail));
    }

    private function failure(string $label, string $detail): void
    {
        $this->failures++;
        $this->line(sprintf('  <fg=red;options=bold>FAIL </> %-18s %s', $label, $detail));
    }
}
