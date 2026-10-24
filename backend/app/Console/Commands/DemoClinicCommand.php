<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Cie10Code;
use App\Models\Doctor;
use App\Models\FamilyBackground;
use App\Models\MedicalRecord;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\PatientGynecoHistory;
use App\Models\PatientImmunization;
use App\Models\Pregnancy;
use App\Models\RecordDiagnosis;
use App\Models\RecordPrescription;
use App\Models\RecordVital;
use App\Models\Specialty;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\Vaccine;
use App\Models\Workspace;
use App\Services\ClinicService;
use App\Services\InvoiceService;
use App\Services\Obstetrics\GestationalAge;
use App\Services\PaymentService;
use App\Services\WorkspaceEntitlementService;
use App\Support\ActiveWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A fictional clinic for demos and promo videos, safe to run in production.
 *
 * Unlike the local demo seeders (which reset well-known logins to "password"
 * and touch real accounts), this only ever creates accounts under
 *
 * @demo.elosar.site, with the password the operator passes, already verified
 * so nobody has to open a mailbox. Every patient is invented and its cédula
 * starts with 99 (no province issues it), so it cannot match a real person.
 *
 * Idempotent: the first run builds everything; later runs only move the day
 * queue to today, so running it the morning of a recording leaves the waiting
 * room and agenda ready.
 */
class DemoClinicCommand extends Command
{
    protected $signature = 'elosar:demo-clinic {--password= : Password for the five demo accounts (min. 10 characters)}';

    protected $description = 'Create (or refresh) the fictional "Clínica Demo Elosar" with its team, patients and today\'s agenda.';

    public const CLINIC_NAME = 'Clínica Demo Elosar';

    public const EMAIL_DOMAIN = 'demo.elosar.site';

    /** Marks the appointments this command owns, so a refresh moves only them. */
    private const QUEUE_NOTE = 'Agenda demo';

    public function handle(ActiveWorkspace $active): int
    {
        $password = (string) $this->option('password');

        if (mb_strlen($password) < 10) {
            $this->error('Pasa --password con al menos 10 caracteres: estas cuentas existen en producción.');

            return self::FAILURE;
        }

        $clinic = Workspace::query()->where('type', 'clinic')->where('name', self::CLINIC_NAME)->first();

        if ($clinic) {
            $active->runUnguarded(function () use ($clinic): void {
                $this->moveQueueToToday($clinic);
                $this->fillWaitingRoom($clinic);
            });
            $this->enrich($active, $clinic);
            $this->info('La clínica demo ya existía: la agenda de hoy quedó al día.');
            $this->printLogins();

            return self::SUCCESS;
        }

        $active->runUnguarded(fn () => DB::transaction(fn () => $this->build($password)));
        $built = Workspace::query()->where('type', 'clinic')->where('name', self::CLINIC_NAME)->firstOrFail();
        $active->runUnguarded(fn () => $this->fillWaitingRoom($built));
        $this->enrich($active, Workspace::query()->where('type', 'clinic')->where('name', self::CLINIC_NAME)->firstOrFail());

        $this->info('Clínica demo creada.');
        $this->printLogins();

        return self::SUCCESS;
    }

    /**
     * Months of history, documents, a letterhead and a colourful week (see
     * DemoClinicEnricher). A failure here never breaks the deploy or the demo.
     */
    private function enrich(ActiveWorkspace $active, Workspace $clinic): void
    {
        try {
            $active->runUnguarded(fn () => app(\App\Support\DemoClinicEnricher::class)->run($clinic->fresh()));
        } catch (\Throwable $e) {
            $this->warn('Demo enrichment skipped: '.$e->getMessage());
        }
    }

    private function build(string $password): void
    {
        $owner = $this->doctor('Dr. Andrés Salazar Vega', 'dr.salazar', ['Medicina General', 'Medicina Interna'], '9900000017', '0991234501', 'MSP-1712-2015', $password);
        $gyneco = $this->doctor('Dra. Valeria Ortiz Mena', 'dra.ortiz', ['Ginecología', 'Ginecología y Obstetricia'], '9900000025', '0991234502', 'MSP-1745-2018', $password);

        $clinic = Workspace::query()->create([
            'name' => self::CLINIC_NAME,
            'type' => 'clinic',
            'invite_code' => strtoupper(Str::random(8)),
            'owner_doctor_id' => $owner->id,
            'owner_user_id' => $owner->user_id,
            'prescription_settings' => [
                'header_mode' => 'digital',
                'header' => self::CLINIC_NAME,
                'subheader' => 'Medicina General · Ginecología',
                'address' => 'Av. Amazonas N00-00 y Ejemplo, Quito',
                'phone' => '(02) 000-0000',
                'email' => 'contacto@'.self::EMAIL_DOMAIN,
                'footer' => 'Atención de lunes a sábado · Documento de demostración',
                'issue_city' => 'QUITO',
                'validity_days' => 3,
            ],
        ]);

        $clinics = app(ClinicService::class);
        $clinics->attachDoctor($clinic, $owner, role: 'doctor');
        $clinics->attachDoctor($clinic, $gyneco, role: 'doctor');
        app(WorkspaceEntitlementService::class)->provisionLegacyEntitlement($clinic, null, 'Clínica de demostración (elosar:demo-clinic).');

        $nurse = null;
        foreach ([
            ['Carla Mendoza', 'recepcion', 'receptionist', 'Recepción'],
            ['Lucía Paredes', 'enfermeria', 'nurse', 'Enfermera'],
            ['Diego Torres', 'caja', 'billing', 'Caja'],
        ] as [$name, $local, $role, $title]) {
            $user = $this->account($name, $local, 'staff', $password);
            StaffProfile::query()->updateOrCreate(['user_id' => $user->id], ['name' => $name, 'job_title' => $title, 'phone' => '0991234'.random_int(510, 599)]);
            $clinics->attachMember($clinic, $user->fresh('staffProfile'), $role);
            $nurse = $role === 'nurse' ? $user : $nurse;
        }

        $services = $this->services($clinic);
        $patients = $this->patients($clinic, $owner, $gyneco, $nurse);
        $this->invoices($clinic, $owner, $patients, $services);
        $this->moveQueueToToday($clinic);
    }

    // ── Team ─────────────────────────────────────────────────────────────────

    private function account(string $name, string $local, string $type, string $password): User
    {
        $user = User::query()->firstOrNew(['email' => $local.'@'.self::EMAIL_DOMAIN]);
        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'account_type' => $type,
            'must_change_password' => false,
            // Invented address on a domain nobody reads: verifying by mail is
            // impossible, and these accounts belong to no real person.
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    /** @param list<string> $specialties first match wins */
    private function doctor(string $name, string $local, array $specialties, string $cedula, string $phone, string $license, string $password): Doctor
    {
        $user = $this->account($name, $local, 'doctor', $password);
        $specialty = Specialty::query()->whereIn('name', $specialties)->first() ?? Specialty::query()->firstOrCreate(['name' => $specialties[0]]);

        $doctor = Doctor::query()->updateOrCreate(['email' => $user->email], [
            'name' => $name,
            'user_id' => $user->id,
            'specialty_id' => $specialty->id,
            'id_number' => $cedula,
            'city' => 'Quito',
            'phone' => $phone,
            'medical_license' => $license,
        ])->fresh();

        if ($doctor->workspace) {
            app(WorkspaceEntitlementService::class)->provisionLegacyEntitlement($doctor->workspace);
        }

        return $doctor;
    }

    // ── Catalog and money ────────────────────────────────────────────────────

    /** @return array<string, MedicalService> */
    private function services(Workspace $clinic): array
    {
        $catalog = [
            'consulta' => ['Consulta médica general', 'general', '30.00'],
            'gineco' => ['Consulta de ginecología', 'general', '40.00'],
            'control' => ['Control / seguimiento', 'general', '20.00'],
            'eco' => ['Ecografía obstétrica', 'imaging', '45.00'],
            'bh' => ['Biometría hemática completa', 'laboratory', '12.00'],
            'nebu' => ['Nebulización', 'procedure', '10.00'],
        ];

        $services = [];
        foreach (array_values($catalog) as $i => [$name, $category, $price]) {
            $services[array_keys($catalog)[$i]] = MedicalService::query()->create([
                'workspace_id' => $clinic->id, 'name' => $name, 'category' => $category,
                'base_price' => $price, 'iva_rate' => '0.00', 'is_active' => true, 'sort_order' => $i + 1,
            ]);
        }

        return $services;
    }

    /**
     * @param  list<Patient>  $patients
     * @param  array<string, MedicalService>  $services
     */
    private function invoices(Workspace $clinic, Doctor $owner, array $patients, array $services): void
    {
        $invoices = app(InvoiceService::class);
        $payments = app(PaymentService::class);

        foreach ([[1, 'consulta', 'cash', 6], [2, 'control', 'card', 12], [4, 'consulta', 'transfer', 20], [6, 'nebu', 'cash', 3]] as [$index, $service, $method, $daysAgo]) {
            $patient = $patients[$index];
            $item = $services[$service];

            $invoice = $invoices->create([
                'workspace_id' => $clinic->id,
                'patient_id' => $patient->id,
                'created_by' => $owner->id,
                'issued_at' => Carbon::today()->subDays($daysAgo)->toDateString(),
                'items' => [[
                    'medical_service_id' => $item->id, 'description' => $item->name, 'quantity' => '1',
                    'unit_price' => (string) $item->base_price, 'iva_rate' => '0.00', 'discount_percentage' => '0', 'sort_order' => 0,
                ]],
                'buyer_id_type' => '05',
                'buyer_id' => $patient->id_number,
                'buyer_name' => $patient->name,
                'buyer_address' => $patient->city ?? 'Quito',
                'buyer_email' => $patient->email,
            ]);

            $payments->register($invoice, [
                'amount' => (string) $invoice->amount_due,
                'payment_method' => $method,
                'payment_date' => $invoice->issued_at->toDateString(),
                'registered_by' => $owner->id,
            ]);
        }
    }

    // ── Patients ─────────────────────────────────────────────────────────────

    /** @return list<Patient> */
    private function patients(Workspace $clinic, Doctor $owner, Doctor $gyneco, ?User $nurse): array
    {
        $cie = fn (string $code, string $fallback) => Cie10Code::query()->where('code', $code)->value('description') ?? $fallback;
        $out = [];

        foreach ($this->roster() as $i => $p) {
            $patient = Patient::query()->create([
                'workspace_id' => $clinic->id,
                'name' => $p['name'],
                'id_number' => sprintf('99%08d', 101 + $i),
                'document_type' => 'cedula',
                'birth_date' => Carbon::today()->subYears($p['age'])->subDays(40 + $i * 23),
                'gender' => $p['gender'],
                'civil_status' => $p['civil'] ?? 'single',
                'city' => $p['city'] ?? 'Quito',
                'address' => 'Calle Ejemplo '.(10 + $i).' y Demo',
                'nationality' => 'Ecuatoriana',
                'phone' => '09900000'.str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT),
                'email' => Str::slug($p['name'], '.').'@'.self::EMAIL_DOMAIN,
                'blood_type' => $p['blood'] ?? 'O+',
                'emergency_contact_name' => $p['contact'] ?? 'Familiar de contacto',
                'emergency_contact_phone' => '0990000099',
                'personal_background' => $p['background'],
                'insurance_provider' => $p['insurance'] ?? null,
                'insurance_policy_number' => isset($p['insurance']) ? 'POL-DEMO-'.(100 + $i) : null,
                'data_consent_at' => now(),
                'data_consent_version' => config('lopdp.consent_version'),
            ]);

            foreach ($p['allergies'] ?? [] as [$type, $name, $severity, $notes]) {
                PatientAllergy::query()->create(['patient_id' => $patient->id, 'allergen_type' => $type, 'allergen_name' => $name, 'severity' => $severity, 'notes' => $notes]);
            }

            foreach ($p['family'] ?? [] as [$disease, $relative]) {
                FamilyBackground::query()->create(['patient_id' => $patient->id, 'disease' => $disease, 'relative_type' => $relative]);
            }

            if (isset($p['gyneco'])) {
                PatientGynecoHistory::query()->create(['workspace_id' => $clinic->id, 'patient_id' => $patient->id] + $p['gyneco']);
            }

            $pregnancy = null;
            if (isset($p['pregnant_weeks'])) {
                $dating = ['lmp_date' => Carbon::today()->subWeeks($p['pregnant_weeks'])->toDateString(), 'lmp_is_reliable' => true, 'dating_source' => 'lmp'];
                $pregnancy = Pregnancy::query()->create([
                    'workspace_id' => $clinic->id, 'patient_id' => $patient->id, 'status' => 'active', 'fetus_count' => 1,
                ] + $dating + GestationalAge::columnsFor($dating));
            }

            foreach ($p['visits'] as $v) {
                $doctor = ($v['doctor'] ?? 'owner') === 'gyneco' ? $gyneco : $owner;
                $when = Carbon::today()->subDays($v['days_ago'])->setTime(10, 30);

                $record = MedicalRecord::query()->create([
                    'workspace_id' => $clinic->id,
                    'patient_id' => $patient->id,
                    'pregnancy_id' => ($v['prenatal'] ?? false) ? $pregnancy?->id : null,
                    'created_by' => $doctor->id,
                    'status' => 'completed',
                    'reason_for_consultation' => $v['reason'],
                    'current_illness' => $v['illness'],
                    'physical_examination' => $v['exam'] ?? 'Paciente consciente, orientada, hidratada. Sin hallazgos de alarma.',
                    'diagnosis' => $v['dx'][1],
                    'treatment' => $v['plan'],
                    'recommendations' => $v['advice'] ?? 'Control según evolución. Acudir antes si hay signos de alarma.',
                    'follow_up_date' => isset($v['follow_up']) ? Carbon::today()->addDays($v['follow_up']) : null,
                ]);
                $record->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();

                [$sys, $dia, $hr, $temp, $sat, $weight, $height] = $v['vitals'];
                RecordVital::query()->create([
                    'medical_record_id' => $record->id, 'blood_pressure_sys' => $sys, 'blood_pressure_dia' => $dia,
                    'heart_rate' => $hr, 'temperature' => $temp, 'oxygen_saturation' => $sat, 'respiratory_rate' => 18,
                    'weight_kg' => $weight, 'height_cm' => $height, 'bmi' => round($weight / (($height / 100) ** 2), 2),
                    'recorded_at' => $when,
                ]);

                RecordDiagnosis::query()->create([
                    'medical_record_id' => $record->id, 'cie10_code' => $v['dx'][0],
                    'cie10_description' => $cie($v['dx'][0], $v['dx'][1]), 'type' => 'primary',
                ]);

                foreach ($v['rx'] ?? [] as [$med, $generic, $conc, $dose, $freq, $daysN, $qty]) {
                    RecordPrescription::query()->create([
                        'medical_record_id' => $record->id, 'medication_name' => $med, 'generic_name' => $generic,
                        'concentration' => $conc, 'dosage' => $dose, 'route' => 'Oral', 'frequency' => $freq,
                        'duration_days' => $daysN, 'quantity' => $qty, 'is_controlled' => false,
                    ]);
                }
            }

            $this->vaccinate($clinic, $patient, $p['vaccines'] ?? 0, $nurse);
            $out[] = $patient;
        }

        $this->appointments($clinic, $out, $owner, $gyneco);

        return $out;
    }

    private function vaccinate(Workspace $clinic, Patient $patient, int $count, ?User $nurse): void
    {
        if ($count === 0) {
            return;
        }

        foreach (Vaccine::query()->where('is_active', true)->orderBy('sort_order')->limit($count)->get() as $i => $vaccine) {
            PatientImmunization::query()->create([
                'workspace_id' => $clinic->id, 'patient_id' => $patient->id, 'vaccine_id' => $vaccine->id,
                'vaccine_name' => $vaccine->name, 'dose_label' => 'dose_1',
                'administered_at' => Carbon::today()->subMonths(2 + $i * 3)->toDateString(),
                'lot' => 'DEMO-'.(1000 + $i), 'administered_by_user_id' => $nurse?->id,
            ]);
        }
    }

    // ── Today's agenda ───────────────────────────────────────────────────────

    /**
     * Today's queue in every stage the video walks through, plus the next days.
     * Hours are stored as an offset in `duration_minutes`-free form: the slot
     * is rebuilt from the list below every time the queue moves to today.
     *
     * @param  list<Patient>  $patients
     */
    private function appointments(Workspace $clinic, array $patients, Doctor $owner, Doctor $gyneco): void
    {
        foreach ($this->agenda() as [$index, $who, $dayOffset, $hour, $status]) {
            Appointment::query()->create([
                'workspace_id' => $clinic->id,
                'patient_id' => $patients[$index]->id,
                'doctor_id' => ($who === 'gyneco' ? $gyneco : $owner)->id,
                'appointment_date' => Carbon::today()->addDays($dayOffset)->setTime((int) $hour, (int) round(($hour - (int) $hour) * 60)),
                'status' => $status,
                'type' => 'consultation',
                'channel' => 'in_person',
                'notes' => self::QUEUE_NOTE.' #'.$index.'-'.$dayOffset,
            ]);
        }
    }

    /**
     * Re-dates the demo agenda so "today" is always today, and resets each
     * appointment to the stage the script needs (a recording may have moved
     * María José to "atendida" yesterday).
     */
    private function moveQueueToToday(Workspace $clinic): void
    {
        $byNote = Appointment::query()->where('workspace_id', $clinic->id)->where('notes', 'like', self::QUEUE_NOTE.' #%')->get()->keyBy('notes');

        foreach ($this->agenda() as [$index, , $dayOffset, $hour, $status]) {
            $appointment = $byNote->get(self::QUEUE_NOTE.' #'.$index.'-'.$dayOffset);
            if (! $appointment) {
                continue;
            }

            $when = Carbon::today()->addDays($dayOffset)->setTime((int) $hour, (int) round(($hour - (int) $hour) * 60));
            $checkedIn = in_array($status, ['checked_in', 'vitals_done', 'completed'], true);

            $appointment->forceFill([
                'appointment_date' => $when,
                'status' => $status,
                'checked_in_at' => $checkedIn ? $when : null,
            ])->saveQuietly();
        }
    }

    /**
     * Today's waiting room, as the video needs it: whoever the agenda has at
     * «Signos tomados» gets the nurse's draft with vitals, and every visit still
     * to be attended today is cleared of the atención a previous take left on it
     * — otherwise «Atender» reopens yesterday's recording instead of a fresh one.
     */
    private function fillWaitingRoom(Workspace $clinic): void
    {
        $vitals = [
            1 => ['weight_kg' => 71, 'height_cm' => 172, 'blood_pressure_sys' => 128, 'blood_pressure_dia' => 82, 'heart_rate' => 104, 'temperature' => 38.6, 'oxygen_saturation' => 93, 'respiratory_rate' => 22],
            6 => ['weight_kg' => 58, 'height_cm' => 160, 'blood_pressure_sys' => 112, 'blood_pressure_dia' => 74, 'heart_rate' => 76, 'temperature' => 36.7, 'oxygen_saturation' => 98, 'respiratory_rate' => 16],
        ];

        $today = Appointment::query()
            ->where('workspace_id', $clinic->id)
            ->where('notes', 'like', self::QUEUE_NOTE.' #%-0')
            ->whereIn('status', ['scheduled', 'checked_in', 'vitals_done'])
            ->get();

        foreach ($today as $appointment) {
            MedicalRecord::query()->where('appointment_id', $appointment->id)->get()->each->delete();

            $index = (int) Str::between((string) $appointment->notes, '#', '-');
            if ($appointment->status !== 'vitals_done' || ! isset($vitals[$index])) {
                continue;
            }

            $record = MedicalRecord::query()->create([
                'workspace_id' => $clinic->id,
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'status' => 'vitals_done',
                'triaged_at' => now(),
            ]);
            $v = $vitals[$index];
            $record->vitals()->create($v + [
                'bmi' => round($v['weight_kg'] / (($v['height_cm'] / 100) ** 2), 2),
                'recorded_at' => now(),
            ]);
        }
    }

    /** @return list<array{int, string, int, float, string}> patient index, doctor, day offset, hour, status */
    private function agenda(): array
    {
        return [
            [5, 'owner', 0, 8.0, 'completed'],
            [8, 'gyneco', 0, 8.5, 'completed'],
            [0, 'owner', 0, 9.0, 'checked_in'],     // ⭐ María José: enfermería toma signos → Elo
            [1, 'owner', 0, 9.5, 'vitals_done'],   // sala de espera: fiebre, SpO2 baja (en rojo)
            [3, 'gyneco', 0, 10.0, 'scheduled'],    // Ana Lucía, control prenatal
            [6, 'owner', 0, 10.5, 'vitals_done'],  // sala de espera: signos normales
            [7, 'gyneco', 0, 11.0, 'scheduled'],
            [9, 'owner', 0, 11.5, 'scheduled'],
            [2, 'owner', 1, 9.0, 'scheduled'],
            [4, 'gyneco', 1, 10.0, 'scheduled'],
            [5, 'owner', 2, 15.0, 'scheduled'],
            [8, 'gyneco', 3, 9.5, 'scheduled'],
            [1, 'owner', 7, 10.0, 'scheduled'],
            [3, 'gyneco', 14, 10.0, 'scheduled'],
        ];
    }

    private function printLogins(): void
    {
        $this->newLine();
        $this->table(['Rol', 'Correo'], [
            ['Médico (dueño)', 'dr.salazar@'.self::EMAIL_DOMAIN],
            ['Médica (ginecología)', 'dra.ortiz@'.self::EMAIL_DOMAIN],
            ['Recepción', 'recepcion@'.self::EMAIL_DOMAIN],
            ['Enfermería', 'enfermeria@'.self::EMAIL_DOMAIN],
            ['Caja', 'caja@'.self::EMAIL_DOMAIN],
        ]);
        $this->line('Contraseña: la que pasaste con --password.');
    }

    /**
     * Ten invented patients, each built for a scene of the video. Index 0 is
     * the star: allergic to penicillin, so Elo must steer away from it.
     *
     * @return list<array<string, mixed>>
     */
    private function roster(): array
    {
        $none = ['allergies' => 'Ver alergias registradas', 'surgeries' => 'Ninguna', 'current_medication' => 'Ninguna', 'habits' => ['tobacco' => false, 'alcohol' => 'occasional']];

        return [
            [ // 0 ⭐ Elo scene
                'name' => 'María José Andrade Ruiz', 'gender' => 'female', 'age' => 34, 'civil' => 'married', 'blood' => 'A+',
                'background' => $none, 'insurance' => 'Salud S.A.',
                'allergies' => [['drug', 'Penicilina', 'severe', 'Urticaria generalizada y edema de labios en 2019.']],
                'family' => [['Hipertensión arterial', 'mother']],
                'gyneco' => ['menarche_age' => 12, 'cycle_length_days' => 28, 'cycle_duration_days' => 5, 'cycle_is_regular' => true, 'gestations' => 1, 'term_births' => 1, 'living_children' => 1, 'contraceptive_method' => 'Implante subdérmico'],
                'visits' => [[
                    'days_ago' => 120, 'reason' => 'Dolor lumbar tras levantar peso.', 'illness' => 'Dolor lumbar mecánico de 3 días, sin irradiación ni déficit neurológico.',
                    'dx' => ['M54.5', 'Lumbago no especificado'], 'plan' => 'Analgésico, calor local y pausas activas.',
                    'vitals' => [118, 76, 74, 36.6, 98, 62.0, 160], 'rx' => [['Ibuprofeno', 'Ibuprofeno', '400 mg', '1 tableta', 'cada 8 horas', 5, 15]],
                ]],
            ],
            [ // 1 chronic, rich history
                'name' => 'Jorge Cevallos Mora', 'gender' => 'male', 'age' => 58, 'civil' => 'married', 'blood' => 'O+', 'insurance' => 'Ecuasanitas',
                'background' => ['allergies' => 'Ninguna conocida', 'surgeries' => 'Apendicectomía (1998)', 'current_medication' => 'Losartán 50 mg, Metformina 850 mg', 'habits' => ['tobacco' => true, 'alcohol' => 'occasional']],
                'family' => [['Diabetes mellitus tipo 2', 'father'], ['Cardiopatía isquémica', 'paternal_grandfather']],
                'vaccines' => 1,
                'visits' => [
                    ['days_ago' => 210, 'reason' => 'Control de presión arterial.', 'illness' => 'Hipertenso desde hace 6 años, cefalea ocasional.', 'dx' => ['I10', 'Hipertensión esencial (primaria)'], 'plan' => 'Losartán 50 mg diario. Dieta baja en sal.', 'vitals' => [148, 94, 82, 36.5, 97, 86.0, 170], 'rx' => [['Losartán', 'Losartán potásico', '50 mg', '1 tableta', 'cada 24 horas', 30, 30]]],
                    ['days_ago' => 95, 'reason' => 'Sed y orina frecuente.', 'illness' => 'Glucosa en ayunas 168 mg/dL en examen de empresa.', 'dx' => ['E11.9', 'Diabetes mellitus tipo 2 sin complicaciones'], 'plan' => 'Metformina 850 mg con el almuerzo. Hemoglobina glicosilada en 3 meses.', 'vitals' => [138, 88, 80, 36.7, 97, 85.0, 170], 'rx' => [['Metformina', 'Metformina', '850 mg', '1 tableta', 'cada 24 horas', 30, 30]]],
                    ['days_ago' => 30, 'reason' => 'Control de diabetes e hipertensión.', 'illness' => 'Buena adherencia, glucosa capilar 118 mg/dL.', 'dx' => ['I10', 'Hipertensión esencial (primaria)'], 'plan' => 'Mantener tratamiento. Perfil lipídico.', 'vitals' => [132, 84, 76, 36.4, 98, 83.5, 170], 'follow_up' => 5],
                ],
            ],
            [ // 2
                'name' => 'Daniela Cordero Peña', 'gender' => 'female', 'age' => 27, 'blood' => 'B+', 'background' => $none,
                'allergies' => [['food', 'Mariscos', 'moderate', 'Ronchas y picazón.']],
                'gyneco' => ['menarche_age' => 13, 'cycle_length_days' => 30, 'cycle_duration_days' => 4, 'cycle_is_regular' => true, 'gestations' => 0, 'contraceptive_method' => 'Anticonceptivos orales'],
                'visits' => [['days_ago' => 60, 'reason' => 'Ardor al orinar.', 'illness' => 'Disuria y polaquiuria de 2 días, sin fiebre.', 'dx' => ['N39.0', 'Infección de vías urinarias, sitio no especificado'], 'plan' => 'Nitrofurantoína 100 mg por 5 días. Abundantes líquidos.', 'vitals' => [110, 70, 78, 36.8, 99, 58.0, 162], 'rx' => [['Nitrofurantoína', 'Nitrofurantoína', '100 mg', '1 cápsula', 'cada 12 horas', 5, 10]]]],
            ],
            [ // 3 pregnant — gyneco scene
                'name' => 'Ana Lucía Vera Salinas', 'gender' => 'female', 'age' => 29, 'civil' => 'married', 'blood' => 'O+', 'background' => $none,
                'pregnant_weeks' => 22,
                'gyneco' => ['menarche_age' => 12, 'cycle_length_days' => 28, 'cycle_duration_days' => 5, 'cycle_is_regular' => true, 'gestations' => 2, 'term_births' => 1, 'living_children' => 1],
                'visits' => [
                    ['days_ago' => 56, 'doctor' => 'gyneco', 'prenatal' => true, 'reason' => 'Control prenatal.', 'illness' => 'Embarazo de 14 semanas, náuseas leves, sin sangrado.', 'dx' => ['Z34.9', 'Supervisión de embarazo normal, no especificado'], 'plan' => 'Ácido fólico y hierro. Ecografía morfológica a las 20 semanas.', 'vitals' => [108, 68, 84, 36.6, 99, 61.0, 158], 'rx' => [['Ácido fólico', 'Ácido fólico', '1 mg', '1 tableta', 'cada 24 horas', 30, 30]]],
                    ['days_ago' => 14, 'doctor' => 'gyneco', 'prenatal' => true, 'reason' => 'Control prenatal.', 'illness' => 'Embarazo de 20 semanas, percibe movimientos fetales.', 'dx' => ['Z34.9', 'Supervisión de embarazo normal, no especificado'], 'plan' => 'Continuar suplementos. Curva de glucosa a las 24 semanas.', 'vitals' => [112, 70, 86, 36.7, 99, 63.5, 158], 'follow_up' => 0],
                ],
            ],
            [ // 4
                'name' => 'Gabriela Espinoza Ruiz', 'gender' => 'female', 'age' => 46, 'civil' => 'divorced', 'blood' => 'A-', 'background' => $none,
                'family' => [['Cáncer de mama', 'maternal_grandmother']],
                'gyneco' => ['menarche_age' => 11, 'cycle_length_days' => 26, 'cycle_duration_days' => 6, 'cycle_is_regular' => false, 'gestations' => 2, 'term_births' => 1, 'cesareans' => 1, 'living_children' => 2, 'last_pap_at' => Carbon::today()->subMonths(14)->toDateString(), 'last_pap_result' => 'Negativo'],
                'visits' => [['days_ago' => 75, 'doctor' => 'gyneco', 'reason' => 'Control ginecológico anual.', 'illness' => 'Ciclos irregulares en el último año, bochornos ocasionales.', 'dx' => ['N95.1', 'Estados menopáusicos y climatéricos femeninos'], 'plan' => 'Mamografía y citología. Medidas generales para bochornos.', 'vitals' => [124, 80, 72, 36.5, 98, 68.0, 163]]],
            ],
            [ // 5
                'name' => 'Pedro Guamán Quishpe', 'gender' => 'male', 'age' => 8, 'blood' => 'O+', 'contact' => 'Rosa Quishpe (madre)',
                'background' => ['allergies' => 'Ninguna conocida', 'surgeries' => 'Ninguna', 'current_medication' => 'Ninguna', 'habits' => ['tobacco' => false, 'alcohol' => 'never']],
                'vaccines' => 4,
                'visits' => [['days_ago' => 40, 'reason' => 'Tos y fiebre.', 'illness' => 'Tos seca y fiebre de 38 °C por 2 días. Buen estado general.', 'dx' => ['J06.9', 'Infección aguda de las vías respiratorias superiores, no especificada'], 'plan' => 'Paracetamol según peso. Lavados nasales.', 'vitals' => [100, 62, 96, 38.0, 97, 26.0, 128], 'rx' => [['Paracetamol jarabe', 'Paracetamol', '160 mg/5 ml', '10 ml', 'cada 6 horas si hay fiebre', 3, 1]]]],
            ],
            [ // 6
                'name' => 'Marco Tobar Jiménez', 'gender' => 'male', 'age' => 41, 'civil' => 'married', 'blood' => 'B+', 'background' => $none, 'insurance' => 'BMI Igualas Médicas',
                'allergies' => [['drug', 'Aspirina', 'moderate', 'Broncoespasmo.']],
                'visits' => [['days_ago' => 18, 'reason' => 'Crisis de asma.', 'illness' => 'Sibilancias y disnea tras exposición a polvo.', 'dx' => ['J45.9', 'Asma, no especificada'], 'plan' => 'Nebulización en consulta. Salbutamol inhalado de rescate.', 'vitals' => [126, 82, 98, 36.8, 94, 79.0, 175], 'rx' => [['Salbutamol inhalador', 'Salbutamol', '100 mcg', '2 inhalaciones', 'cada 6 horas si hay falta de aire', 7, 1]]]],
            ],
            [ // 7
                'name' => 'Sofía Cedeño Loor', 'gender' => 'female', 'age' => 23, 'blood' => 'O+', 'background' => $none,
                'gyneco' => ['menarche_age' => 13, 'cycle_length_days' => 28, 'cycle_duration_days' => 5, 'cycle_is_regular' => true, 'dysmenorrhea' => true, 'gestations' => 0],
                'visits' => [['days_ago' => 100, 'doctor' => 'gyneco', 'reason' => 'Dolor menstrual intenso.', 'illness' => 'Dismenorrea que le hace faltar a clases.', 'dx' => ['N94.6', 'Dismenorrea, no especificada'], 'plan' => 'Antiinflamatorio desde el día previo a la menstruación. Ecografía pélvica.', 'vitals' => [106, 66, 76, 36.6, 99, 54.0, 160], 'rx' => [['Naproxeno', 'Naproxeno', '550 mg', '1 tableta', 'cada 12 horas', 3, 6]]]],
            ],
            [ // 8
                'name' => 'Verónica Herrera Lima', 'gender' => 'female', 'age' => 37, 'civil' => 'married', 'blood' => 'AB+', 'background' => $none,
                'gyneco' => ['menarche_age' => 12, 'cycle_length_days' => 28, 'cycle_duration_days' => 5, 'cycle_is_regular' => true, 'gestations' => 3, 'term_births' => 2, 'abortions' => 1, 'living_children' => 2, 'contraceptive_method' => 'DIU de cobre'],
                'visits' => [['days_ago' => 150, 'doctor' => 'gyneco', 'reason' => 'Flujo vaginal anormal.', 'illness' => 'Flujo blanquecino con prurito de 1 semana.', 'dx' => ['B37.3', 'Candidiasis de la vulva y de la vagina'], 'plan' => 'Óvulos de clotrimazol por 6 noches.', 'vitals' => [114, 72, 70, 36.5, 99, 64.0, 165]]],
            ],
            [ // 9
                'name' => 'Ricardo Mero Alcívar', 'gender' => 'male', 'age' => 66, 'civil' => 'widowed', 'city' => 'Manta', 'blood' => 'O-',
                'background' => ['allergies' => 'Ver alergias registradas', 'surgeries' => 'Prostatectomía (2020)', 'current_medication' => 'Atorvastatina 20 mg', 'habits' => ['tobacco' => false, 'alcohol' => 'never']],
                'allergies' => [['drug', 'Sulfas', 'mild', 'Erupción leve.']],
                'family' => [['Hipertensión arterial', 'mother']],
                'vaccines' => 2,
                'visits' => [['days_ago' => 200, 'reason' => 'Control de colesterol.', 'illness' => 'LDL 168 mg/dL en examen previo.', 'dx' => ['E78.0', 'Hipercolesterolemia pura'], 'plan' => 'Atorvastatina 20 mg en la noche. Dieta y caminata.', 'vitals' => [130, 80, 68, 36.4, 97, 77.0, 168], 'rx' => [['Atorvastatina', 'Atorvastatina', '20 mg', '1 tableta', 'cada 24 horas en la noche', 30, 30]]]],
            ],
        ];
    }
}
