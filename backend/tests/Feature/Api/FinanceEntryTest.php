<?php

use App\Models\FinanceEntry;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Finance entries (/finance-entries)
|--------------------------------------------------------------------------
| The money typed by hand: operating expenses, income with no patient invoice
| behind it, and deductions agreed with a doctor. The clinic's books belong to
| whoever owns or administers it — the cash desk collects, but never reads the
| margin (finance.view / finance.manage).
*/

function financeHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('lets the owner register an expense and lists it with its totals', function () {
    $s = makeClinicScenario();
    $headers = financeHeaders($s['clinic']->id);

    Sanctum::actingAs($s['ownerUser']);

    $this->postJson('/api/v1/finance-entries', [
        'type' => 'expense',
        'category' => 'supplies',
        'description' => 'Guantes y jeringas',
        'amount' => '120.50',
        'entry_date' => now()->toDateString(),
        'receipt_number' => '001-002-000123',
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.type', 'expense')
        ->assertJsonPath('data.amount', '120.50')
        ->assertJsonPath('data.registered_by', $s['ownerUser']->name);

    $this->postJson('/api/v1/finance-entries', [
        'type' => 'income',
        'category' => 'room_rental',
        'description' => 'Alquiler de consultorio',
        'amount' => '80.00',
        'entry_date' => now()->toDateString(),
    ], $headers)->assertCreated();

    $body = $this->getJson('/api/v1/finance-entries', $headers)->assertOk()->json();

    expect($body['data'])->toHaveCount(2)
        ->and($body['meta']['totals']['expense'])->toBe('120.50')
        ->and($body['meta']['totals']['income'])->toBe('80.00')
        ->and($body['meta']['totals']['doctor_adjustment'])->toBe('0.00');
});

it('keeps the books away from the cash desk and from an employed doctor', function () {
    $s = makeClinicScenario();
    $headers = financeHeaders($s['clinic']->id);

    // Dedicated cashier: collects money, does not read the margin.
    setMemberRoles($s, 'recUser', ['billing']);
    Sanctum::actingAs($s['recUser']);
    $this->getJson('/api/v1/finance-entries', $headers)->assertForbidden();
    $this->postJson('/api/v1/finance-entries', [
        'type' => 'expense',
        'category' => 'supplies',
        'description' => 'Insumos',
        'amount' => '10.00',
        'entry_date' => now()->toDateString(),
    ], $headers)->assertForbidden();

    // Employed doctor: clinical only.
    Sanctum::actingAs($s['memUser']);
    $this->getJson('/api/v1/finance-entries', $headers)->assertForbidden();
});

it('lets an operational admin keep the books', function () {
    $s = makeClinicScenario();
    setMemberRoles($s, 'memUser', ['doctor', 'admin']);

    Sanctum::actingAs($s['memUser']);
    $this->postJson('/api/v1/finance-entries', [
        'type' => 'expense',
        'category' => 'utilities',
        'description' => 'Luz y agua',
        'amount' => '95.00',
        'entry_date' => now()->toDateString(),
    ], financeHeaders($s['clinic']->id))->assertCreated();
});

it('only accepts a doctor deduction against a doctor of this clinic', function () {
    $s = makeClinicScenario();
    $headers = financeHeaders($s['clinic']->id);
    [$outsider] = makeDoctorWithWorkspace();

    Sanctum::actingAs($s['ownerUser']);

    $payload = [
        'type' => 'doctor_adjustment',
        'category' => 'supplies',
        'description' => 'Insumos consumidos',
        'amount' => '25.00',
        'entry_date' => now()->toDateString(),
    ];

    // No doctor at all, and a doctor from another clinic: both rejected.
    $this->postJson('/api/v1/finance-entries', $payload, $headers)->assertStatus(422);
    $this->postJson('/api/v1/finance-entries', [...$payload, 'doctor_id' => $outsider->id], $headers)
        ->assertStatus(422);

    $this->postJson('/api/v1/finance-entries', [...$payload, 'doctor_id' => $s['memDoctor']->id], $headers)
        ->assertCreated()
        ->assertJsonPath('data.doctor_id', $s['memDoctor']->id)
        ->assertJsonPath('data.doctor_name', $s['memDoctor']->name);
});

it('edits and deletes a movement, and never shows another clinic its own', function () {
    $s = makeClinicScenario();
    $headers = financeHeaders($s['clinic']->id);

    // A movement that belongs to a different workspace entirely.
    $other = Workspace::factory()->create();
    FinanceEntry::create([
        'workspace_id' => $other->id,
        'type' => 'expense',
        'category' => 'rent',
        'description' => 'Arriendo ajeno',
        'amount' => '999.00',
        'entry_date' => now()->toDateString(),
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $id = $this->postJson('/api/v1/finance-entries', [
        'type' => 'expense',
        'category' => 'maintenance',
        'description' => 'Reparación del ecógrafo',
        'amount' => '150.00',
        'entry_date' => now()->toDateString(),
    ], $headers)->assertCreated()->json('data.id');

    $listed = $this->getJson('/api/v1/finance-entries', $headers)->assertOk()->json('data');
    expect($listed)->toHaveCount(1)
        ->and($listed[0]['description'])->toBe('Reparación del ecógrafo');

    $this->putJson("/api/v1/finance-entries/{$id}", [
        'type' => 'expense',
        'category' => 'maintenance',
        'description' => 'Reparación del ecógrafo (2 sesiones)',
        'amount' => '180.00',
        'entry_date' => now()->toDateString(),
    ], $headers)->assertOk()->assertJsonPath('data.amount', '180.00');

    $this->deleteJson("/api/v1/finance-entries/{$id}", [], $headers)->assertOk();

    expect($this->getJson('/api/v1/finance-entries', $headers)->assertOk()->json('data'))->toBeEmpty();
    // Soft-deleted, not gone: money is auditable.
    expect(FinanceEntry::withoutGlobalScopes()->withTrashed()->whereKey($id)->first()->deleted_at)->not->toBeNull();
});

it('filters movements by type and date', function () {
    $s = makeClinicScenario();
    $headers = financeHeaders($s['clinic']->id);

    Sanctum::actingAs($s['ownerUser']);

    $this->postJson('/api/v1/finance-entries', [
        'type' => 'expense', 'category' => 'supplies', 'description' => 'Este mes',
        'amount' => '10.00', 'entry_date' => now()->startOfMonth()->toDateString(),
    ], $headers)->assertCreated();

    $this->postJson('/api/v1/finance-entries', [
        'type' => 'expense', 'category' => 'supplies', 'description' => 'Mes pasado',
        'amount' => '20.00', 'entry_date' => now()->subMonth()->startOfMonth()->toDateString(),
    ], $headers)->assertCreated();

    $this->postJson('/api/v1/finance-entries', [
        'type' => 'income', 'category' => 'room_rental', 'description' => 'Alquiler',
        'amount' => '30.00', 'entry_date' => now()->startOfMonth()->toDateString(),
    ], $headers)->assertCreated();

    $thisMonth = $this->getJson('/api/v1/finance-entries?type=expense&from='
        .now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString(), $headers)
        ->assertOk()->json();

    expect($thisMonth['data'])->toHaveCount(1)
        ->and($thisMonth['data'][0]['description'])->toBe('Este mes');
});
