<?php

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientAllergy;
use Laravel\Sanctum\Sanctum;

/*
| Clinic cobro flow: the cashier (billing) charges a patient already attended by
| a doctor, without ever seeing clinical data. Actors from makeClinicScenario().
*/

function cajaHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** A completed appointment (ready to be charged) in the clinic. */
function completedAppointment(int $workspaceId, int $doctorId): Appointment
{
    $patient = Patient::factory()->create(['workspace_id' => $workspaceId]);

    return Appointment::create([
        'workspace_id'     => $workspaceId,
        'patient_id'       => $patient->id,
        'doctor_id'        => $doctorId,
        'appointment_date' => now(),
        'status'           => 'completed',
    ]);
}

function cajaInvoicePayload(int $workspaceId, int $patientId, array $extra = []): array
{
    return array_merge([
        'workspace_id' => $workspaceId,
        'patient_id'   => $patientId,
        'issued_at'    => now()->toDateString(),
        'items'        => [['description' => 'Consulta médica', 'quantity' => 1, 'unit_price' => 30]],
    ], $extra);
}

// ─────────────────────────────────────────────
// "Por cobrar"
// ─────────────────────────────────────────────

it('lists completed appointments without an invoice as billable, and drops them once invoiced', function () {
    $s = makeClinicScenario();
    $appt = completedAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = cajaHeaders($s['clinic']->id);
    $billing = makeStaff('Caja', 'caja_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    Sanctum::actingAs($billing);

    $list = $this->getJson('/api/v1/clinic/billables', $h)->assertOk()->json('data');
    expect(collect($list)->pluck('appointment_id'))->toContain($appt->id);
    $row = collect($list)->firstWhere('appointment_id', $appt->id);
    expect($row['doctor']['id'])->toBe($s['memDoctor']->id)
        ->and($row['patient'])->toHaveKeys(['id', 'name', 'id_number']);

    // Invoice it → it leaves the billables list.
    $this->postJson('/api/v1/invoices', cajaInvoicePayload($s['clinic']->id, $appt->patient_id, [
        'appointment_id' => $appt->id,
    ]), $h)->assertStatus(201);

    $after = $this->getJson('/api/v1/clinic/billables', $h)->assertOk()->json('data');
    expect(collect($after)->pluck('appointment_id'))->not->toContain($appt->id);
});

it('rejects an invoice linked to an appointment that is not completed', function () {
    $s = makeClinicScenario();
    $appt = completedAppointment($s['clinic']->id, $s['memDoctor']->id);
    $appt->update(['status' => 'scheduled']);
    $billing = makeStaff('Caja', 'caja_pending_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    Sanctum::actingAs($billing);

    $this->postJson('/api/v1/invoices', cajaInvoicePayload($s['clinic']->id, $appt->patient_id, [
        'appointment_id' => $appt->id,
    ]), cajaHeaders($s['clinic']->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('appointment_id');

    expect(Invoice::where('appointment_id', $appt->id)->exists())->toBeFalse();
});

it('rejects an invoice whose patient differs from its completed appointment', function () {
    $s = makeClinicScenario();
    $appt = completedAppointment($s['clinic']->id, $s['memDoctor']->id);
    $otherPatient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $billing = makeStaff('Caja', 'caja_patient_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    Sanctum::actingAs($billing);

    $this->postJson('/api/v1/invoices', cajaInvoicePayload($s['clinic']->id, $otherPatient->id, [
        'appointment_id' => $appt->id,
    ]), cajaHeaders($s['clinic']->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('appointment_id');

    expect(Invoice::where('appointment_id', $appt->id)->exists())->toBeFalse();
});

it('rejects a second invoice for the same completed appointment', function () {
    $s = makeClinicScenario();
    $appt = completedAppointment($s['clinic']->id, $s['memDoctor']->id);
    $billing = makeStaff('Caja', 'caja_duplicate_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    Sanctum::actingAs($billing);
    $payload = cajaInvoicePayload($s['clinic']->id, $appt->patient_id, ['appointment_id' => $appt->id]);

    $this->postJson('/api/v1/invoices', $payload, cajaHeaders($s['clinic']->id))->assertStatus(201);
    $this->postJson('/api/v1/invoices', $payload, cajaHeaders($s['clinic']->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('appointment_id');

    expect(Invoice::where('appointment_id', $appt->id)->count())->toBe(1);
});

// ─────────────────────────────────────────────
// LODP: billing-scoped patient lookup
// ─────────────────────────────────────────────

it('returns only minimal billing fields in the patient lookup (no clinical data)', function () {
    $s = makeClinicScenario();
    $billing = makeStaff('Caja', 'caja2_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id, 'name' => 'Zoraida Quishpe']);
    PatientAllergy::create(['patient_id' => $patient->id, 'allergen_name' => 'Penicilina', 'severity' => 'severe']);
    $h = cajaHeaders($s['clinic']->id);
    Sanctum::actingAs($billing);

    $rows = $this->getJson('/api/v1/billing/patients?q=Zoraida', $h)->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and(array_keys($rows[0]))->toEqualCanonicalizing(['id', 'name', 'id_number'])
        ->and($rows[0])->not->toHaveKey('allergies');
});

it('forbids a nurse from the cashier endpoints but lets a receptionist collect', function () {
    $s = makeClinicScenario();
    $h = cajaHeaders($s['clinic']->id);

    // Nurse handles triage/vitals, never the cash desk.
    Sanctum::actingAs($s['nurseUser']);
    $this->getJson('/api/v1/clinic/billables', $h)->assertStatus(403);
    $this->getJson('/api/v1/billing/patients?q=ana', $h)->assertStatus(403);

    // Reception doubles as the cash desk (billing.view + billing.manage).
    Sanctum::actingAs($s['recUser']);
    $this->getJson('/api/v1/clinic/billables', $h)->assertOk();
    $this->getJson('/api/v1/billing/patients?q=ana', $h)->assertOk();
});

// ─────────────────────────────────────────────
// Attribution
// ─────────────────────────────────────────────

it('attributes the attending doctor and the issuing cashier on an appointment invoice', function () {
    $s = makeClinicScenario();
    $appt = completedAppointment($s['clinic']->id, $s['memDoctor']->id);
    $billing = makeStaff('Caja', 'caja3_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    $h = cajaHeaders($s['clinic']->id);
    Sanctum::actingAs($billing);

    $invoiceId = $this->postJson('/api/v1/invoices', cajaInvoicePayload($s['clinic']->id, $appt->patient_id, [
        'appointment_id' => $appt->id,
    ]), $h)->assertStatus(201)->json('data.id');

    $this->assertDatabaseHas('invoices', [
        'id'                  => $invoiceId,
        'appointment_id'      => $appt->id,
        'attending_doctor_id' => $s['memDoctor']->id,
        'issued_by_user_id'   => $billing->id,
    ]);
});

it('accepts a walk-in invoice with an explicit attending doctor', function () {
    $s = makeClinicScenario();
    $billing = makeStaff('Caja', 'caja4_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $h = cajaHeaders($s['clinic']->id);
    Sanctum::actingAs($billing);

    $this->postJson('/api/v1/invoices', cajaInvoicePayload($s['clinic']->id, $patient->id, [
        'attending_doctor_id' => $s['memDoctor']->id,
    ]), $h)->assertStatus(201)->assertJsonPath('data.attending_doctor.id', $s['memDoctor']->id);
});

it('rejects an attending doctor from another workspace (422)', function () {
    $s = makeClinicScenario();
    $billing = makeStaff('Caja', 'caja5_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    [$foreignDoctor] = makeDoctorWithWorkspace(); // belongs to a different workspace
    $h = cajaHeaders($s['clinic']->id);
    Sanctum::actingAs($billing);

    $this->postJson('/api/v1/invoices', cajaInvoicePayload($s['clinic']->id, $patient->id, [
        'attending_doctor_id' => $foreignDoctor->id,
    ]), $h)->assertStatus(422)->assertJsonValidationErrors('attending_doctor_id');
});

it('records the cashier user on the payment', function () {
    $s = makeClinicScenario();
    $appt = completedAppointment($s['clinic']->id, $s['memDoctor']->id);
    $billing = makeStaff('Caja', 'caja6_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    $h = cajaHeaders($s['clinic']->id);
    Sanctum::actingAs($billing);

    $invoiceId = $this->postJson('/api/v1/invoices', cajaInvoicePayload($s['clinic']->id, $appt->patient_id, [
        'appointment_id' => $appt->id,
    ]), $h)->json('data.id');

    $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
        'amount'         => '30.00',
        'payment_method' => 'cash',
        'payment_date'   => now()->toDateString(),
    ], $h)->assertStatus(201);

    $this->assertDatabaseHas('payments', [
        'invoice_id'            => $invoiceId,
        'registered_by_user_id' => $billing->id,
    ]);
});

// ─────────────────────────────────────────────
// Reporting
// ─────────────────────────────────────────────

it('reports collected revenue grouped by attending doctor', function () {
    $s = makeClinicScenario();
    $appt = completedAppointment($s['clinic']->id, $s['memDoctor']->id);
    $billing = makeStaff('Caja', 'caja7_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    $h = cajaHeaders($s['clinic']->id);
    Sanctum::actingAs($billing);

    $invoiceId = $this->postJson('/api/v1/invoices', cajaInvoicePayload($s['clinic']->id, $appt->patient_id, [
        'appointment_id' => $appt->id,
    ]), $h)->json('data.id');
    $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
        'amount' => '30.00', 'payment_method' => 'cash', 'payment_date' => now()->toDateString(),
    ], $h)->assertStatus(201);

    $report = $this->getJson('/api/v1/reports/revenue', $h)->assertOk()->json('data');
    $row = collect($report['by_doctor'])->firstWhere('doctor_id', $s['memDoctor']->id);

    expect($row)->not->toBeNull()
        ->and($row['total'])->toBe('30.00');
});
