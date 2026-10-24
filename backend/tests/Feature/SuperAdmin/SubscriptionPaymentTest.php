<?php

use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\ClinicService;
use App\Services\WorkspaceEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

function paymentData(array $overrides = []): array
{
    return array_merge([
        'amount' => '21.00',
        'method' => SubscriptionPayment::METHOD_TRANSFER,
        'paid_at' => today(),
        'period_start' => today(),
        'period_end' => today()->addMonth(),
        'reference' => 'TRF-0001',
        'note' => 'Transferencia recibida.',
    ], $overrides);
}

it('records what a clinic paid without touching the clinical ledger', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $operator = User::factory()->create();

    $payment = app(WorkspaceEntitlementService::class)
        ->recordPayment($workspace, paymentData(), $operator, extendAccess: false);

    expect($payment->amount)->toBe('21.00')
        ->and($payment->recorded_by_user_id)->toBe($operator->id)
        ->and($workspace->fresh()->paidUntil()->toDateString())
        ->toBe(today()->addMonth()->toDateString());

    // Elosar's money never becomes a patient invoice or an SRI voucher.
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('payments', 0);
});

it('extends access to the end of the paid period, audited like any other action', function () {
    [, , $workspace] = makeDoctorWithWorkspace();
    $operator = User::factory()->create();
    $periodEnd = today()->addMonth();

    app(WorkspaceEntitlementService::class)
        ->recordPayment($workspace, paymentData(['period_end' => $periodEnd]), $operator);

    $entitlement = $workspace->fresh()->entitlement;

    expect($entitlement->state)->toBe(WorkspaceEntitlement::STATE_ACTIVE)
        ->and($entitlement->access_ends_at->toDateString())->toBe($periodEnd->toDateString());

    $this->assertDatabaseHas('workspace_entitlement_events', [
        'workspace_id' => $workspace->id,
        'action' => 'operator_set_active',
        'actor_user_id' => $operator->id,
    ]);
});

it('reads paid until from the furthest period, not the latest payment', function () {
    [, , $workspace] = makeDoctorWithWorkspace();
    $service = app(WorkspaceEntitlementService::class);

    // Three months paid up front, then a correction registered afterwards for
    // a shorter period. The account is still covered for three months.
    $service->recordPayment($workspace, paymentData([
        'period_end' => today()->addMonths(3),
    ]), null, extendAccess: false);

    $service->recordPayment($workspace, paymentData([
        'paid_at' => today()->addDay(),
        'period_end' => today()->addMonth(),
    ]), null, extendAccess: false);

    expect($workspace->fresh()->paidUntil()->toDateString())
        ->toBe(today()->addMonths(3)->toDateString());
});

it('calls an account overdue only once it actually stopped paying', function () {
    [, , $neverPaid] = makeDoctorWithWorkspace();
    [, , $lapsed] = makeDoctorWithWorkspace();
    [, , $current] = makeDoctorWithWorkspace();

    $service = app(WorkspaceEntitlementService::class);

    $service->recordPayment($lapsed, paymentData([
        'period_start' => today()->subMonths(2),
        'period_end' => today()->subDay(),
    ]), null, extendAccess: false);

    $service->recordPayment($current, paymentData([
        'period_end' => today()->addDays(10),
    ]), null, extendAccess: false);

    // An account that never paid is on trial or under review — a different
    // conversation, not a debt.
    expect($neverPaid->fresh()->isSubscriptionOverdue())->toBeFalse()
        ->and($lapsed->fresh()->isSubscriptionOverdue())->toBeTrue()
        ->and($current->fresh()->isSubscriptionOverdue())->toBeFalse();
});

it('prices each plan by the doctors actually attending', function () {
    [$owner] = makeDoctorWithWorkspace();
    $personal = $owner->workspace;
    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica Precio');

    expect($personal->entitlement->monthlyPriceUsd())->toBe(15)
        ->and($clinic->entitlement->monthlyPriceUsd())->toBe(21);

    // Two extra doctors beyond the two included: 21 + 2 x 7.
    $clinic->entitlement()->update(['clinical_seat_limit' => 2]);
    foreach (range(1, 3) as $i) {
        [$extra] = makeDoctorWithWorkspace();
        $clinic->entitlement()->update(['clinical_seat_limit' => 10]);
        app(ClinicService::class)->attachDoctor($clinic->fresh(), $extra, 'doctor');
    }
    $clinic->entitlement()->update(['clinical_seat_limit' => 2]);

    expect($clinic->fresh()->entitlement->monthlyPriceUsd())->toBe(21 + 2 * 7);
});

it('bills an owner with a consulting room and a clinic the bundle, not the sum', function () {
    [$owner] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica Paquete');

    $owner->workspace->entitlement()->update(['state' => WorkspaceEntitlement::STATE_ACTIVE]);
    $clinic->entitlement()->update(['state' => WorkspaceEntitlement::STATE_ACTIVE]);

    // 15 + 21 would be 36. The bundle is 30 — summing per workspace would
    // overstate revenue by 20% in exactly the case the bundle rewards.
    expect(app(WorkspaceEntitlementService::class)->monthlyRecurringRevenueUsd())->toBe(30);
});

it('leaves trials and read-only spaces out of the revenue', function () {
    [, , $trial] = makeDoctorWithWorkspace();
    [, , $readOnly] = makeDoctorWithWorkspace();
    [, , $active] = makeDoctorWithWorkspace();

    $readOnly->entitlement()->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);
    $active->entitlement()->update(['state' => WorkspaceEntitlement::STATE_ACTIVE]);

    expect($trial->fresh()->entitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
        // Only the active consulting room bills: USD 15.
        ->and(app(WorkspaceEntitlementService::class)->monthlyRecurringRevenueUsd())->toBe(15);
});

it('refuses a payment period that ends before it starts', function () {
    [, , $workspace] = makeDoctorWithWorkspace();

    expect(fn () => SubscriptionPayment::query()->create([
        'workspace_id' => $workspace->id,
        'amount' => '21.00',
        'method' => SubscriptionPayment::METHOD_TRANSFER,
        'paid_at' => today(),
        'period_start' => CarbonImmutable::parse(today()->addMonth()),
        'period_end' => CarbonImmutable::parse(today()),
    ]))->toThrow(QueryException::class);
});
