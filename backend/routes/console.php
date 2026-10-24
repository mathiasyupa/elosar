<?php

use App\Jobs\SendAppointmentReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send each appointment one email as it enters the next 24 hours. Hourly keeps
// short-notice bookings covered; reminder_sent_at prevents duplicates.
// Prerequisite: MAIL_MAILER must be configured in .env
Schedule::job(new SendAppointmentReminders)->hourly();

// Purge tokens that can no longer sign anyone in, so personal_access_tokens
// doesn't grow unbounded: those past their own expires_at (impersonation), and
// sessions idle longer than sanctum.idle_days (ADR-021).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::call(fn () => \Laravel\Sanctum\PersonalAccessToken::query()
    ->whereRaw('coalesce(last_used_at, created_at) < ?', [now()->subDays((int) config('sanctum.idle_days', 60))])
    ->delete())->daily()->name('prune-idle-sessions');

Schedule::command('elosar:expire-workspace-entitlements')->daily();

// Off-site copy of the database and the volume, nightly at 03:00 Guayaquil.
// withoutOverlapping() because a slow night must not have two dumps competing
// for the same disk and CPU; runInBackground() so it never delays the schedule.
//
// This is the ONLY backup that exists: Railway backs up neither the database nor
// the volume on the current plan. Removing it leaves patient records, X-rays and
// the SRI certificate with no copy anywhere.
Schedule::command('elosar:backup')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();
