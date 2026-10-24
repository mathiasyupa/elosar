<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MedicalService;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/**
 * "¿Cuánto entró por laboratorio?" — the question top_services cannot answer,
 * because it groups by the line's free-text description.
 *
 * The figure is BILLED, never collected: payments land on the invoice, not on
 * its lines, so apportioning a partial payment would be invention.
 */
function pnlByCategory(array $headers): array
{
    return test()->getJson('/api/v1/reports/pnl', $headers)
        ->assertOk()
        ->json('data.services_by_category');
}

function categoryRow(array $breakdown, ?string $category): ?array
{
    foreach ($breakdown['rows'] as $row) {
        if ($row['category'] === $category) {
            return $row;
        }
    }

    return null;
}

it('breaks billed revenue down by the arancel category', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $lab = MedicalService::factory()->create([
        'workspace_id' => $ws->id, 'name' => 'Biometría', 'category' => 'laboratory', 'base_price' => '20.00',
    ]);
    $consult = MedicalService::factory()->create([
        'workspace_id' => $ws->id, 'name' => 'Consulta', 'category' => 'general', 'base_price' => '30.00',
    ]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'status' => 'paid',
        'issued_at' => now(),
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'medical_service_id' => $lab->id,
        'description' => 'Biometría', 'quantity' => 2, 'unit_price' => '20.00', 'line_total' => '40.00',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'medical_service_id' => $consult->id,
        'description' => 'Consulta', 'quantity' => 1, 'unit_price' => '30.00', 'line_total' => '30.00',
    ]);

    Sanctum::actingAs($user);
    $breakdown = pnlByCategory(['X-Workspace-Id' => (string) $ws->id]);

    expect($breakdown['total'])->toBe('70.00');

    $laboratory = categoryRow($breakdown, 'laboratory');
    expect($laboratory['total'])->toBe('40.00')
        ->and($laboratory['count'])->toBe(1)
        // 40 of 70 = 57.1 %
        ->and($laboratory['share'])->toBe('57.1');

    expect(categoryRow($breakdown, 'general')['total'])->toBe('30.00');
});

it('shows hand-typed lines as uncategorised instead of hiding them', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id, 'patient_id' => $patient->id,
        'status' => 'paid', 'issued_at' => now(),
    ]);

    // No medical_service_id: typed straight into the invoice.
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'medical_service_id' => null,
        'description' => 'Algo suelto', 'quantity' => 1, 'unit_price' => '15.00', 'line_total' => '15.00',
    ]);

    Sanctum::actingAs($user);
    $breakdown = pnlByCategory(['X-Workspace-Id' => (string) $ws->id]);

    // A big bucket here IS the finding — the catalogue is not being used.
    expect(categoryRow($breakdown, null))->not->toBeNull()
        ->and(categoryRow($breakdown, null)['total'])->toBe('15.00')
        ->and($breakdown['total'])->toBe('15.00');
});

it('leaves a voided invoice out of the breakdown', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $service = MedicalService::factory()->create([
        'workspace_id' => $ws->id, 'category' => 'laboratory', 'base_price' => '20.00',
    ]);

    $void = Invoice::factory()->create([
        'workspace_id' => $ws->id, 'patient_id' => $patient->id,
        'status' => 'void', 'issued_at' => now(),
    ]);
    InvoiceItem::create([
        'invoice_id' => $void->id, 'medical_service_id' => $service->id,
        'description' => 'Anulada', 'quantity' => 1, 'unit_price' => '20.00', 'line_total' => '20.00',
    ]);

    Sanctum::actingAs($user);

    expect(pnlByCategory(['X-Workspace-Id' => (string) $ws->id])['total'])->toBe('0.00');
});

it('never mixes the breakdown with another clinic', function () {
    [$doctorA, $userA, $wsA] = makeDoctorWithWorkspace();
    [$doctorB, $userB, $wsB] = makeDoctorWithWorkspace();

    $patientA = Patient::factory()->create(['workspace_id' => $wsA->id]);
    $serviceA = MedicalService::factory()->create([
        'workspace_id' => $wsA->id, 'category' => 'imaging', 'base_price' => '50.00',
    ]);
    $invoiceA = Invoice::factory()->create([
        'workspace_id' => $wsA->id, 'patient_id' => $patientA->id,
        'status' => 'paid', 'issued_at' => now(),
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoiceA->id, 'medical_service_id' => $serviceA->id,
        'description' => 'Ecografía', 'quantity' => 1, 'unit_price' => '50.00', 'line_total' => '50.00',
    ]);

    Sanctum::actingAs($userB);

    expect(pnlByCategory(['X-Workspace-Id' => (string) $wsB->id])['total'])->toBe('0.00');
});
