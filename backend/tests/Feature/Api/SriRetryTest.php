<?php

use App\Jobs\AuthorizeSriVoucherJob;
use App\Models\Invoice;
use App\Models\Patient;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| SRI retry endpoint
|--------------------------------------------------------------------------
| Lets the doctor re-queue a rejected (or stuck) invoice for SRI
| authorization from the app. SriService::resend() owns the state reset and
| the idempotency guard (never re-sends an already-authorized voucher) — the
| controller only wires the request to it.
*/

it('re-queues a rejected invoice for SRI authorization', function () {
    Queue::fake();

    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id'             => $ws->id,
        'patient_id'               => $patient->id,
        'sri_status'               => 'rejected',
        'sri_submission_attempts'  => 4,
        'sri_error'                => ['source' => 'sri', 'code' => '56', 'message' => 'ERROR ESTABLECIMIENTO CERRADO', 'info' => null],
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/invoices/{$invoice->id}/sri/retry", [], ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonPath('data.sri_status', 'pending');

    $invoice->refresh();
    expect($invoice->sri_status)->toBe('pending')
        ->and($invoice->sri_submission_attempts)->toBe(0)
        ->and($invoice->sri_error)->toBeNull();

    Queue::assertPushed(AuthorizeSriVoucherJob::class);
});

it('rejects re-sending an already authorized invoice', function () {
    Queue::fake();

    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'sri_status'   => 'authorized',
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/invoices/{$invoice->id}/sri/retry", [], ['X-Workspace-Id' => (string) $ws->id])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('tells the emitter the invoice is annulled, not that it was authorized', function (string $status) {
    Queue::fake();

    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'sri_status'   => 'rejected',
    ]);
    $invoice->forceFill(['status' => $status])->save();

    Sanctum::actingAs($user);

    // The two refusals resend() can give must not share one message: telling
    // someone their voided invoice "was already authorized" sends them looking
    // for an authorization that does not exist.
    $this->postJson("/api/v1/invoices/{$invoice->id}/sri/retry", [], ['X-Workspace-Id' => (string) $ws->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.sri.0', 'Esta factura está anulada; no se puede reenviar al SRI.');

    Queue::assertNothingPushed();
})->with(['void', 'cancelled']);

it('forbids retrying SRI without billing.manage', function () {
    Queue::fake();

    $s = makeClinicScenario();
    setMemberRoles($s, 'nurseUser', ['nurse']);
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id'   => $patient->id,
        'sri_status'   => 'rejected',
    ]);

    Sanctum::actingAs($s['nurseUser']);

    $this->postJson("/api/v1/invoices/{$invoice->id}/sri/retry", [], ['X-Workspace-Id' => (string) $s['clinic']->id])
        ->assertStatus(403);

    Queue::assertNothingPushed();
});
