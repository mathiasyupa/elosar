<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Support\ActiveWorkspace;
use Laravel\Sanctum\Sanctum;

/**
 * Who may read what each doctor earns.
 *
 * The roster already treats this as compensation data: ClinicController::team()
 * blanks `commission_pct` for anyone without team.manage, so a cashier sees the
 * team list but not what colleagues are paid.
 *
 * The settlement report is the same fact by another door — it names every
 * doctor, their percentage and their take-home — and it is gated by
 * reports.view, which the `billing` role holds.
 *
 * These tests state the CURRENT behaviour so the audit rests on evidence rather
 * than on reading. They are the starting point of a decision, not the decision.
 */
function settlementScenario(): array
{
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    app(ActiveWorkspace::class)->bind((int) $clinic->id);

    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);
    $clinic->members()->where('doctor_id', $s['memDoctor']->id)->update(['commission_pct' => '60.00']);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'attending_doctor_id' => $s['memDoctor']->id,
        'status' => 'paid',
        'issued_at' => now(),
        'total' => '100.00',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'medical_service_id' => null,
        'description' => 'Consulta', 'quantity' => 1, 'unit_price' => '100.00', 'line_total' => '100.00',
    ]);
    $invoice->payments()->create([
        'workspace_id' => $clinic->id, 'amount' => '100.00',
        'payment_method' => 'cash', 'payment_date' => now(),
    ]);

    return $s;
}

it('hides what a colleague earns from the team roster', function () {
    $s = settlementScenario();

    Sanctum::actingAs($s['recUser']);

    $team = $this->getJson('/api/v1/clinic/team', ['X-Workspace-Id' => (string) $s['clinic']->id])
        ->assertOk()
        ->json('data');

    // Deliberate: compensation is not front-desk data.
    foreach ($team as $member) {
        expect($member['commission_pct'])->toBeNull();
    }
})->group('security');

it('gives the cash desk what was collected but not what the doctor keeps', function () {
    $s = settlementScenario();

    // The dedicated cashier role: money, no clinical access, no finance.view.
    setMemberRoles($s, 'recUser', ['billing']);

    Sanctum::actingAs($s['recUser']);

    $data = $this->getJson('/api/v1/reports/settlement', ['X-Workspace-Id' => (string) $s['clinic']->id])
        ->assertOk()
        ->json('data');

    $doctorRow = collect($data['by_doctor'])->firstWhere('doctor_id', $s['memDoctor']->id);

    // Collected per doctor stays — reconciling the drawer against who attended
    // is the cashier's actual job.
    expect($doctorRow['total'])->toBe('100.00')
        ->and($data['shows_compensation'])->toBeFalse()
        // What the doctor is paid does not. Same rule the roster already applies.
        ->and($doctorRow['commission_pct'])->toBeNull()
        ->and($doctorRow['doctor_amount'])->toBeNull()
        ->and($doctorRow['doctor_net'])->toBeNull()
        ->and($data['totals']['doctor_net'])->toBeNull();

    // The per-attention detail must not leak it either.
    foreach ($data['detail'] as $row) {
        expect($row['doctor_amount'])->toBeNull();
    }
})->group('security');

it('gives compensation to whoever reads the clinic books', function () {
    $s = settlementScenario();

    // The owner holds finance.view — doctor commission is the largest component
    // of the margin they are responsible for.
    Sanctum::actingAs($s['ownerUser']);

    $data = $this->getJson('/api/v1/reports/settlement', ['X-Workspace-Id' => (string) $s['clinic']->id])
        ->assertOk()
        ->json('data');

    $doctorRow = collect($data['by_doctor'])->firstWhere('doctor_id', $s['memDoctor']->id);

    expect($data['shows_compensation'])->toBeTrue()
        ->and($doctorRow['doctor_amount'])->toBe('60.00');
})->group('security');

it('keeps an employed doctor to their own numbers', function () {
    $s = settlementScenario();

    // A clinical-only member: no ownership, no management role.
    Sanctum::actingAs($s['memUser']);

    $data = $this->getJson('/api/v1/reports/settlement', ['X-Workspace-Id' => (string) $s['clinic']->id])
        ->assertOk()
        ->json('data');

    // This part already worked: a colleague's figures are not theirs.
    expect(collect($data['by_doctor'])->pluck('doctor_id')->unique()->all())->toBe([$s['memDoctor']->id]);

    // And they keep their OWN compensation — an employed doctor without
    // finance.view must still be able to check what they are owed.
    expect($data['shows_compensation'])->toBeTrue()
        ->and(collect($data['by_doctor'])->first()['doctor_amount'])->toBe('60.00');
})->group('security');
