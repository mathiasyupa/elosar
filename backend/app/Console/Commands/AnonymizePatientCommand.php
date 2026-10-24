<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Support\ActiveWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * LOPDP right-to-erasure (art. 12). Strips a patient's identifying/contact PII
 * while PRESERVING the clinical history, which is subject to the sanitary
 * conservation obligation and cannot simply be deleted. Run manually on a
 * verified data-subject request.
 *
 * What it CANNOT do on its own: the inside of an attached file. A lab report
 * or a scanned X-ray carries the name printed on it, and no database update
 * reaches that. So it reports those files by name instead of staying silent —
 * an operator handling a verified request has to know there is a manual step.
 *
 * Keeping them is the default: the clinical history is under a conservation
 * duty. `--purge-files` is for when the operator has decided, for this request,
 * that erasure wins.
 *
 * Usage: php artisan patients:anonymize {id} [--force] [--purge-files]
 */
class AnonymizePatientCommand extends Command
{
    protected $signature = 'patients:anonymize
        {id : Patient id}
        {--force : Skip confirmation}
        {--purge-files : Also delete attached files and their rows (erasure wins over retention)}';

    protected $description = 'Anonymize a patient\'s PII (LOPDP right to erasure) while keeping the clinical record';

    public function handle(ActiveWorkspace $activeWorkspace): int
    {
        $id = (int) $this->argument('id');

        // CLI has no active tenant; read across workspaces explicitly (auditable).
        // withTrashed: the app ARCHIVES any patient with clinical history, and this
        // command archives too as its last step. Looking only among the living left out
        // exactly the people who file an erasure request, and made it un-repeatable.
        $patient = $activeWorkspace->runUnguarded(fn () => Patient::withTrashed()->find($id));

        if (! $patient) {
            $this->error("Patient #{$id} not found.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Anonymize patient #{$id} ({$patient->name})? This cannot be undone."
        )) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $activeWorkspace->runUnguarded(function () use ($patient): void {
            // NOT NULL columns (name/id_number/city/email/phone) get neutral
            // placeholders; genuinely optional PII is cleared to null.
            $patient->forceFill([
                'name'                    => 'PACIENTE ANONIMIZADO',
                'id_number'               => 'ANON-'.$patient->id,
                'email'                   => 'anon-'.$patient->id.'@anonimizado.local',
                'phone'                   => 'N/A',
                'city'                    => 'N/A',
                // A street address points at a person far more precisely than a
                // city does. Leaving it behind would defeat the whole command.
                'address'                 => null,
                'emergency_contact_name'  => null,
                'emergency_contact_phone' => null,
                'insurance_provider'      => null,
                'insurance_policy_number' => null,
                'personal_background'     => null,
                'consent_marketing'       => false,
            ])->save();

            $patient->allergies()->delete();
            $patient->familyBackground()->delete();

            // The gyneco-obstetric history is a profile of the woman, not a
            // clinical event: it goes, like allergies and family background.
            $patient->gynecoHistory()->delete();

            // Standing alerts are free text somebody typed about this person.
            $patient->alerts()->delete();

            // Pregnancies are care that was given, so the rows stay for legal
            // retention — but their free-text fields are the one place in them
            // where a name or an address can have been written down.
            $patient->pregnancies()->update([
                'risk_notes'    => null,
                'outcome_notes' => null,
            ]);

            $patient->delete(); // soft delete: removes from active use, keeps the row
        });

        $this->info("Patient #{$id} anonymized. Clinical records preserved for legal retention.");

        $this->reportAttachments($activeWorkspace, $patient);

        return self::SUCCESS;
    }

    /**
     * Says what the database scrub could not reach.
     *
     * Silence here is the actual defect: the command printed "anonymized" while
     * a PDF with the patient's name on it stayed readable on the volume, and in
     * every nightly backup taken since.
     */
    private function reportAttachments(ActiveWorkspace $activeWorkspace, Patient $patient): void
    {
        $attachments = $activeWorkspace->runUnguarded(
            fn () => $patient->attachments()->get(['id', 'title', 'file_path'])
        );

        if ($attachments->isEmpty()) {
            return;
        }

        $count = $attachments->count();
        $noun = $count === 1 ? '1 attached file' : "{$count} attached files";

        if (! $this->option('purge-files')) {
            $this->warn("NOT ANONYMIZED: {$noun}. A lab report or a scanned X-ray carries");
            $this->warn('the name printed inside the file, and no UPDATE reaches that:');
            foreach ($attachments as $a) {
                $this->warn("  - #{$a->id} {$a->title} ({$a->file_path})");
            }
            $this->warn('Keep them (clinical retention) or re-run with --purge-files.');

            return;
        }

        $failed = [];
        foreach ($attachments as $a) {
            // A file that is already gone must not abort the rest: the erasure
            // request still has to finish.
            try {
                Storage::disk('local')->delete($a->file_path);
            } catch (Throwable $e) {
                $failed[] = $a->file_path;
            }
        }

        $activeWorkspace->runUnguarded(fn () => $patient->attachments()->delete());

        $this->info("Erased {$noun} and their rows.");

        if ($failed !== []) {
            $this->warn('Could not delete from disk: '.implode(', ', $failed));
            $this->warn('The rows are gone; check the volume by hand.');
        }

        $this->warn('Backups taken before now still contain those files until their');
        $this->warn('retention window rotates. That is not something this command can reach.');
    }
}
