<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Notifications\AppointmentReminderNotification;
use App\Services\WorkspaceEntitlementService;
use App\Support\ActiveWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Sends one email reminder when an appointment enters the next 24 hours.
 *
 * Runs hourly via the scheduler (routes/console.php).
 *
 * Logic:
 *   1. Finds appointments with status='scheduled' between now and the next 24 hours.
 *   2. Filters out those that already have reminder_sent_at (avoids duplicates).
 *   3. For each appointment: notifies the patient if they have an email on file.
 *   4. Sets reminder_sent_at = now() without firing other observers.
 *
 * If the mailer is not configured (MAIL_MAILER=log in dev), the email is
 * written to storage/logs/laravel.log without failing the job.
 */
class SendAppointmentReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of retries on failure.
     */
    public int $tries = 3;

    /**
     * Maximum execution time in seconds.
     */
    public int $timeout = 120;

    public function handle(): void
    {
        // Resolved here rather than injected so the job stays callable as
        // `(new SendAppointmentReminders)->handle()`, which is how the
        // scheduler and the regression tests invoke it.
        $entitlements = app(WorkspaceEntitlementService::class);

        $from = now();
        $to = $from->copy()->addDay();

        // Daily cross-tenant sweep: no session/workspace is active, so the
        // tenant scope is explicitly disabled for this read only.
        $appointments = app(ActiveWorkspace::class)->runUnguarded(
            fn () => Appointment::with(['patient', 'doctor.workspace', 'workspace.entitlement'])
                ->where('status', 'scheduled')
                ->whereBetween('appointment_date', [$from, $to])
                ->whereNull('reminder_sent_at')
                // A platform_admin suspension must stop patient-facing side
                // effects immediately too, not just interactive access.
                ->whereHas('workspace', fn ($q) => $q->whereNull('suspended_at'))
                ->get()
        );

        $sent = 0;
        $skipped = 0;

        foreach ($appointments as $appointment) {
            // A read-only workspace keeps its data readable, but Elosar stops
            // acting on its behalf towards patients. Unlike SRI vouchers, a
            // reminder has no half-finished state to protect: skipping it
            // leaves nothing inconsistent.
            if ($entitlements->effectiveAccess($appointment->workspace) !== WorkspaceEntitlementService::ACCESS_FULL) {
                $skipped++;

                continue;
            }

            $patient = $appointment->patient;

            if (! $patient || ! $patient->email) {
                $skipped++;

                continue;
            }

            try {
                // Sent now, inside this job, not queued again: a queued notification
                // is rebuilt by the worker with no workspace bound, so loading its
                // Patient tripped the tenant guard and every reminder failed —
                // after reminder_sent_at had already been stamped.
                Notification::sendNow($patient, new AppointmentReminderNotification($appointment));

                // Mark as sent without firing model events
                $appointment->updateQuietly(['reminder_sent_at' => now()]);

                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Could not send reminder for appointment #{$appointment->id}: ".$e->getMessage());
            }
        }

        Log::info("SendAppointmentReminders: {$sent} sent, {$skipped} skipped (no email).");
    }
}
