<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Cie10Code;
use App\Models\Doctor;
use App\Models\FinanceEntry;
use App\Models\Invoice;
use App\Models\MedicalAttachment;
use App\Models\MedicalRecord;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\RecordDiagnosis;
use App\Models\RecordPrescription;
use App\Models\RecordVital;
use App\Models\Workspace;
use App\Services\ClinicService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\WorkspaceEntitlementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Makes «Clínica Demo Elosar» look like a clinic that has been working for
 * months, for the promo videos: a designed letterhead, a full fee schedule,
 * six months of attentions, invoices, payments and running costs (so Reportes
 * has a shape), documents in the file, a colourful week in the agenda and a
 * second clinic for the owner doctor.
 *
 * Idempotent and demo-only: every block checks its own marker and adds only
 * what is missing; the week agenda is rebuilt around the current week on each
 * run. Never touches any other workspace than the ones it creates.
 */
class DemoClinicEnricher
{
    public const HISTORY_NOTE = 'Historia demo';

    public const WEEK_NOTE = 'Semana demo';

    public const SECOND_CLINIC = 'Centro Médico Los Valles';

    private const SERVICES = [
        ['Consulta médica general', 'general', '30.00'],
        ['Consulta de ginecología', 'general', '40.00'],
        ['Control / seguimiento', 'general', '20.00'],
        ['Control prenatal', 'general', '35.00'],
        ['Certificado médico', 'general', '15.00'],
        ['Ecografía obstétrica', 'imaging', '45.00'],
        ['Ecografía pélvica', 'imaging', '40.00'],
        ['Biometría hemática completa', 'laboratory', '12.00'],
        ['Perfil lipídico', 'laboratory', '18.00'],
        ['Glucosa en ayunas', 'laboratory', '5.00'],
        ['Papanicolaou', 'laboratory', '25.00'],
        ['Electrocardiograma', 'procedure', '25.00'],
        ['Nebulización', 'procedure', '10.00'],
        ['Curación de heridas', 'procedure', '15.00'],
        ['Inyección intramuscular', 'procedure', '5.00'],
        ['Retiro de puntos', 'procedure', '12.00'],
    ];

    /** [CIE-10, fallback description, reason, plan, service name, type] */
    private const CASES = [
        ['J02.9', 'Faringitis aguda, no especificada', 'Dolor de garganta y fiebre', 'Analgésico, hidratación y control en 5 días.', 'Consulta médica general', 'consultation'],
        ['I10', 'Hipertensión esencial (primaria)', 'Control de presión arterial', 'Mantener losartán, dieta baja en sal.', 'Control / seguimiento', 'control'],
        ['E11.9', 'Diabetes mellitus tipo 2 sin complicaciones', 'Control de glucosa', 'Metformina, perfil lipídico.', 'Control / seguimiento', 'control'],
        ['Z34.9', 'Supervisión de embarazo normal', 'Control prenatal', 'Ácido fólico, hierro, ecografía.', 'Control prenatal', 'control'],
        ['N39.0', 'Infección de vías urinarias', 'Ardor al orinar', 'Antibiótico por 5 días, abundantes líquidos.', 'Consulta médica general', 'consultation'],
        ['M54.5', 'Lumbago no especificado', 'Dolor lumbar', 'Analgésico y pausas activas.', 'Consulta médica general', 'consultation'],
        ['J45.9', 'Asma, no especificada', 'Falta de aire', 'Nebulización y salbutamol de rescate.', 'Nebulización', 'procedure'],
        ['N76.0', 'Vaginitis aguda', 'Flujo vaginal anormal', 'Óvulos por 6 noches.', 'Consulta de ginecología', 'consultation'],
        ['K29.7', 'Gastritis, no especificada', 'Dolor en la boca del estómago', 'Omeprazol por 14 días, dieta.', 'Consulta médica general', 'consultation'],
        ['Z00.0', 'Examen médico general', 'Chequeo general', 'Exámenes de rutina.', 'Biometría hemática completa', 'exams'],
        ['T14.1', 'Herida de región no especificada', 'Herida en la mano', 'Curación y control en 3 días.', 'Curación de heridas', 'procedure'],
        ['N94.6', 'Dismenorrea, no especificada', 'Dolor menstrual', 'Antiinflamatorio y ecografía pélvica.', 'Ecografía pélvica', 'exams'],
    ];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
    ) {}

    public function run(Workspace $clinic): void
    {
        $owner = Doctor::query()->where('email', 'dr.salazar@'.self::domain())->first();
        $gyneco = Doctor::query()->where('email', 'dra.ortiz@'.self::domain())->first();
        if (! $owner || ! $gyneco) {
            return;
        }

        $services = $this->services($clinic);
        $this->letterhead($clinic);
        $this->history($clinic, $owner, $gyneco, $services);
        $this->finance($clinic);
        $this->documents($clinic);
        $this->week($clinic, $owner, $gyneco);
        $this->secondClinic($owner);
    }

    private static function domain(): string
    {
        return \App\Console\Commands\DemoClinicCommand::EMAIL_DOMAIN;
    }

    /** @return array<string, MedicalService> by name */
    private function services(Workspace $clinic): array
    {
        $out = [];
        foreach (self::SERVICES as $i => [$name, $category, $price]) {
            $out[$name] = MedicalService::query()->firstOrCreate(
                ['workspace_id' => $clinic->id, 'name' => $name],
                ['category' => $category, 'base_price' => $price, 'iva_rate' => '0.00', 'is_active' => true, 'sort_order' => $i + 1],
            );
        }

        return $out;
    }

    // ── Letterhead ────────────────────────────────────────────────────────────

    /** A designed banner (logo, name, contact, brand stripe), drawn with GD. */
    private function letterhead(Workspace $clinic): void
    {
        $disk = Storage::disk('local');
        if ($clinic->header_image_path && $disk->exists($clinic->header_image_path)) {
            return;
        }

        $bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $regular = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        if (! is_file($bold) || ! is_file($regular)) {
            return;
        }

        [$w, $h] = [2400, 440];
        $img = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($img, 255, 255, 255);
        $navy = imagecolorallocate($img, 24, 36, 58);
        $blue = imagecolorallocate($img, 73, 111, 216);
        $grey = imagecolorallocate($img, 75, 88, 112);
        imagefill($img, 0, 0, $white);

        // Brand stripe: teal → blue, along the bottom.
        for ($x = 0; $x < $w; $x++) {
            $t = $x / $w;
            $c = imagecolorallocate($img, (int) (83 + (73 - 83) * $t), (int) (201 + (111 - 201) * $t), (int) (192 + (216 - 192) * $t));
            imageline($img, $x, $h - 26, $x, $h, $c);
        }

        // Logo: a rounded cross inside a circle.
        imagefilledellipse($img, 220, 200, 300, 300, $blue);
        imagefilledrectangle($img, 195, 110, 245, 290, $white);
        imagefilledrectangle($img, 130, 175, 310, 225, $white);

        imagettftext($img, 78, 0, 430, 185, $navy, $bold, 'Clínica Demo Elosar');
        imagettftext($img, 38, 0, 434, 255, $blue, $regular, 'Medicina General · Ginecología y Obstetricia');
        imagettftext($img, 30, 0, 434, 335, $grey, $regular, 'Av. Amazonas N00-00 y Ejemplo, Quito  ·  (02) 000-0000  ·  contacto@'.self::domain());

        ob_start();
        imagepng($img, null, 6);
        $path = 'letterheads/demo-banner.png';
        $disk->put($path, (string) ob_get_clean());

        $settings = $clinic->prescription_settings ?? [];
        $settings['header_mode'] = 'image';
        $clinic->forceFill(['header_image_path' => $path, 'prescription_settings' => $settings])->save();
    }

    // ── Six months of work ───────────────────────────────────────────────────

    /** @param array<string, MedicalService> $services */
    private function history(Workspace $clinic, Doctor $owner, Doctor $gyneco, array $services): void
    {
        if (Invoice::query()->where('workspace_id', $clinic->id)->where('notes', self::HISTORY_NOTE)->exists()) {
            return;
        }

        $patients = Patient::query()->where('workspace_id', $clinic->id)->get();
        if ($patients->isEmpty()) {
            return;
        }

        mt_srand(2026);
        $today = Carbon::today();
        for ($daysAgo = 180; $daysAgo >= 1; $daysAgo--) {
            $day = $today->copy()->subDays($daysAgo);
            if ($day->isSunday()) {
                continue;
            }
            // A clinic that grows: fewer visits half a year ago, more lately.
            $perDay = $daysAgo > 120 ? mt_rand(1, 2) : ($daysAgo > 60 ? mt_rand(1, 3) : mt_rand(2, 4));

            for ($n = 0; $n < $perDay; $n++) {
                $patient = $patients[mt_rand(0, $patients->count() - 1)];
                $case = self::CASES[mt_rand(0, count(self::CASES) - 1)];
                $doctor = ($patient->gender === 'female' && mt_rand(0, 1)) || $case[5] === 'control' && str_contains($case[2], 'prenatal') ? $gyneco : $owner;
                $this->attention($clinic, $patient, $doctor, $day->copy()->setTime(8 + $n * 2 + mt_rand(0, 1), [0, 30][mt_rand(0, 1)]), $case, $services);
            }
        }
    }

    /**
     * @param  array{0:string,1:string,2:string,3:string,4:string,5:string}  $case
     * @param  array<string, MedicalService>  $services
     */
    private function attention(Workspace $clinic, Patient $patient, Doctor $doctor, Carbon $when, array $case, array $services): void
    {
        [$code, $fallback, $reason, $plan, $serviceName, $type] = $case;

        $appointment = Appointment::query()->create([
            'workspace_id' => $clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
            'appointment_date' => $when, 'status' => 'completed', 'checked_in_at' => $when,
            'type' => $type, 'channel' => 'in_person', 'duration_minutes' => 30, 'notes' => self::HISTORY_NOTE,
        ]);

        $record = MedicalRecord::query()->create([
            'workspace_id' => $clinic->id, 'patient_id' => $patient->id, 'appointment_id' => $appointment->id,
            'created_by' => $doctor->id, 'status' => 'completed', 'reason_for_consultation' => $reason,
            'diagnosis' => $fallback, 'treatment' => $plan,
        ]);
        $record->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();

        RecordDiagnosis::query()->create([
            'medical_record_id' => $record->id, 'cie10_code' => $code,
            'cie10_description' => Cie10Code::query()->where('code', $code)->value('description') ?? $fallback, 'type' => 'primary',
        ]);
        RecordVital::query()->create([
            'medical_record_id' => $record->id, 'blood_pressure_sys' => mt_rand(105, 140), 'blood_pressure_dia' => mt_rand(65, 90),
            'heart_rate' => mt_rand(62, 92), 'temperature' => mt_rand(362, 378) / 10, 'oxygen_saturation' => mt_rand(95, 99),
            'respiratory_rate' => mt_rand(14, 20), 'recorded_at' => $when,
        ]);
        if (in_array($type, ['consultation', 'control'], true)) {
            RecordPrescription::query()->create([
                'medical_record_id' => $record->id, 'medication_name' => ['Paracetamol', 'Ibuprofeno', 'Omeprazol', 'Losartán'][mt_rand(0, 3)],
                'concentration' => '500 mg', 'dosage' => '1 tableta', 'route' => 'oral', 'frequency' => 'cada 8 horas',
                'duration_days' => 5, 'quantity' => 15, 'is_controlled' => false,
            ]);
        }

        $service = $services[$serviceName] ?? reset($services);
        $invoice = $this->invoices->create([
            'workspace_id' => $clinic->id, 'patient_id' => $patient->id, 'appointment_id' => $appointment->id,
            'created_by' => $doctor->id, 'attending_doctor_id' => $doctor->id,
            'issued_at' => $when->toDateString(), 'notes' => self::HISTORY_NOTE,
            'items' => [[
                'medical_service_id' => $service->id, 'description' => $service->name, 'quantity' => '1',
                'unit_price' => (string) $service->base_price, 'iva_rate' => '0.00', 'discount_percentage' => '0', 'sort_order' => 0,
            ]],
            'buyer_id_type' => '05', 'buyer_id' => $patient->id_number, 'buyer_name' => $patient->name,
            'buyer_address' => $patient->city ?? 'Quito', 'buyer_email' => $patient->email,
        ]);

        // Most are paid on the spot; a few stay pending, like in real life.
        if (mt_rand(1, 100) <= 88) {
            $this->payments->register($invoice, [
                'amount' => (string) $invoice->amount_due,
                'payment_method' => ['cash', 'cash', 'transfer', 'card'][mt_rand(0, 3)],
                'payment_date' => $when->toDateString(),
                'registered_by' => $doctor->id,
            ]);
        }
    }

    private function finance(Workspace $clinic): void
    {
        if (FinanceEntry::query()->where('workspace_id', $clinic->id)->exists()) {
            return;
        }

        $today = Carbon::today();
        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = $today->copy()->subMonths($monthsAgo)->startOfMonth();
            foreach ([
                [3, 'expense', 'rent', 'Arriendo del local', '900.00'],
                [8, 'expense', 'utilities', 'Luz, agua e internet', '175.00'],
                [15, 'expense', 'supplies', 'Insumos médicos y de laboratorio', '220.00'],
                [28, 'expense', 'payroll', 'Sueldos de recepción, enfermería y caja', '1450.00'],
            ] as [$day, $type, $category, $description, $amount]) {
                $date = $month->copy()->addDays($day - 1);
                if ($date->greaterThan($today)) {
                    continue;
                }
                FinanceEntry::query()->create([
                    'workspace_id' => $clinic->id, 'type' => $type, 'category' => $category,
                    'description' => $description, 'amount' => $amount, 'entry_date' => $date->toDateString(),
                ]);
            }
        }
    }

    // ── Documents in the file ─────────────────────────────────────────────────

    private function documents(Workspace $clinic): void
    {
        if (MedicalAttachment::query()->where('workspace_id', $clinic->id)->where('notes', self::HISTORY_NOTE)->exists()) {
            return;
        }

        $patients = Patient::query()->where('workspace_id', $clinic->id)->orderBy('id')->take(6)->get();
        $docs = [
            ['Biometría hemática', 'lab', 'pdf', 'Hemoglobina 13.8 g/dL · Leucocitos 7 200/mm³ · Plaquetas 265 000/mm³'],
            ['Perfil lipídico', 'lab', 'pdf', 'Colesterol total 212 mg/dL · LDL 138 mg/dL · HDL 44 mg/dL · Triglicéridos 160 mg/dL'],
            ['Ecografía obstétrica', 'image', 'png', null],
            ['Glucosa en ayunas', 'lab', 'pdf', 'Glucosa 104 mg/dL (ayunas de 8 horas)'],
            ['Radiografía de tórax', 'image', 'png', null],
            ['Examen general de orina', 'lab', 'pdf', 'Leucocitos 2–4/campo · Nitritos negativos · Densidad 1.020'],
        ];

        foreach ($docs as $i => [$title, $category, $kind, $result]) {
            $patient = $patients[$i % max(1, $patients->count())] ?? null;
            if (! $patient) {
                return;
            }
            $path = "attachments/{$clinic->id}/{$patient->id}/demo-".($i + 1).'.'.$kind;
            $bytes = $kind === 'pdf' ? $this->labPdf($title, $patient->name, (string) $result) : $this->scanImage($title);
            Storage::disk('local')->put($path, $bytes);

            $attachment = MedicalAttachment::query()->create([
                'workspace_id' => $clinic->id, 'patient_id' => $patient->id, 'title' => $title,
                'category' => $category, 'file_path' => $path, 'notes' => self::HISTORY_NOTE,
            ]);
            $when = Carbon::today()->subDays(3 + $i * 9);
            $attachment->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
        }
    }

    private function labPdf(string $title, string $patient, string $result): string
    {
        $html = '<html><body style="font-family: DejaVu Sans, sans-serif; color:#18243a; padding:30px">'
            .'<div style="border-bottom:3px solid #496fd8; padding-bottom:8px; font-size:20px; font-weight:bold">Laboratorio Clínico Demo</div>'
            .'<p style="color:#6e7a91">Documento de demostración</p>'
            .'<h2 style="color:#2f4c9d">'.e($title).'</h2>'
            .'<p><b>Paciente:</b> '.e($patient).'</p>'
            .'<p style="font-size:14px; line-height:1.8">'.e($result).'</p>'
            .'<p style="margin-top:40px; color:#6e7a91">Valores de referencia según el laboratorio.</p></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    /** A grey scan-like image (ultrasound / x-ray look) with its label. */
    private function scanImage(string $title): string
    {
        $img = imagecreatetruecolor(900, 680);
        imagefill($img, 0, 0, imagecolorallocate($img, 12, 14, 18));
        mt_srand(crc32($title));
        for ($i = 0; $i < 9000; $i++) {
            $g = mt_rand(40, 170);
            $c = imagecolorallocatealpha($img, $g, $g, $g, mt_rand(60, 115));
            imagefilledellipse($img, (int) (450 + mt_rand(-260, 260) * (mt_rand(0, 100) / 100)), (int) (340 + mt_rand(-220, 220) * (mt_rand(0, 100) / 100)), mt_rand(3, 14), mt_rand(3, 14), $c);
        }
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        if (is_file($font)) {
            imagettftext($img, 18, 0, 24, 40, imagecolorallocate($img, 220, 220, 220), $font, mb_strtoupper($title).' — DEMO');
        }
        ob_start();
        imagepng($img, null, 6);

        return (string) ob_get_clean();
    }

    // ── A colourful week ──────────────────────────────────────────────────────

    /**
     * The current week (Mon–Sat) with varied visit types and lengths and real
     * gaps between them, so the calendar reads at a glance. Today's morning is
     * the demo queue; this adds the afternoons and the other days. Rebuilt on
     * every run around the current week.
     */
    private function week(Workspace $clinic, Doctor $owner, Doctor $gyneco): void
    {
        Appointment::query()->where('workspace_id', $clinic->id)->where('notes', self::WEEK_NOTE)->delete();

        $patients = Patient::query()->where('workspace_id', $clinic->id)->orderBy('id')->get();
        if ($patients->isEmpty()) {
            return;
        }

        // [hour, minutes, duration, type, gyneco?]
        $slots = [
            [8, 0, 30, 'consultation', false], [9, 0, 45, 'control', true], [10, 30, 30, 'exams', false],
            [11, 30, 60, 'procedure', true], [14, 0, 30, 'consultation', true], [15, 0, 30, 'follow_up', false],
            [16, 0, 45, 'consultation', false], [17, 0, 30, 'walk_in', true],
        ];

        $monday = Carbon::today()->startOfWeek();
        $today = Carbon::today();
        $p = 0;
        for ($d = 0; $d < 6; $d++) {
            $day = $monday->copy()->addDays($d);
            foreach ($slots as $i => [$hour, $minute, $duration, $type, $gyn]) {
                // Leave the demo queue alone (today's morning) and skip a few slots for air.
                if (($day->isSameDay($today) && $hour < 12) || (($d + $i) % 4 === 3)) {
                    continue;
                }
                $when = $day->copy()->setTime($hour, $minute);
                Appointment::query()->create([
                    'workspace_id' => $clinic->id,
                    'patient_id' => $patients[$p++ % $patients->count()]->id,
                    'doctor_id' => ($gyn ? $gyneco : $owner)->id,
                    'appointment_date' => $when,
                    'duration_minutes' => $duration,
                    'status' => $when->lessThan(now()) ? 'completed' : 'scheduled',
                    'checked_in_at' => $when->lessThan(now()) ? $when : null,
                    'type' => $type,
                    'channel' => 'in_person',
                    'notes' => self::WEEK_NOTE,
                ]);
            }
        }
    }

    // ── A doctor in more than one clinic ──────────────────────────────────────

    private function secondClinic(Doctor $owner): void
    {
        if (Workspace::query()->where('type', 'clinic')->where('name', self::SECOND_CLINIC)->exists()) {
            return;
        }

        $clinic = Workspace::query()->create([
            'name' => self::SECOND_CLINIC,
            'type' => 'clinic',
            'invite_code' => strtoupper(\Illuminate\Support\Str::random(8)),
            'owner_doctor_id' => $owner->id,
            'owner_user_id' => $owner->user_id,
            'prescription_settings' => ['header_mode' => 'digital', 'header' => self::SECOND_CLINIC, 'subheader' => 'Medicina General', 'address' => 'Sangolquí, Valle de los Chillos (demo)'],
        ]);
        app(ClinicService::class)->attachDoctor($clinic, $owner, role: 'doctor');
        app(WorkspaceEntitlementService::class)->provisionLegacyEntitlement($clinic, null, 'Segunda clínica demo (elosar:demo-clinic).');

        foreach (['Rosa Elena Quishpe Toapanta', 'Carlos Andrés Pilco Guamán', 'Lucía Fernanda Tipán Vega'] as $i => $name) {
            Patient::query()->create([
                'workspace_id' => $clinic->id, 'name' => $name, 'id_number' => sprintf('99%08d', 901 + $i),
                'document_type' => 'cedula', 'birth_date' => Carbon::today()->subYears(30 + $i * 9),
                'gender' => $i === 1 ? 'male' : 'female', 'city' => 'Sangolquí', 'phone' => '09900009'.($i + 10),
                'data_consent_at' => now(), 'data_consent_version' => config('lopdp.consent_version'),
            ]);
        }
    }
}
