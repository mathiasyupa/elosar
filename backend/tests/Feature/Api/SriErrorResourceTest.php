<?php

use App\Models\Invoice;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| SRI error surfaced on InvoiceResource
|--------------------------------------------------------------------------
| The doctor app needs to show WHY the SRI rejected a voucher and how long a
| `pending` one can sit before it's stuck. `sri_error` (curated by SriError)
| is safe to expose; `sri_last_error` (raw, may contain exception text) must
| never leak into the API response.
*/

it('returns the curated SRI error, the fiscal number and the stale threshold', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $invoice = Invoice::factory()->create([
        'workspace_id'        => $ws->id,
        'patient_id'          => $patient->id,
        'sri_status'          => 'rejected',
        'sri_sequence_number' => '001-001-000000042',
        'sri_last_error'      => '56 ERROR ESTABLECIMIENTO CERRADO',
        'sri_error'           => ['source' => 'sri', 'code' => '56', 'message' => 'ERROR ESTABLECIMIENTO CERRADO', 'info' => 'El establecimiento 001 está cerrado'],
        'sri_last_attempt_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/invoices/{$invoice->id}", ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonPath('data.sri_error.code', '56')
        ->assertJsonPath('data.sri_error.source', 'sri')
        ->assertJsonPath('data.sri_stale_after_seconds', 900)
        ->assertJsonPath('data.sri_sequence_number', '001-001-000000042')
        ->assertJsonMissingPath('data.sri_last_error');
});
