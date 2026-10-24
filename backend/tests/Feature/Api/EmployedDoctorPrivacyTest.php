<?php

use App\Models\Appointment;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Employed-doctor privacy (clinical-only member of a clinic)
|--------------------------------------------------------------------------
| A plain doctor member (role 'doctor', not owner, no management role) must not
| see the clinic's administrative/financial data beyond their own attentions:
| no invite code, no clinic-wide revenue, no colleagues' credit notes or
| billables, and no colleagues' commission percentages.
*/

function privacyHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** One paid invoice per doctor: $100 owner / $40 member. */
function seedPrivacyRevenue(array $s): array
{
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $invoices = [];
    foreach ([['ownerDoctor', '100.00'], ['memDoctor', '40.00']] as [$key, $amount]) {
        $invoice = Invoice::factory()->create([
            'workspace_id'        => $s['clinic']->id,
            'patient_id'          => $patient->id,
            'attending_doctor_id' => $s[$key]->id,
            'total'               => $amount,
        ]);
        Payment::factory()->create([
            'workspace_id' => $s['clinic']->id,
            'invoice_id'   => $invoice->id,
            'amount'       => $amount,
            'payment_date' => now()->toDateString(),
        ]);
        $invoices[$key] = $invoice;
    }

    return $invoices;
}

// ── Invite code ───────────────────────────────────────────────────────────

it('hides the clinic invite code from an employed doctor in settings', function () {
    $s = makeClinicScenario();

    Sanctum::actingAs($s['memUser']);
    $data = $this->getJson('/api/v1/settings', privacyHeaders($s['clinic']->id))
        ->assertOk()->json('data');

    expect($data)->not->toHaveKey('invite_code')
        ->and($data['is_owner'])->toBeFalse();
});

it('shows the owner the invite code in settings', function () {
    $s = makeClinicScenario();

    Sanctum::actingAs($s['ownerUser']);
    $data = $this->getJson('/api/v1/settings', privacyHeaders($s['clinic']->id))
        ->assertOk()->json('data');

    expect($data['invite_code'])->toBe($s['clinic']->invite_code)
        ->and($data['is_owner'])->toBeTrue();
});

// ── Dashboard financial snapshot ──────────────────────────────────────────

it('shows an employed doctor only their own money on the clinic dashboard', function () {
    $s = makeClinicScenario();
    seedPrivacyRevenue($s);

    Sanctum::actingAs($s['memUser']);
    $data = $this->getJson('/api/v1/dashboard/stats', privacyHeaders($s['clinic']->id))
        ->assertOk()->json('data');

    expect($data['billing']['scope'])->toBe('own')
        ->and($data['billing']['month_revenue'])->toBe('40.00');
});

it('shows the owner the whole clinic money on the dashboard', function () {
    $s = makeClinicScenario();
    seedPrivacyRevenue($s);

    Sanctum::actingAs($s['ownerUser']);
    $data = $this->getJson('/api/v1/dashboard/stats', privacyHeaders($s['clinic']->id))
        ->assertOk()->json('data');

    expect($data['billing']['scope'])->toBe('clinic')
        ->and($data['billing']['month_revenue'])->toBe('140.00');
});

// ── Credit notes ──────────────────────────────────────────────────────────

it('hides a colleague credit note from an employed doctor', function () {
    $s = makeClinicScenario();
    $invoices = seedPrivacyRevenue($s);

    $foreign = CreditNote::create([
        'workspace_id'       => $s['clinic']->id,
        'invoice_id'         => $invoices['ownerDoctor']->id,
        'credit_note_number' => 'NC-0001',
        'issued_at'          => now(),
        'reason'             => 'Anulación',
        'amount'             => '100.00',
        'status'             => 'issued',
        'created_by'         => $s['ownerDoctor']->id,
    ]);
    $own = CreditNote::create([
        'workspace_id'       => $s['clinic']->id,
        'invoice_id'         => $invoices['memDoctor']->id,
        'credit_note_number' => 'NC-0002',
        'issued_at'          => now(),
        'reason'             => 'Anulación',
        'amount'             => '40.00',
        'status'             => 'issued',
        'created_by'         => $s['memDoctor']->id,
    ]);

    Sanctum::actingAs($s['memUser']);
    $ids = collect(
        $this->getJson('/api/v1/credit-notes', privacyHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($own->id)
        ->not->toContain($foreign->id);

    // The owner keeps seeing both.
    Sanctum::actingAs($s['ownerUser']);
    $ids = collect(
        $this->getJson('/api/v1/credit-notes', privacyHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($own->id)->toContain($foreign->id);
});

// ── Billables ("por cobrar") ──────────────────────────────────────────────

it('shows an employed doctor only their own billable attentions', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $mine = Appointment::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id'   => $patient->id,
        'doctor_id'    => $s['memDoctor']->id,
        'status'       => 'completed',
    ]);
    $others = Appointment::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id'   => $patient->id,
        'doctor_id'    => $s['ownerDoctor']->id,
        'status'       => 'completed',
    ]);

    Sanctum::actingAs($s['memUser']);
    $ids = collect(
        $this->getJson('/api/v1/clinic/billables', privacyHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    )->pluck('appointment_id');

    expect($ids)->toContain($mine->id)
        ->not->toContain($others->id);

    // The owner keeps the whole "por cobrar" list.
    Sanctum::actingAs($s['ownerUser']);
    $ids = collect(
        $this->getJson('/api/v1/clinic/billables', privacyHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    )->pluck('appointment_id');

    expect($ids)->toContain($mine->id)->toContain($others->id);
});

// ── Team roster commissions ───────────────────────────────────────────────

it('hides commission percentages from members without team.manage', function () {
    $s = makeClinicScenario();
    $s['clinic']->members()
        ->where('user_id', $s['memUser']->id)
        ->update(['commission_pct' => '35.00']);

    // The employed doctor sees the roster but no compensation data.
    Sanctum::actingAs($s['memUser']);
    $roster = collect(
        $this->getJson('/api/v1/clinic/team', privacyHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    );

    expect($roster->pluck('commission_pct')->filter()->all())->toBe([]);

    // The owner (team.manage) sees the percentages.
    Sanctum::actingAs($s['ownerUser']);
    $roster = collect(
        $this->getJson('/api/v1/clinic/team', privacyHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    );

    $memberRow = $roster->firstWhere('doctor_id', $s['memDoctor']->id);
    expect((string) $memberRow['commission_pct'])->toBe('35.00');
});
