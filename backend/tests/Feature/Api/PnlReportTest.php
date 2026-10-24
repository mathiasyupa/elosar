<?php

use App\Models\FinanceEntry;
use App\Models\Patient;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Clinic P&L (/reports/pnl)
|--------------------------------------------------------------------------
| cobrado + otros ingresos − comisiones a médicos − gastos = utilidad neta.
| Gated by finance.view: the owner and the operational admin read the margin of
| the business; the cash desk and an employed doctor never do.
*/

function pnlHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('adds up income, commissions and expenses into the net profit', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = pnlHeaders($clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    $member = $clinic->members()->where('doctor_id', $s['memDoctor']->id)->firstOrFail();
    $member->update(['commission_pct' => 60]);

    // $400 collected this month for a doctor on 60% → $240 of commission.
    seedAttention($clinic, $patient, $s['memDoctor']->id, '400.00', now()->toDateString());

    FinanceEntry::create([
        'workspace_id' => $clinic->id,
        'type' => FinanceEntry::TYPE_INCOME,
        'category' => 'room_rental',
        'description' => 'Alquiler de consultorio',
        'amount' => '100.00',
        'entry_date' => now()->toDateString(),
    ]);

    FinanceEntry::create([
        'workspace_id' => $clinic->id,
        'type' => FinanceEntry::TYPE_EXPENSE,
        'category' => 'supplies',
        'description' => 'Insumos del mes',
        'amount' => '150.00',
        'entry_date' => now()->toDateString(),
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $data = $this->getJson('/api/v1/reports/pnl', $headers)->assertOk()->json('data');

    // 400 + 100 − 240 − 150 = 110, on 500 of income → 22% margin.
    expect($data['collected'])->toBe('400.00')
        ->and($data['other_income'])->toBe('100.00')
        ->and($data['income_total'])->toBe('500.00')
        ->and($data['doctor_commissions'])->toBe('240.00')
        ->and($data['expenses'])->toBe('150.00')
        ->and($data['net_profit'])->toBe('110.00')
        ->and($data['net_margin_pct'])->toBe('22.0');

    // The six-month trend ends in the selected period.
    expect($data['series'])->toHaveCount(6)
        ->and(collect($data['series'])->last()['month'])->toBe(now()->format('Y-m'))
        ->and(collect($data['series'])->last()['net_profit'])->toBe('110.00');
});

it('counts a doctor deduction as money that stays with the clinic', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $headers = pnlHeaders($clinic->id);
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    $member = $clinic->members()->where('doctor_id', $s['memDoctor']->id)->firstOrFail();
    $member->update(['commission_pct' => 50]);

    seedAttention($clinic, $patient, $s['memDoctor']->id, '200.00', now()->toDateString());

    FinanceEntry::create([
        'workspace_id' => $clinic->id,
        'type' => FinanceEntry::TYPE_DOCTOR_ADJUSTMENT,
        'category' => 'advance',
        'description' => 'Adelanto entregado',
        'amount' => '40.00',
        'entry_date' => now()->toDateString(),
        'doctor_id' => $s['memDoctor']->id,
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $data = $this->getJson('/api/v1/reports/pnl', $headers)->assertOk()->json('data');

    // The doctor's 100 drops to 60, so the clinic's net rises by the same 40.
    expect($data['doctor_commissions'])->toBe('60.00')
        ->and($data['doctor_adjustments'])->toBe('40.00')
        ->and($data['net_profit'])->toBe('140.00');
});

it('keeps the margin away from the cash desk and from an employed doctor', function () {
    $s = makeClinicScenario();
    $headers = pnlHeaders($s['clinic']->id);

    setMemberRoles($s, 'recUser', ['billing']);
    Sanctum::actingAs($s['recUser']);
    $this->getJson('/api/v1/reports/pnl', $headers)->assertForbidden();

    Sanctum::actingAs($s['memUser']);
    $this->getJson('/api/v1/reports/pnl', $headers)->assertForbidden();

    // The operational admin does read it.
    setMemberRoles($s, 'memUser', ['doctor', 'admin']);
    Sanctum::actingAs($s['memUser']);
    $this->getJson('/api/v1/reports/pnl', $headers)->assertOk();
});

it('never counts another clinic expenses or income', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    seedAttention($s['clinic'], $patient, null, '90.00', now()->toDateString());

    $other = Workspace::factory()->create();
    FinanceEntry::create([
        'workspace_id' => $other->id,
        'type' => FinanceEntry::TYPE_EXPENSE,
        'category' => 'rent',
        'description' => 'Arriendo de otra clínica',
        'amount' => '5000.00',
        'entry_date' => now()->toDateString(),
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $data = $this->getJson('/api/v1/reports/pnl', pnlHeaders($s['clinic']->id))->assertOk()->json('data');

    expect($data['expenses'])->toBe('0.00')
        ->and($data['net_profit'])->toBe('90.00');
});

it('breaks expenses down by category for the period', function () {
    $s = makeClinicScenario();
    $headers = pnlHeaders($s['clinic']->id);

    foreach ([['supplies', '30.00'], ['supplies', '20.00'], ['utilities', '70.00']] as [$category, $amount]) {
        FinanceEntry::create([
            'workspace_id' => $s['clinic']->id,
            'type' => FinanceEntry::TYPE_EXPENSE,
            'category' => $category,
            'description' => 'Gasto',
            'amount' => $amount,
            'entry_date' => now()->toDateString(),
        ]);
    }

    // Last month's expense must not land in this month's breakdown.
    FinanceEntry::create([
        'workspace_id' => $s['clinic']->id,
        'type' => FinanceEntry::TYPE_EXPENSE,
        'category' => 'rent',
        'description' => 'Arriendo del mes pasado',
        'amount' => '900.00',
        'entry_date' => now()->subMonth()->startOfMonth()->toDateString(),
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $byCategory = collect(
        $this->getJson('/api/v1/reports/pnl', $headers)->assertOk()->json('data.expenses_by_category')
    );

    expect($byCategory)->toHaveCount(2)
        // Biggest first.
        ->and($byCategory->first()['category'])->toBe('utilities')
        ->and($byCategory->first()['total'])->toBe('70.00')
        ->and($byCategory->firstWhere('category', 'supplies')['count'])->toBe(2)
        ->and($byCategory->firstWhere('category', 'supplies')['total'])->toBe('50.00');
});
