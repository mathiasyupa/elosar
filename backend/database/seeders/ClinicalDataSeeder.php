<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\FamilyBackground;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\PatientImmunization;
use App\Models\Specialty;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\Vaccine;
use App\Models\Workspace;
use App\Services\ClinicService;
use App\Services\WorkspaceEntitlementService;
use App\Support\ActiveWorkspace;
use Carbon\Carbon;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Focused demo of a real medical practice: ONE clinic ("Clínica Santa María")
 * with every membership role represented, ~20 patients split across two doctors
 * with full clinical records, family history, vaccination doses and a day queue.
 *
 * Replaces the old 100-random-doctor seed (which inflated the workspace count):
 * only the accounts a demo actually needs are created.
 *
 * Runs unguarded (no authenticated doctor during seeding) and idempotent, so
 * `migrate:fresh --seed` never duplicates. Clinical narratives are Spanish on
 * purpose (patient-facing content for the Ecuadorian market).
 *
 * Demo logins (all password: "password"):
 *   test@example.com       Dr. Roberto Castillo   médico jefe + DUEÑO de la clínica
 *   medico@clinica.com     Dra. Carla Mendoza     médico miembro (pediatría)
 *   enfermera@clinica.com  Ana Torres             enfermería
 *   recepcion@clinica.com  Sofía Ramírez          recepción
 *   caja@clinica.com       Luis Paredes           caja / facturación
 *   admin@clinica.com      Diego Herrera          encargado (admin operativo)
 *   owner@example.com Mathias Yupa          dueño del SaaS (platform_admin, aparte)
 */
class ClinicalDataSeeder extends Seeder
{
    public const CLINIC_NAME = 'Clínica Santa María';

    public function run(): void
    {
        app(ActiveWorkspace::class)->runUnguarded(fn () => $this->seed());
    }

    private function seed(): void
    {
        $faker = Factory::create('es_ES');

        $specialties = Specialty::all();
        if ($specialties->isEmpty()) {
            $this->command->warn('No specialties found. Run SpecialtySeeder first.');

            return;
        }

        // 1. DOCTORS WITH A LOGIN ------------------------------------------------
        // Director = médico jefe + clinic owner, linked to the well-known test login.
        $director = $this->makeDoctor('Dr. Roberto Castillo', 'test@example.com', 'Medicina Interna', '1710000019', $specialties, $faker);
        // Second doctor = clinic member (pediatrics — sees the child patients).
        $medico2 = $this->makeDoctor('Dra. Carla Mendoza', 'medico@clinica.com', 'Pediatría', '1710000027', $specialties, $faker);
        // Visiting specialists: a clinic bills across several specialties, and
        // the settlement report only tells its story with more than one doctor
        // splitting revenue.
        $traumatologo = $this->makeDoctor('Dr. Andrés Villacís', 'traumatologia@clinica.com', 'Traumatología', '1710000043', $specialties, $faker);
        $ginecologa = $this->makeDoctor('Dra. Lucía Andrade', 'ginecologia@clinica.com', 'Ginecología', '1710000051', $specialties, $faker);

        // SaaS owner: a doctor with a personal workspace, kept OUTSIDE the demo
        // clinic. Gets platform_admin in ShieldSeeder (runs last).
        $this->makeDoctor('Mathias Yupa', 'owner@example.com', 'Medicina General', '1710000035', $specialties, $faker);

        // 2. CLINIC + MEMBERSHIPS ----------------------------------------------
        $clinicService = app(ClinicService::class);

        $clinic = Workspace::where('type', 'clinic')->where('name', self::CLINIC_NAME)->first();
        if (! $clinic) {
            $clinic = DB::transaction(function () use ($director, $clinicService): Workspace {
                $clinic = Workspace::query()->create([
                    'name' => self::CLINIC_NAME,
                    'type' => 'clinic',
                    'invite_code' => $this->generateInviteCode(),
                    'owner_doctor_id' => $director->id,
                    'owner_user_id' => $director->user_id,
                ]);

                $clinicService->attachDoctor($clinic, $director, role: 'doctor');

                return $clinic;
            });
        }

        $clinicService->attachDoctor($clinic, $medico2, role: 'doctor');
        $clinicService->attachDoctor($clinic, $traumatologo, role: 'doctor');
        $clinicService->attachDoctor($clinic, $ginecologa, role: 'doctor');

        // Entitle the clinic once its team is in place, so the grandfathering
        // rule sizes the plan to the doctors it actually runs — two included
        // and the rest billed as additional seats. Doing it earlier would make
        // the seat check reject the very team this demo is meant to show, and
        // would need updating by hand every time a doctor is added here.
        app(WorkspaceEntitlementService::class)->provisionLegacyEntitlement($clinic);

        // Staff accounts: no Doctor record, no personal workspace — they only
        // reach this clinic. One per non-clinical role.
        // `roles` (optional) demos the multi-role model: permissions sum. Sofía is
        // the polyvalent front-desk auxiliary common in small Ecuadorian clinics —
        // she schedules, collects payments AND takes vital signs.
        $staffSeed = [
            ['name' => 'Ana Torres',    'email' => 'enfermera@clinica.com', 'role' => 'nurse',        'job_title' => 'Enfermera'],
            ['name' => 'Sofía Ramírez', 'email' => 'recepcion@clinica.com', 'role' => 'receptionist', 'job_title' => 'Recepción / auxiliar', 'roles' => ['receptionist', 'nurse']],
            ['name' => 'Luis Paredes',  'email' => 'caja@clinica.com',      'role' => 'billing',      'job_title' => 'Cajero'],
            ['name' => 'Diego Herrera', 'email' => 'admin@clinica.com',     'role' => 'admin',        'job_title' => 'Administrador'],
        ];

        $nurseUser = null;
        foreach ($staffSeed as $s) {
            $staffUser = User::updateOrCreate(
                ['email' => $s['email']],
                ['name' => $s['name'], 'password' => Hash::make('password'), 'account_type' => 'staff'],
            );
            StaffProfile::updateOrCreate(
                ['user_id' => $staffUser->id],
                ['name' => $s['name'], 'job_title' => $s['job_title'], 'phone' => '+5939'.$faker->numerify('########')],
            );
            $clinicService->attachMember($clinic, $staffUser->fresh('staffProfile'), $s['role']);

            // Extra roles (multi-role members) once the membership row exists.
            if (! empty($s['roles'])) {
                $clinic->members()->where('user_id', $staffUser->id)->first()?->update(['roles' => $s['roles']]);
            }

            if ($s['role'] === 'nurse') {
                $nurseUser = $staffUser;
            }
        }

        $this->command->info("Clinic '{$clinic->name}' seeded (invite_code: {$clinic->invite_code}).");

        // 3. PATIENTS -----------------------------------------------------------
        // 20 patients living in the clinic workspace. First 10 are "attended by"
        // the director, last 10 by Dra. Mendoza (children among them).
        $patients = $this->seedPatients($clinic, $faker);

        // 4. FAMILY HISTORY -----------------------------------------------------
        $this->seedFamilyBackground($patients, $faker);

        // 5. MEDICAL RECORDS ----------------------------------------------------
        $this->seedMedicalRecords($patients, $director, $medico2);

        // 5b. OUTREACH CASES ----------------------------------------------------
        // Lapsed patients + ordered controls nobody booked, so the two call
        // lists (Pacientes → Reactivar / Seguimiento) show real cases instead
        // of an empty state. Centralised in DemoOutreachSeeder (called from
        // DatabaseSeeder + the deploy entrypoint) so an already-seeded database
        // — like production — can be backfilled without re-running everything.

        // 6. VACCINATION DOSES --------------------------------------------------
        $this->seedImmunizations($clinic, $patients, $nurseUser);

        // 7. DAY QUEUE + AGENDA -------------------------------------------------
        $this->seedAppointments($clinic, $patients, $director, $medico2);

        $this->command->info('Demo clinic seeded: 20 patients, records, vaccines, day queue.');
    }

    /**
     * Create (or update) a doctor with a login account. The Doctor `created`
     * event auto-provisions the personal workspace.
     */
    private function makeDoctor(string $name, string $email, string $specialtyName, string $idNumber, $specialties, $faker): Doctor
    {
        return DB::transaction(function () use ($name, $email, $specialtyName, $idNumber, $specialties, $faker): Doctor {
            $spec = $specialties->firstWhere('name', $specialtyName) ?? $specialties->random();

            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'account_type' => 'doctor'],
            );

            $doctor = Doctor::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'user_id' => $user->id,
                    'specialty_id' => $spec->id,
                    'id_number' => $idNumber,
                    'city' => 'Quito',
                    'phone' => '+5939'.$faker->numerify('########'),
                ],
            )->fresh();

            if ($doctor->workspace) {
                app(WorkspaceEntitlementService::class)->provisionLegacyEntitlement($doctor->workspace);
            }

            return $doctor;
        });
    }

    private function generateInviteCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Workspace::query()->where('invite_code', $code)->exists());

        return $code;
    }

    /**
     * @return Collection<int, Patient>
     */
    private function seedPatients(Workspace $clinic, $faker)
    {
        // [name, gender, age in years (fractions = infants for vaccine demo)]
        $roster = [
            ['María Fernanda Pérez', 'female', 34],
            ['José Luis Andrade', 'male', 47],
            ['Lucía Beltrán Moreira', 'female', 29],
            ['Diego Salazar Cabrera', 'male', 52],
            ['Carla Moreno Vaca', 'female', 41],
            ['Pedro Ramírez Cedeño', 'male', 63],
            ['Sofía Cedeño Loor', 'female', 25],
            ['Andrés Vinueza Paz', 'male', 38],
            ['Gabriela Espinoza Ruiz', 'female', 56],
            ['Marco Tobar Jiménez', 'male', 44],
            ['Verónica Herrera Lima', 'female', 33],
            ['Fernando Zambrano Ortiz', 'male', 49],
            ['Daniela Cordero Peña', 'female', 27],
            ['Ricardo Mero Alcívar', 'male', 60],
            ['Mateo Villacís Andrade', 'male', 3],
            ['Emilia Guerrero Salas', 'female', 5],
            ['Benjamín Suárez Mora', 'male', 1],
            ['Isabella Ponce Villamar', 'female', 8],
            ['Martín Chávez Reyes', 'male', 6],
            ['Valentina Rueda Ochoa', 'female', 10],
        ];

        $civilStatuses = ['single', 'married', 'common_law', 'divorced', 'widowed'];
        $cities = ['Quito', 'Guayaquil', 'Cuenca', 'Ambato', 'Loja', 'Manta'];
        $bloodTypes = ['O+', 'A+', 'B+', 'O-', 'A-', 'AB+'];

        $patients = collect();
        foreach ($roster as $index => [$name, $gender, $age]) {
            $birth = Carbon::now()->subMonths((int) round($age * 12))->subDays(rand(0, 300));
            $isChild = $age < 15;
            $slug = 'p'.($index + 1);

            $patients->push(Patient::updateOrCreate(
                ['workspace_id' => $clinic->id, 'id_number' => sprintf('17%08d', 500001 + $index)],
                [
                    'name' => $name,
                    'birth_date' => $birth,
                    'gender' => $gender,
                    'civil_status' => $isChild ? 'single' : Arr::random($civilStatuses),
                    'city' => Arr::random($cities),
                    'phone' => '+5939'.$faker->numerify('########'),
                    'email' => $slug.'.demo@paciente.com',
                    'blood_type' => Arr::random($bloodTypes),
                    'emergency_contact_name' => $faker->name(),
                    'emergency_contact_phone' => '+5939'.$faker->numerify('########'),
                    'personal_background' => [
                        'allergies' => Arr::random(['Ninguna conocida', 'Penicilina', 'Polvo / ácaros', 'Mariscos']),
                        'surgeries' => Arr::random(['Ninguna', 'Apendicectomía', 'Cesárea']),
                        'current_medication' => Arr::random(['Ninguna', 'Losartán 50mg', 'Metformina 850mg']),
                        'habits' => [
                            'tobacco' => ! $isChild && (bool) rand(0, 1),
                            'alcohol' => $isChild ? 'never' : Arr::random(['never', 'occasional', 'frequent']),
                        ],
                    ],
                ],
            ));
        }

        $this->command->info('20 patients seeded (14 adults + 6 children).');

        return $patients;
    }

    private function seedFamilyBackground($patients, $faker): void
    {
        $familyDiseases = [
            ['disease' => 'Diabetes mellitus tipo 2', 'relative' => 'father'],
            ['disease' => 'Hipertensión arterial', 'relative' => 'mother'],
            ['disease' => 'Cáncer de mama', 'relative' => 'maternal_grandmother'],
            ['disease' => 'Cardiopatía isquémica', 'relative' => 'paternal_grandfather'],
            ['disease' => 'Asma bronquial', 'relative' => 'brother'],
        ];

        foreach ($patients as $patient) {
            if ($patient->familyBackground()->exists()) {
                continue;
            }

            foreach (Arr::random($familyDiseases, rand(1, 2)) as $fam) {
                FamilyBackground::create([
                    'patient_id' => $patient->id,
                    'disease' => $fam['disease'],
                    'relative_type' => $fam['relative'],
                    'notes' => $faker->boolean(40) ? $faker->sentence(8) : null,
                ]);
            }
        }
    }

    private function seedMedicalRecords($patients, Doctor $director, Doctor $medico2): void
    {
        $cases = $this->clinicalCases();

        foreach ($patients->values() as $index => $patient) {
            if ($patient->medicalRecords()->exists()) {
                continue;
            }

            // First 10 patients -> director; last 10 -> Dra. Mendoza.
            $doctor = $index < 10 ? $director : $medico2;
            $case = $cases[$index % count($cases)];

            MedicalRecord::create([
                'workspace_id' => $patient->workspace_id,
                'patient_id' => $patient->id,
                'created_by' => $doctor->id,
                'status' => 'completed',
                'reason_for_consultation' => $case['reason'],
                'current_illness' => $case['illness'],
                'diagnosis' => $case['diagnosis'],
                'treatment' => $case['treatment'],
                'created_at' => Carbon::now()->subDays(rand(1, 45)),
            ]);
        }

        $this->command->info('20 medical records seeded (10 per doctor).');
    }

    /**
     * Patients who stopped coming, for the re-engage call list.
     *
     * The list ranks by what a patient has left at the practice, NOT by how
     * stale they are, so the demo is built to prove exactly that: Valentina is
     * the most lapsed (22 months) and lands last, while Marco — barely 8 months
     * away but a $305 regular — leads. Money is seeded in DemoDataSeeder.
     *
     * Offsets are in DAYS on purpose: subMonths()/subYears() preserve the day
     * of the month and would fire a bogus "patient anniversary" on every run.
     */
    public function seedLapsedHistories($patients, Doctor $director, Doctor $medico2): void
    {
        // roster index => visit dates, most recent first
        $histories = [
            9 => [243, 395, 578, 791, 1034],  // Marco Tobar    — 5 visits
            8 => [426, 639, 913],             // Gabriela Espinoza — 3 visits
            18 => [213, 395],                  // Martín Chávez  — 2 visits
            19 => [670],                       // Valentina Rueda — 1 visit
        ];

        $cases = $this->clinicalCases();
        $seeded = 0;

        foreach ($histories as $index => $daysAgo) {
            $patient = $patients->values()->get($index);

            if ($patient === null) {
                continue;
            }

            // Already backdated on a previous run.
            if ($patient->medicalRecords()->where('created_at', '<', Carbon::now()->subMonths(6))->exists()) {
                continue;
            }

            $doctor = $index < 10 ? $director : $medico2;

            // Push the record seedMedicalRecords() already made back in time,
            // then add the older visits, so this heals an existing database
            // instead of only working on a fresh one.
            $latest = $patient->medicalRecords()->orderByDesc('created_at')->first();
            if ($latest !== null) {
                $latest->forceFill(['created_at' => Carbon::now()->subDays($daysAgo[0])])->saveQuietly();
            }

            foreach (array_slice($daysAgo, $latest !== null ? 1 : 0) as $offset) {
                // Rotate through the case list so a patient's history reads as
                // different visits, not the same consultation five times.
                $case = $cases[($index + $offset) % count($cases)];

                MedicalRecord::create([
                    'workspace_id' => $patient->workspace_id,
                    'patient_id' => $patient->id,
                    'created_by' => $doctor->id,
                    'status' => 'completed',
                    'reason_for_consultation' => $case['reason'],
                    'current_illness' => $case['illness'],
                    'diagnosis' => $case['diagnosis'],
                    'treatment' => $case['treatment'],
                    'created_at' => Carbon::now()->subDays($offset),
                ]);
            }

            $seeded++;
        }

        if ($seeded > 0) {
            $this->command->info("{$seeded} lapsed patient histories seeded (re-engage list).");
        }
    }

    /**
     * Controls the doctor ordered that never became an appointment, for the
     * follow-up call list.
     *
     * Every patient here must satisfy the endpoint's four rules: no upcoming
     * appointment, no consultation after the control was due, one follow-up
     * each, and a date inside [today-1y, today+30d].
     *
     * Only indices 7, 8, 9, 18 and 19 can ever qualify: seedAppointments()
     * puts 0-7 on today's queue (which counts as booked) and 10-17 on the
     * upcoming agenda. Index 7 is the exception — its appointment is today
     * with status no_show, which the query excludes.
     */
    public function seedPendingFollowUps($patients): void
    {
        // roster index => [days from today (negative = overdue), reason]
        $followUps = [
            8 => [-330, 'Control de hipertensión: repetir perfil lipídico y EKG de seguimiento.'],
            9 => [-210, 'Control en 1 mes: presión arterial y adherencia a atorvastatina.'],
            18 => [-21,  'Control en 6 meses para reevaluar los cuadros de broncoespasmo a repetición.'],
            7 => [24,   'Control de lesiones en manos en 3 semanas; evaluar dermatitis de contacto ocupacional.'],
        ];

        $seeded = 0;

        foreach ($followUps as $index => [$days, $reason]) {
            $patient = $patients->values()->get($index);

            if ($patient === null || $patient->medicalRecords()->whereNotNull('follow_up_date')->exists()) {
                continue;
            }

            $record = $patient->medicalRecords()->orderByDesc('created_at')->first();

            if ($record === null) {
                continue;
            }

            $record->forceFill([
                'follow_up_date' => Carbon::today()->addDays($days),
                'follow_up_notes' => $reason,
            ])->saveQuietly();

            $seeded++;
        }

        if ($seeded > 0) {
            $this->command->info("{$seeded} pending follow-ups seeded (control call list).");
        }
    }

    /**
     * Administered vaccine doses for the children plus a few adults, so the
     * vaccination card screen is populated. Recorded by the clinic nurse.
     */
    private function seedImmunizations(Workspace $clinic, $patients, ?User $nurseUser): void
    {
        $vaccines = Vaccine::where('is_active', true)->orderBy('sort_order')->get();
        if ($vaccines->isEmpty()) {
            $this->command->warn('No vaccines in catalog; skipping immunizations.');

            return;
        }

        // Children (index >= 14) get a small PAI history; two adults get boosters.
        $targets = $patients->values()->filter(fn ($p, $i) => $i >= 14 || in_array($i, [0, 5], true));

        foreach ($targets as $patient) {
            if (PatientImmunization::where('patient_id', $patient->id)->exists()) {
                continue;
            }

            foreach ($vaccines->random(min(4, $vaccines->count())) as $vaccine) {
                $doses = max(1, min($vaccine->max_doses ?: 1, rand(1, 3)));

                for ($d = 1; $d <= $doses; $d++) {
                    PatientImmunization::create([
                        'workspace_id' => $clinic->id,
                        'patient_id' => $patient->id,
                        'vaccine_id' => $vaccine->id,
                        'vaccine_name' => $vaccine->name,
                        'dose_label' => $doses === 1 ? 'single' : 'dose_'.$d,
                        'administered_at' => Carbon::now()->subMonths(rand(1, 24))->toDateString(),
                        'lot' => 'LOT-'.strtoupper(bin2hex(random_bytes(3))),
                        'administered_by_user_id' => $nurseUser?->id,
                    ]);
                }
            }
        }

        $this->command->info('Vaccination doses seeded for children + a few adults.');
    }

    /**
     * A today day-queue across pipeline stages (for the operations dashboard /
     * "cola") plus a few upcoming scheduled appointments (for the agenda).
     */
    private function seedAppointments(Workspace $clinic, $patients, Doctor $director, Doctor $medico2): void
    {
        if (Appointment::where('workspace_id', $clinic->id)->exists()) {
            return;
        }

        $queue = [
            ['status' => 'completed',   'h' => 8],
            ['status' => 'with_doctor', 'h' => 9],
            ['status' => 'vitals_done', 'h' => 9],
            ['status' => 'vitals_done', 'h' => 10],
            ['status' => 'checked_in',  'h' => 10],
            ['status' => 'checked_in',  'h' => 11],
            ['status' => 'scheduled',   'h' => 12],
            ['status' => 'no_show',     'h' => 8],
        ];

        foreach ($queue as $i => $q) {
            $patient = $patients[$i];
            $checkedIn = in_array($q['status'], ['checked_in', 'vitals_done', 'with_doctor', 'completed'], true);
            $when = Carbon::today()->setHour($q['h'])->setMinute(0);

            Appointment::create([
                'workspace_id' => $clinic->id,
                'patient_id' => $patient->id,
                'doctor_id' => ($i % 2 === 0 ? $director : $medico2)->id,
                'appointment_date' => $when,
                'status' => $q['status'],
                'checked_in_at' => $checkedIn ? $when : null,
                'type' => 'consultation',
                'channel' => 'in_person',
            ]);
        }

        // Upcoming agenda: next 8 business-ish slots.
        for ($i = 0; $i < 8; $i++) {
            $patient = $patients[10 + $i];
            Appointment::create([
                'workspace_id' => $clinic->id,
                'patient_id' => $patient->id,
                'doctor_id' => ($i % 2 === 0 ? $director : $medico2)->id,
                'appointment_date' => Carbon::now()->addDays(rand(1, 14))->setHour(rand(8, 16))->setMinute(Arr::random([0, 30])),
                'status' => 'scheduled',
                'type' => 'consultation',
                'channel' => 'in_person',
            ]);
        }

        $this->command->info('Day queue (8 across stages) + upcoming agenda (8) seeded.');
    }

    /**
     * Realistic Ecuadorian clinical cases (Spanish, market-facing content).
     *
     * @return list<array{reason:string,illness:string,diagnosis:string,treatment:string}>
     */
    private function clinicalCases(): array
    {
        return [
            [
                'reason' => 'Dolor opresivo en el pecho irradiado al brazo izquierdo tras esfuerzo físico moderado.',
                'illness' => 'El dolor inició hace 2 semanas, dura aproximadamente 5 minutos y cede con el reposo.',
                'diagnosis' => 'Angina de pecho estable (CIE-10: I20.8). Sospecha de cardiopatía isquémica.',
                'treatment' => 'Aspirina 100mg VO diario, Atorvastatina 40mg VO noche. Electrocardiograma de esfuerzo y derivación a cardiología.',
            ],
            [
                'reason' => 'Cefalea intensa y pulsátil unilateral, con fotofobia y náuseas desde hace 24 horas.',
                'illness' => 'Cuadro recurrente, 2 veces al mes, desencadenado por estrés laboral y falta de sueño.',
                'diagnosis' => 'Migraña común sin aura (CIE-10: G43.0).',
                'treatment' => 'Sumatriptán 50mg VO al inicio del dolor. Paracetamol 1g VO cada 8 horas si es leve. Reposo en habitación oscura.',
            ],
            [
                'reason' => 'Control de embarazo de rutina. Gestación de 24 semanas.',
                'illness' => 'Buena tolerancia alimentaria, percibe movimientos fetales. Sin sangrado ni pérdida de líquido.',
                'diagnosis' => 'Supervisión de embarazo normal de bajo riesgo (CIE-10: Z34.9).',
                'treatment' => 'Ácido fólico y sulfato ferroso 1 tableta diaria VO. Examen general de orina y curva de tolerancia a la glucosa.',
            ],
            [
                'reason' => 'Tos productiva con esputo verdoso, dificultad respiratoria y fiebre de 38.5°C.',
                'illness' => 'Cuadro de 5 días. Estertores crepitantes en base pulmonar derecha a la auscultación.',
                'diagnosis' => 'Neumonía adquirida en la comunidad (CIE-10: J18.9).',
                'treatment' => 'Amoxicilina + Ácido Clavulánico 875/125mg VO cada 12 horas por 7 días. Hidratación y paracetamol para la fiebre.',
            ],
            [
                'reason' => 'Fatiga extrema, sed excesiva y aumento de la frecuencia urinaria.',
                'illness' => 'Pérdida de 5kg en el último mes pese a apetito aumentado. Glucemia en ayunas: 240 mg/dL.',
                'diagnosis' => 'Diabetes mellitus tipo 2 (CIE-10: E11.9).',
                'treatment' => 'Metformina 850mg VO dos veces al día. Plan nutricional bajo en carbohidratos y actividad física. Solicitar HbA1c.',
            ],
            [
                'reason' => 'Fiebre de 39°C, irritabilidad y rechazo a la alimentación en niño de corta edad.',
                'illness' => 'Cuadro de 2 días. Faringe congestiva, sin dificultad respiratoria. Buen estado de hidratación.',
                'diagnosis' => 'Faringoamigdalitis aguda (CIE-10: J02.9).',
                'treatment' => 'Paracetamol 15mg/kg/dosis cada 6 horas. Abundantes líquidos. Control si persiste la fiebre por más de 72 horas.',
            ],
            [
                'reason' => 'Control de niño sano. Evaluación de crecimiento y desarrollo.',
                'illness' => 'Niño con adecuada ganancia de peso y talla. Esquema de vacunación en revisión.',
                'diagnosis' => 'Supervisión de salud del niño (CIE-10: Z00.1). Desarrollo acorde a la edad.',
                'treatment' => 'Continuar lactancia/alimentación complementaria según edad. Completar esquema PAI. Próximo control en 2 meses.',
            ],
            [
                'reason' => 'Prurito y lesiones eritematosas descamativas en ambas manos desde hace 3 días.',
                'illness' => 'Manipuló detergentes industriales sin guantes de protección en su trabajo.',
                'diagnosis' => 'Dermatitis de contacto alérgica (CIE-10: L23.9).',
                'treatment' => 'Hidrocortisona 1% cada 12 horas por 5 días. Cetirizina 10mg VO en la noche por 7 días. Evitar el alérgeno.',
            ],
            [
                'reason' => 'Dolor y edema en tobillo derecho tras inversión forzada en práctica deportiva.',
                'illness' => 'Incapacidad parcial para la marcha. Edema y dolor a la palpación del ligamento peroneoastragalino.',
                'diagnosis' => 'Esguince de tobillo derecho grado II (CIE-10: S93.4).',
                'treatment' => 'Protocolo RICE (reposo, hielo, vendaje compresivo, elevación). Ibuprofeno 400mg VO cada 8 horas por 5 días.',
            ],
            [
                'reason' => 'Preocupación constante, opresión torácica y dificultad para conciliar el sueño.',
                'illness' => 'Síntomas de 6 meses que interfieren con el desempeño laboral y familiar.',
                'diagnosis' => 'Trastorno de ansiedad generalizada (CIE-10: F41.1).',
                'treatment' => 'Derivar a psicoterapia cognitivo-conductual. Sertralina 50mg VO en la mañana. Control en 2 semanas.',
            ],
        ];
    }
}
