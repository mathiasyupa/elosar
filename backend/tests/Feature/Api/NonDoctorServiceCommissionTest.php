<?php

use App\Models\DoctorCommissionRule;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Services\Reports\DoctorSettlementService;
use App\Support\ActiveWorkspace;

/**
 * Who earns the commission on work a doctor did not perform.
 *
 * A clinic laboratory is usually run by a technician, not a physician, but the
 * invoice carries a single attending_doctor_id — so a laboratory line rides on
 * the attending doctor's invoice and, by default, on their global percentage.
 *
 * This became easy to hit the moment doctors started declaring performed
 * services themselves: what used to depend on the cashier remembering to add
 * the lab now lands on the invoice every time.
 *
 * The escape hatch already exists — a per-service DoctorCommissionRule beats the
 * global percentage — but "0 %" only works if a zero-valued rule is treated as a
 * rule and not as an absent one. These tests pin exactly that.
 */
it('pays the attending doctor a commission on laboratory they did not perform', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $doctor = $s['memDoctor'];
    app(ActiveWorkspace::class)->bind((int) $clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    // The doctor's global agreement: 50 % of everything.
    $clinic->members()->where('doctor_id', $doctor->id)->update(['commission_pct' => '50.00']);

    $lab = MedicalService::factory()->create([
        'workspace_id' => $clinic->id, 'name' => 'Biometría', 'category' => 'laboratory', 'base_price' => '20.00',
    ]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $doctor->id,
        'status' => 'paid',
        'issued_at' => now(),
        'total' => '20.00',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'medical_service_id' => $lab->id,
        'description' => 'Biometría', 'quantity' => 1, 'unit_price' => '20.00', 'line_total' => '20.00',
    ]);
    $invoice->payments()->create(['workspace_id' => $clinic->id, 'amount' => '20.00', 'payment_method' => 'cash', 'payment_date' => now()]);

    $rows = app(DoctorSettlementService::class)->forWorkspace((int) $clinic->id);
    $row = collect($rows)->firstWhere('doctor_id', $doctor->id);

    // NOT "Sin asignar": it is attributed, and the doctor takes half the lab.
    expect($row)->not->toBeNull()
        ->and($row['doctor_amount'])->toBe('10.00');
});

it('lets a per-service rule of zero keep the laboratory out of the commission', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $doctor = $s['memDoctor'];
    app(ActiveWorkspace::class)->bind((int) $clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    $clinic->members()->where('doctor_id', $doctor->id)->update(['commission_pct' => '50.00']);

    $lab = MedicalService::factory()->create([
        'workspace_id' => $clinic->id, 'name' => 'Biometría', 'category' => 'laboratory', 'base_price' => '20.00',
    ]);
    $consult = MedicalService::factory()->create([
        'workspace_id' => $clinic->id, 'name' => 'Consulta', 'category' => 'general', 'base_price' => '30.00',
    ]);

    // The clinic keeps 100 % of the laboratory: the technician's work, not his.
    DoctorCommissionRule::create([
        'workspace_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'medical_service_id' => $lab->id,
        'rule_type' => DoctorCommissionRule::TYPE_PERCENTAGE,
        'value' => '0.00',
    ]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $doctor->id,
        'status' => 'paid',
        'issued_at' => now(),
        'total' => '50.00',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'medical_service_id' => $lab->id,
        'description' => 'Biometría', 'quantity' => 1, 'unit_price' => '20.00', 'line_total' => '20.00',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'medical_service_id' => $consult->id,
        'description' => 'Consulta', 'quantity' => 1, 'unit_price' => '30.00', 'line_total' => '30.00',
    ]);
    $invoice->payments()->create(['workspace_id' => $clinic->id, 'amount' => '50.00', 'payment_method' => 'cash', 'payment_date' => now()]);

    $rows = app(DoctorSettlementService::class)->forWorkspace((int) $clinic->id);
    $row = collect($rows)->firstWhere('doctor_id', $doctor->id);

    // Half the consultation (15.00) and nothing of the laboratory. A zero-valued
    // rule must count as a rule, not fall through to the global 50 %.
    expect($row['doctor_amount'])->toBe('15.00');
});
