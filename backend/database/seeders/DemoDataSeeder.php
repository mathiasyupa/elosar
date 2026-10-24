<?php

namespace Database\Seeders;

use App\Models\Cie10Code;
use App\Models\Doctor;
use App\Models\FinanceEntry;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\RecordDiagnosis;
use App\Models\RecordPrescription;
use App\Models\RecordVital;
use App\Models\Workspace;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\ActiveWorkspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Demo data that exercises EVERYTHING built on top of the base clinical seed:
 * structured vitals / diagnoses / prescriptions, patient allergies, a billing
 * fee schedule, invoices (mixed states) + payments via the domain services,
 * an automatic credit note, and the workspace/buyer fiscal data needed to run
 * the SRI diagnostic flow (`sri:check`).
 *
 * Runs AFTER ClinicalDataSeeder, against Dr. House's personal workspace (the
 * one the test user lands on at login). Unguarded (no authenticated doctor
 * during seeding) and idempotent: each section is guarded so re-running
 * migrate:fresh --seed (or just db:seed) never duplicates data.
 *
 * Demo medication / allergy / diagnosis text is Spanish on purpose
 * (patient-facing clinical content for the Ecuadorian market).
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Note stamped on the re-engage list's historic invoices. Doubles as a
     * per-patient idempotency marker (see seedOutreachBilling): a fixed string
     * that reads reasonably on a demo invoice and is safe to match on exactly.
     */
    private const OUTREACH_INVOICE_NOTE = 'Atención de control (histórico).';

    /** Same idea for the day-to-day flow: marker + a note that reads naturally. */
    private const DAILY_FLOW_NOTE = 'Atención del día.';

    /** How far back the day-to-day billing flow goes. */
    private const DAILY_FLOW_DAYS = 90;

    public function run(): void
    {
        app(ActiveWorkspace::class)->runUnguarded(fn () => $this->seed());
    }

    private function seed(): void
    {
        $workspace = Workspace::where('type', 'clinic')
            ->where('name', ClinicalDataSeeder::CLINIC_NAME)
            ->first();

        if (! $workspace) {
            $this->command->warn('DemoDataSeeder skipped: demo clinic not found. Run ClinicalDataSeeder first.');
            return;
        }

        // Billing is attributed to the clinic owner (médico jefe).
        $doctor = Doctor::find($workspace->owner_doctor_id);

        if (! $doctor) {
            $this->command->warn('DemoDataSeeder skipped: demo clinic has no owner doctor.');
            return;
        }

        $patients = Patient::where('workspace_id', $workspace->id)->orderBy('id')->get();
        $records  = MedicalRecord::where('workspace_id', $workspace->id)->orderBy('id')->get();

        if ($patients->isEmpty()) {
            $this->command->warn('DemoDataSeeder skipped: no patients in the demo workspace.');
            return;
        }

        $this->seedAllergies($patients);
        $this->seedInsurance($patients);
        $this->seedClinicalDetails($records);
        $services = $this->seedFeeSchedule($workspace);
        $this->seedFiscalData($workspace);
        $this->seedBillingFlow($workspace, $doctor, $patients, $services);
        // Outreach billing (the money that ranks the re-engage list) is run by
        // DemoOutreachSeeder so production can be backfilled standalone.

        // The money the Reportes screen is made of: a real daily rhythm of
        // attentions, the revenue split with each specialist, and the clinic's
        // running costs. Without them the report is a wall of $0.
        $this->seedCommissions($workspace);
        $this->seedDailyFlow($workspace, $patients, $services);
        $this->seedFinanceEntries($workspace);

        $this->command->info('DemoDataSeeder finished: allergies, vitals/diagnoses/prescriptions, fee schedule, invoices, payments, credit note, daily billing flow, commissions, expenses and fiscal data.');
    }

    /**
     * Add structured allergies to ~60% of patients (idempotent per patient).
     */
    private function seedAllergies($patients): void
    {
        foreach ($patients as $patient) {
            if ($patient->allergies()->exists()) {
                continue;
            }

            if (! fake()->boolean(60)) {
                continue;
            }

            PatientAllergy::factory()
                ->count(rand(1, 2))
                ->state(['patient_id' => $patient->id])
                ->create();
        }
    }

    /**
     * Assign private insurance to roughly half the patients (idempotent: only
     * fills patients without a provider yet) so the "Seguros" screen has data.
     */
    private function seedInsurance($patients): void
    {
        $providers = ['Salud S.A.', 'Ecuasanitas', 'BMI Igualas Médicas', 'Humana', 'MediKen'];

        foreach ($patients as $index => $patient) {
            if ($patient->insurance_provider) {
                continue;
            }

            // Deterministic ~50% coverage based on row order.
            if ($index % 2 !== 0) {
                continue;
            }

            $patient->update([
                'insurance_provider'      => $providers[$index % count($providers)],
                'insurance_policy_number' => 'POL-' . str_pad((string) (1000 + $patient->id), 6, '0', STR_PAD_LEFT),
            ]);
        }
    }

    /**
     * For each existing medical record: vital signs, structured ICD-10 diagnoses
     * (1 primary + 0-2 secondary, real catalog codes) and 1-3 prescriptions.
     */
    private function seedClinicalDetails($records): void
    {
        $catalogCodes = Cie10Code::inRandomOrder()->limit(60)->get();

        foreach ($records as $record) {
            if ($record->vitals()->doesntExist()) {
                RecordVital::factory()
                    ->state([
                        'medical_record_id' => $record->id,
                        'recorded_at'       => $record->created_at ?? now(),
                    ])
                    ->create();
            }

            if ($record->diagnoses()->doesntExist()) {
                $this->seedDiagnoses($record, $catalogCodes);
            }

            if ($record->prescriptions()->doesntExist()) {
                RecordPrescription::factory()
                    ->count(rand(1, 3))
                    ->state(['medical_record_id' => $record->id])
                    ->create();
            }
        }
    }

    private function seedDiagnoses(MedicalRecord $record, $catalogCodes): void
    {
        $picked = $catalogCodes->isNotEmpty()
            ? $catalogCodes->random(min(rand(1, 3), $catalogCodes->count()))
            : collect();

        if ($picked->isEmpty()) {
            // Fallback when the CIE-10 catalog was not seeded.
            RecordDiagnosis::factory()->primary()->state(['medical_record_id' => $record->id])->create();
            return;
        }

        foreach ($picked->values() as $index => $code) {
            RecordDiagnosis::factory()
                ->state([
                    'medical_record_id' => $record->id,
                    'cie10_code'        => $code->code,
                    'cie10_description' => $code->description,
                    'type'              => $index === 0 ? 'primary' : 'secondary',
                ])
                ->create();
        }
    }

    /**
     * Billing fee schedule for the workspace, across all categories.
     *
     * @return \Illuminate\Support\Collection<int, MedicalService>
     */
    private function seedFeeSchedule(Workspace $workspace)
    {
        $catalog = [
            ['name' => 'Consulta médica general',        'category' => 'general',    'base_price' => '30.00',  'iva_rate' => '0.00'],
            ['name' => 'Consulta de especialidad',       'category' => 'general',    'base_price' => '45.00',  'iva_rate' => '0.00'],
            ['name' => 'Control / seguimiento',          'category' => 'general',    'base_price' => '20.00',  'iva_rate' => '0.00'],
            ['name' => 'Biometría hemática completa',     'category' => 'laboratory', 'base_price' => '12.00',  'iva_rate' => '0.00'],
            ['name' => 'Perfil lipídico',                'category' => 'laboratory', 'base_price' => '18.00',  'iva_rate' => '0.00'],
            ['name' => 'Examen general de orina',        'category' => 'laboratory', 'base_price' => '8.00',   'iva_rate' => '0.00'],
            ['name' => 'Radiografía de tórax',           'category' => 'imaging',    'base_price' => '35.00',  'iva_rate' => '0.00'],
            ['name' => 'Ecografía abdominal',            'category' => 'imaging',    'base_price' => '50.00',  'iva_rate' => '0.00'],
            ['name' => 'Electrocardiograma',             'category' => 'procedure',  'base_price' => '25.00',  'iva_rate' => '0.00'],
            ['name' => 'Curación de heridas',            'category' => 'procedure',  'base_price' => '15.00',  'iva_rate' => '0.00'],
            ['name' => 'Nebulización',                   'category' => 'procedure',  'base_price' => '10.00',  'iva_rate' => '0.00'],
        ];

        $services = collect();

        foreach ($catalog as $index => $item) {
            $services->push(MedicalService::updateOrCreate(
                ['workspace_id' => $workspace->id, 'name' => $item['name']],
                [
                    'category'   => $item['category'],
                    'base_price' => $item['base_price'],
                    'iva_rate'   => $item['iva_rate'],
                    'is_active'  => true,
                    'sort_order' => $index + 1,
                ]
            ));
        }

        return $services;
    }

    /**
     * Fill the issuer's fiscal / SRI fields so `sri:check {ws}` can run the
     * step-by-step diagnostic. SRI stays DISABLED (no real .p12 certificate),
     * so no authorization job is dispatched during seeding.
     */
    private function seedFiscalData(Workspace $workspace): void
    {
        $workspace->update([
            'ruc'                    => $this->validRuc(),
            'razon_social'           => 'CLINICA SANTA MARIA CIA. LTDA.',
            'nombre_comercial'       => $workspace->name,
            'dir_matriz'             => 'Av. Amazonas N34-451 y Av. Atahualpa, Quito',
            'dir_establecimiento'    => 'Av. Amazonas N34-451 y Av. Atahualpa, Quito',
            'cod_establecimiento'    => '001',
            'pto_emision'            => '001',
            'obligado_contabilidad'  => false,
            'sri_environment'        => 'test',
            'sri_enabled'            => false,
        ]);
    }

    /**
     * Invoices via InvoiceService (NEVER built by hand), payments via
     * PaymentService, and a credit note via the automatic void flow.
     * Guarded: only runs when the workspace has no invoices yet.
     */
    private function seedBillingFlow(Workspace $workspace, Doctor $doctor, $patients, $services): void
    {
        if (Invoice::where('workspace_id', $workspace->id)->exists()) {
            $this->command->info('Billing demo already present, skipping invoices/payments/credit note.');
            return;
        }

        $invoiceService = app(InvoiceService::class);
        $paymentService = app(PaymentService::class);

        // state => count, processed in order. Total: 9 invoices.
        $plan = array_merge(
            array_fill(0, 3, 'paid'),
            array_fill(0, 2, 'partial'),
            array_fill(0, 3, 'issued'),
            ['draft'],
        );

        $patientList = $patients->values();
        $paidInvoices = [];

        foreach ($plan as $i => $state) {
            $patient = $patientList[$i % $patientList->count()];

            $issuedAt = Carbon::now()->subDays(rand(5, 60));

            $invoice = $invoiceService->create([
                'workspace_id'  => $workspace->id,
                'patient_id'    => $patient->id,
                'created_by'    => $doctor->id,
                'issued_at'     => $issuedAt->toDateString(),
                'items'         => $this->buildItems($services),
                'notes'         => fake()->optional(0.3)->sentence(),
                'buyer_id_type' => '05',
                'buyer_id'      => $this->validCedula(),
                'buyer_name'    => $patient->name,
                'buyer_address' => $patient->city ?? 'Quito',
                'buyer_email'   => $patient->email,
            ]);

            $this->applyState($invoice, $state, $invoiceService, $paymentService);

            if ($state === 'paid') {
                $paidInvoices[] = $invoice;
            }
        }

        // Credit note: void a paid invoice — InvoiceService issues the NC
        // automatically because amount_paid > 0.
        if (! empty($paidInvoices)) {
            $invoiceService->void($paidInvoices[0]->fresh(), 'Anulación por solicitud del paciente (demo).');
        }

        $this->command->info('Seeded ' . count($plan) . ' invoices with mixed states + payments + 1 credit note.');
    }

    /**
     * Build 1-3 invoice line items from the fee schedule.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Billing history for the lapsed patients of the re-engage call list
     * (ClinicalDataSeeder::seedLapsedHistories).
     *
     * That list is ranked by lifetime collected, so these amounts ARE the
     * demo: $305 > $147.50 > $50 > $0 puts the 8-month regular above the
     * 22-month one-visit patient. Items are fixed on purpose — buildItems()
     * randomises quantity and discount, which can flip the ranking between
     * runs and silently break the very thing the screen exists to show.
     *
     * Gabriela is deliberately part-paid: her row proves the column reports
     * what was COLLECTED ($147.50), not what was invoiced ($200).
     */
    public function seedOutreachBilling(Workspace $workspace, Doctor $doctor, $patients, $services): void
    {
        $patientList = $patients->values();

        // roster index => [[days ago, state, [service name => qty], ...], ...]
        $plan = [
            9 => [
                [243, 'paid', ['Consulta de especialidad' => 1, 'Electrocardiograma' => 1]],            // 70
                [395, 'paid', ['Consulta de especialidad' => 1, 'Perfil lipídico' => 1, 'Biometría hemática completa' => 1]], // 75
                [578, 'paid', ['Ecografía abdominal' => 1, 'Consulta médica general' => 1]],            // 80
                [791, 'paid', ['Radiografía de tórax' => 1, 'Consulta de especialidad' => 1]],          // 80  => 305
            ],
            8 => [
                [426, 'paid',    ['Consulta de especialidad' => 1, 'Ecografía abdominal' => 1]],        // 95
                [639, 'partial', ['Consulta de especialidad' => 1, 'Electrocardiograma' => 1, 'Radiografía de tórax' => 1]], // 105 -> 52.50
            ],
            18 => [
                [213, 'paid', ['Consulta médica general' => 1, 'Nebulización' => 2]],                   // 50
            ],
            // Valentina (19) stays at $0 on purpose: the most lapsed patient
            // is also the least valuable, which is what the ordering must show.
        ];

        $invoiceService = app(InvoiceService::class);
        $paymentService = app(PaymentService::class);
        $byName = $services->keyBy('name');
        $created = 0;

        foreach ($plan as $index => $invoices) {
            $patient = $patientList->get($index);

            if ($patient === null) {
                continue;
            }

            // Per-patient idempotency via a stable marker on the note. Using a
            // single patient as a global sentinel was fragile: in production
            // that patient already had an unrelated invoice, which silently
            // skipped ALL outreach billing and left the whole list at $0.
            $alreadySeeded = Invoice::where('workspace_id', $workspace->id)
                ->where('patient_id', $patient->id)
                ->where('notes', self::OUTREACH_INVOICE_NOTE)
                ->exists();

            if ($alreadySeeded) {
                continue;
            }

            foreach ($invoices as [$daysAgo, $state, $lines]) {
                $items = [];
                foreach (array_values(array_keys($lines)) as $sortOrder => $serviceName) {
                    $service = $byName->get($serviceName);

                    if ($service === null) {
                        continue;
                    }

                    $items[] = [
                        'medical_service_id'  => $service->id,
                        'description'         => $service->name,
                        'quantity'            => (string) $lines[$serviceName],
                        'unit_price'          => (string) $service->base_price,
                        'iva_rate'            => (string) $service->iva_rate,
                        'discount_percentage' => '0',
                        'sort_order'          => $sortOrder,
                    ];
                }

                if ($items === []) {
                    continue;
                }

                $issuedAt = Carbon::now()->subDays($daysAgo);

                $invoice = $invoiceService->create([
                    'workspace_id'  => $workspace->id,
                    'patient_id'    => $patient->id,
                    'created_by'    => $doctor->id,
                    'issued_at'     => $issuedAt->toDateString(),
                    'items'         => $items,
                    'notes'         => self::OUTREACH_INVOICE_NOTE,
                    'buyer_id_type' => '05',
                    'buyer_id'      => $this->validCedula(),
                    'buyer_name'    => $patient->name,
                    'buyer_address' => $patient->city ?? 'Quito',
                    'buyer_email'   => $patient->email,
                ]);

                $this->applyState($invoice, $state, $invoiceService, $paymentService);
                $created++;
            }
        }

        if ($created > 0) {
            $this->command->info("{$created} historic invoices seeded for the re-engage list.");
        }
    }

    private function buildItems($services): array
    {
        $picked = $services->random(min(rand(1, 3), $services->count()));
        $picked = $picked instanceof MedicalService ? collect([$picked]) : $picked;

        $items = [];
        foreach ($picked->values() as $index => $service) {
            $items[] = [
                'medical_service_id'  => $service->id,
                'description'         => $service->name,
                'quantity'            => (string) rand(1, 2),
                'unit_price'          => (string) $service->base_price,
                'iva_rate'            => (string) $service->iva_rate,
                'discount_percentage' => Arr::random(['0', '0', '5', '10']),
                'sort_order'          => $index,
            ];
        }

        return $items;
    }

    /**
     * Drive each invoice to the requested demo state through the services.
     */
    private function applyState(
        Invoice $invoice,
        string $state,
        InvoiceService $invoiceService,
        PaymentService $paymentService,
    ): void {
        switch ($state) {
            case 'paid':
                $paymentService->register($invoice, [
                    'amount'         => (string) $invoice->amount_due,
                    'payment_method' => Arr::random(['cash', 'transfer', 'card']),
                    'payment_date'   => $invoice->issued_at->toDateString(),
                    'registered_by'  => $invoice->created_by,
                ]);
                break;

            case 'partial':
                $half = bcdiv((string) $invoice->total, '2', 2);
                $paymentService->register($invoice, [
                    'amount'         => $half,
                    'payment_method' => Arr::random(['cash', 'transfer', 'card']),
                    'payment_date'   => $invoice->issued_at->toDateString(),
                    'registered_by'  => $invoice->created_by,
                ]);
                break;

            case 'draft':
                // Service always issues; demo a draft by reverting the status.
                Invoice::withoutEvents(fn () => $invoice->update(['status' => 'draft']));
                break;

            case 'issued':
            default:
                // Left as issued by the service.
                break;
        }
    }

    /**
     * The revenue share agreed with each specialist. The owner-doctor keeps
     * everything he bills (no split), which is also the case the report has to
     * show correctly: "sin comisión" is not the same as 0%.
     */
    private function seedCommissions(Workspace $workspace): void
    {
        $percentages = [
            'Dra. Carla Mendoza' => '60.00',
            'Dr. Andrés Villacís' => '65.00',
            'Dra. Lucía Andrade' => '70.00',
        ];

        foreach ($workspace->members()->whereNotNull('doctor_id')->with('doctor')->get() as $member) {
            $pct = $percentages[$member->doctor?->name] ?? null;

            if ($pct !== null && $member->commission_pct === null) {
                $member->update(['commission_pct' => $pct]);
            }
        }
    }

    /**
     * A clinic's real rhythm: a handful of attentions every working day for the
     * last three months, spread across the specialists, mostly collected on the
     * spot and a few left half paid.
     *
     * This is what makes "Día a día" and "Liquidación" readable — nine invoices
     * scattered over two months draw an empty chart. Idempotent through the note
     * stamped on every invoice it creates.
     */
    private function seedDailyFlow(Workspace $workspace, $patients, $services): void
    {
        if (Invoice::where('workspace_id', $workspace->id)->where('notes', self::DAILY_FLOW_NOTE)->exists()) {
            $this->command->info('Daily billing flow already present, skipping.');

            return;
        }

        $doctors = $workspace->members()
            ->whereNotNull('doctor_id')
            ->with('doctor')
            ->get()
            ->pluck('doctor')
            ->filter()
            ->values();

        if ($doctors->isEmpty() || $services->isEmpty()) {
            return;
        }

        $invoiceService = app(InvoiceService::class);
        $paymentService = app(PaymentService::class);
        $patientList = $patients->values();
        $created = 0;

        for ($daysAgo = self::DAILY_FLOW_DAYS; $daysAgo >= 0; $daysAgo--) {
            $day = Carbon::today()->subDays($daysAgo);

            // Sundays are closed and Saturdays are half a day.
            if ($day->isSunday()) {
                continue;
            }

            $attentions = $day->isSaturday() ? rand(1, 3) : rand(3, 7);

            for ($i = 0; $i < $attentions; $i++) {
                $doctor = $doctors[rand(0, $doctors->count() - 1)];
                $patient = $patientList[rand(0, $patientList->count() - 1)];

                // A walk-in nobody attributed: the "Sin asignar" row of the
                // settlement is a real case, not a bug.
                $attending = rand(1, 10) === 1 ? null : $doctor;

                $invoice = $invoiceService->create([
                    'workspace_id' => $workspace->id,
                    'patient_id' => $patient->id,
                    'created_by' => $doctor->id,
                    'attending_doctor_id' => $attending?->id,
                    'issued_at' => $day->toDateString(),
                    'items' => $this->buildItems($services),
                    'notes' => self::DAILY_FLOW_NOTE,
                    'buyer_id_type' => '05',
                    'buyer_id' => $this->validCedula(),
                    'buyer_name' => $patient->name,
                    'buyer_address' => $patient->city ?? 'Quito',
                    'buyer_email' => $patient->email,
                ]);

                // Cash practice: most attentions are collected the same day.
                $roll = rand(1, 100);
                $state = $roll <= 85 ? 'paid' : ($roll <= 95 ? 'partial' : 'issued');

                $this->applyState($invoice, $state, $invoiceService, $paymentService);
                $created++;
            }
        }

        $this->command->info("Seeded {$created} day-to-day attentions over the last ".self::DAILY_FLOW_DAYS.' days.');
    }

    /**
     * What the clinic spends and earns outside the invoicing flow: rent and
     * utilities every month, supplies every week, the consulting room it rents
     * out, and one deduction agreed with a doctor. Without these the P&L only
     * knows income and the "utilidad neta" is not net at all.
     */
    private function seedFinanceEntries(Workspace $workspace): void
    {
        if (FinanceEntry::where('workspace_id', $workspace->id)->exists()) {
            $this->command->info('Finance entries already present, skipping.');

            return;
        }

        $rows = [];
        $today = Carbon::today();

        // Three closed months of fixed costs, so the six-month trend has shape.
        // A cost dated after today has not happened yet: it is skipped, not
        // moved, or the current month closes with expenses nobody has paid.
        for ($monthsAgo = 3; $monthsAgo >= 0; $monthsAgo--) {
            $month = $today->copy()->subMonths($monthsAgo)->startOfMonth();

            $fixed = [
                [3, 'expense', 'rent', 'Arriendo del local', '850.00'],
                [8, 'expense', 'utilities', 'Luz, agua e internet', (string) rand(140, 210).'.00'],
                [28, 'expense', 'payroll', 'Sueldo de enfermería y recepción', '1180.00'],
                [5, 'income', 'room_rental', 'Alquiler de consultorio a especialista visitante', '250.00'],
            ];

            foreach ($fixed as [$dayOfMonth, $type, $category, $description, $amount]) {
                $date = $month->copy()->addDays($dayOfMonth - 1);

                if ($date->greaterThan($today)) {
                    continue;
                }

                $rows[] = [$type, $category, $description, $amount, $date];
            }
        }

        // Supplies land irregularly, the way they actually get bought.
        for ($weeksAgo = 12; $weeksAgo >= 0; $weeksAgo--) {
            if (rand(1, 3) === 1) {
                continue;
            }
            $rows[] = [
                'expense',
                'supplies',
                Arr::random(['Guantes, jeringas y gasas', 'Insumos de laboratorio', 'Papelería e impresión', 'Material de curación']),
                (string) rand(35, 160).'.00',
                $today->copy()->subWeeks($weeksAgo),
            ];
        }

        $rows[] = ['expense', 'maintenance', 'Mantenimiento del ecógrafo', '180.00', $today->copy()->subDays(21)];
        $rows[] = ['expense', 'equipment', 'Tensiómetro y oxímetro nuevos', '260.00', $today->copy()->subDays(46)];

        foreach ($rows as [$type, $category, $description, $amount, $date]) {
            FinanceEntry::create([
                'workspace_id' => $workspace->id,
                'type' => $type,
                'category' => $category,
                'description' => $description,
                'amount' => $amount,
                'entry_date' => $date->toDateString(),
            ]);
        }

        // A deduction agreed with the doctor who has the biggest share.
        $doctor = $workspace->members()
            ->whereNotNull('doctor_id')
            ->with('doctor')
            ->get()
            ->firstWhere('commission_pct', '!=', null);

        if ($doctor?->doctor_id) {
            FinanceEntry::create([
                'workspace_id' => $workspace->id,
                'type' => FinanceEntry::TYPE_DOCTOR_ADJUSTMENT,
                'category' => 'supplies',
                'description' => 'Insumos consumidos en procedimientos',
                'amount' => '45.00',
                'entry_date' => $today->copy()->subDays(9)->toDateString(),
                'doctor_id' => $doctor->doctor_id,
            ]);
        }

        $this->command->info('Seeded '.(count($rows) + 1).' finance entries (expenses, other income and one doctor deduction).');
    }

    /**
     * Generates a valid Ecuadorian cédula (passes App\Rules\EcuadorianId):
     * province 17, natural-person third digit, mod-10 check digit.
     */
    private function validCedula(): string
    {
        $digits = [1, 7, rand(0, 5)];
        for ($i = 3; $i < 9; $i++) {
            $digits[$i] = rand(0, 9);
        }

        $digits[9] = $this->mod10($digits);

        return implode('', $digits);
    }

    /**
     * Natural-person RUC: a valid cédula + establishment suffix "001".
     */
    private function validRuc(): string
    {
        return $this->validCedula() . '001';
    }

    /**
     * Module-10 check digit (same algorithm as App\Rules\EcuadorianId).
     *
     * @param  int[]  $digits  first 9 digits
     */
    private function mod10(array $digits): int
    {
        $coefficients = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $product = $digits[$i] * $coefficients[$i];
            $sum += $product >= 10 ? $product - 9 : $product;
        }

        $check = 10 - ($sum % 10);

        return $check === 10 ? 0 : $check;
    }
}
