<?php

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

function dashHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

// ─────────────────────────────────────────────
// Dashboard stats
// ─────────────────────────────────────────────

it('returns dashboard stats scoped to the active workspace with money as strings', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    // Own workspace: 1 appointment today + a paid invoice collected this month.
    Appointment::factory()->create([
        'workspace_id'     => $ws->id,
        'patient_id'       => $patient->id,
        'appointment_date' => now(),
        'status'           => 'scheduled',
    ]);
    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'total'        => '200.00',
    ]);
    Payment::factory()->create([
        'workspace_id' => $ws->id,
        'invoice_id'   => $invoice->id,
        'amount'       => '200.00',
        'payment_date' => now()->toDateString(),
    ]);

    // A second workspace whose data must NOT bleed in.
    $otherWs = Workspace::factory()->create();
    $otherPatient = Patient::factory()->create(['workspace_id' => $otherWs->id]);
    Appointment::factory()->create([
        'workspace_id'     => $otherWs->id,
        'patient_id'       => $otherPatient->id,
        'appointment_date' => now(),
    ]);
    $otherInvoice = Invoice::factory()->create(['workspace_id' => $otherWs->id, 'patient_id' => $otherPatient->id, 'total' => '999.00']);
    Payment::factory()->create([
        'workspace_id' => $otherWs->id,
        'invoice_id'   => $otherInvoice->id,
        'amount'       => '999.00',
        'payment_date' => now()->toDateString(),
    ]);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/dashboard/stats', dashHeaders($ws->id))
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'appointments_today', 'appointments_pending', 'patients_total',
            'month_revenue', 'pending_collections',
            'todays_appointments', 'recent_invoices',
        ]])
        ->json('data');

    expect($data['appointments_today'])->toBe(1)
        ->and($data['patients_total'])->toBe(1)               // only our workspace's patient
        ->and($data['month_revenue'])->toBeString()->toBe('200.00') // foreign 999 excluded
        ->and($data['pending_collections'])->toBeString();
});

// ─────────────────────────────────────────────
// Revenue report
// ─────────────────────────────────────────────

it('returns the revenue report shape scoped to the active workspace with money as strings', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'total'        => '300.00',
    ]);
    Payment::factory()->create([
        'workspace_id' => $ws->id,
        'invoice_id'   => $invoice->id,
        'amount'       => '120.00',
        'payment_date' => now()->toDateString(),
    ]);

    // Foreign data that must be excluded.
    $otherWs = Workspace::factory()->create();
    $otherPatient = Patient::factory()->create(['workspace_id' => $otherWs->id]);
    $otherInvoice = Invoice::factory()->create(['workspace_id' => $otherWs->id, 'patient_id' => $otherPatient->id, 'total' => '5000.00']);
    Payment::factory()->create([
        'workspace_id' => $otherWs->id,
        'invoice_id'   => $otherInvoice->id,
        'amount'       => '5000.00',
        'payment_date' => now()->toDateString(),
    ]);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/reports/revenue', dashHeaders($ws->id))
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'monthly'   => [['month', 'label', 'total']],
            'by_status' => [['status', 'count', 'total']],
            'billed_total', 'collected_total', 'outstanding_total',
        ]])
        ->json('data');

    expect($data['billed_total'])->toBeString()->toBe('300.00')      // foreign 5000 excluded
        ->and($data['collected_total'])->toBeString()->toBe('120.00')
        ->and($data['outstanding_total'])->toBeString()->toBe('180.00')
        ->and($data['monthly'][0]['total'])->toBeString();
});

it('returns the enriched analytics blocks (appointments, top services) scoped to the workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'total'        => '100.00',
        'amount_paid'  => '40.00',
        'status'       => 'partial',
    ]);
    $invoice->items()->create([
        'description' => 'Consulta de especialidad',
        'quantity'    => '1',
        'unit_price'  => '100.00',
        'line_total'  => '100.00',
    ]);

    Appointment::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patient->id, 'status' => 'completed', 'appointment_date' => now()]);
    Appointment::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patient->id, 'status' => 'cancelled', 'appointment_date' => now()]);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/reports/revenue', dashHeaders($ws->id))
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'appointments' => ['completed', 'cancelled', 'scheduled'],
            'top_services' => [['description', 'count', 'total']],
            'monthly'      => [['billed']],
        ]])
        ->json('data');

    // The aging concept was removed with the "overdue" status; outstanding
    // balance still surfaces via outstanding_total, tested above.
    expect($data)->not->toHaveKey('aging')
        ->and($data['appointments']['completed'])->toBe(1)
        ->and($data['appointments']['cancelled'])->toBe(1)
        ->and($data['top_services'][0]['description'])->toBe('Consulta de especialidad')
        ->and($data['top_services'][0]['total'])->toBeString();
});
