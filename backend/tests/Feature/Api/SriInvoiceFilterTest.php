<?php

use App\Models\Invoice;
use App\Models\Patient;
use App\Services\ClinicService;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| SRI control panel inside Facturación
|--------------------------------------------------------------------------
| The list can be narrowed by SRI state, and a summary answers "do I have
| anything pending?" without paging through every invoice to count it. Both
| stay inside the workspace and inside what the caller is allowed to see.
*/

function sriFilterHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/**
 * One invoice per SRI state, keyed by state. `not_sent` is the factory default
 * (NULL), which is what an invoice looks like with e-invoicing switched off.
 *
 * @return array<string, Invoice>
 */
function seedInvoicesPerSriState(int $workspaceId, ?int $doctorId = null): array
{
    $patient = Patient::factory()->create(['workspace_id' => $workspaceId]);

    $make = fn (?string $state): Invoice => Invoice::factory()->create([
        'workspace_id'        => $workspaceId,
        'patient_id'          => $patient->id,
        'attending_doctor_id' => $doctorId,
        'sri_status'          => $state,
    ]);

    return [
        'pending'    => $make('pending'),
        'rejected'   => $make('rejected'),
        'authorized' => $make('authorized'),
        'not_sent'   => $make(null),
    ];
}

/** Invoice ids returned by the list endpoint for the given query string. */
function listedInvoiceIds(object $test, int $workspaceId, string $query = ''): Illuminate\Support\Collection
{
    return collect(
        $test->getJson("/api/v1/invoices{$query}", sriFilterHeaders($workspaceId))
            ->assertOk()
            ->json('data')
    )->pluck('id');
}

it('filters the invoice list by a single SRI state', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $invoices = seedInvoicesPerSriState($ws->id);

    Sanctum::actingAs($user);
    $ids = listedInvoiceIds($this, $ws->id, '?sri_status=rejected');

    expect($ids->all())->toBe([$invoices['rejected']->id]);
});

it('accepts several SRI states at once', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $invoices = seedInvoicesPerSriState($ws->id);

    Sanctum::actingAs($user);
    $ids = listedInvoiceIds($this, $ws->id, '?sri_status=pending,authorized');

    expect($ids)->toContain($invoices['pending']->id)
        ->toContain($invoices['authorized']->id)
        ->not->toContain($invoices['rejected']->id)
        ->not->toContain($invoices['not_sent']->id);
});

it('resolves needs_attention to everything still unfinished with the SRI', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $invoices = seedInvoicesPerSriState($ws->id);

    Sanctum::actingAs($user);
    $ids = listedInvoiceIds($this, $ws->id, '?sri_status=needs_attention');

    expect($ids)->toContain($invoices['pending']->id)
        ->toContain($invoices['rejected']->id)
        // An authorized voucher is done, and one that never went to the SRI was
        // never meant to — neither is outstanding business.
        ->not->toContain($invoices['authorized']->id)
        ->not->toContain($invoices['not_sent']->id);
});

it('keeps annulled invoices out of needs_attention, where nobody could clear them', function (string $status) {
    // SriService::resend() refuses to re-arm an annulled invoice, so listing it
    // under "what must I clear?" would show work with no way to finish it.
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $states  = seedInvoicesPerSriState($ws->id);
    $annulled = $states['rejected'];
    $annulled->forceFill(['status' => $status])->save();

    $ids = listedInvoiceIds($this, $ws->id, '?sri_status=needs_attention');

    expect($ids)->toContain($states['pending']->id)
        ->and($ids)->not->toContain($annulled->id);
})->with(['void', 'cancelled']);

it('still lists an annulled invoice when its SRI state is asked for explicitly', function () {
    // Asking for `rejected` is asking for that state, annulled or not — hiding
    // it would make the invoice unreachable from this screen.
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $states = seedInvoicesPerSriState($ws->id);
    $states['rejected']->forceFill(['status' => 'void'])->save();

    expect(listedInvoiceIds($this, $ws->id, '?sri_status=rejected'))
        ->toContain($states['rejected']->id);
});

it('does not count annulled invoices as outstanding in the summary', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $states = seedInvoicesPerSriState($ws->id);
    $states['pending']->forceFill(['status' => 'void'])->save();
    $states['authorized']->forceFill(['status' => 'void'])->save();

    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($ws->id))
        ->assertOk()
        // The voided pending one stops being outstanding...
        ->assertJsonPath('pending', 0)
        ->assertJsonPath('rejected', 1)
        // ...but a comprobante the SRI accepted stays counted: it was filed.
        ->assertJsonPath('authorized', 1);
});

it('matches not_sent against invoices that never went to the SRI', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $invoices = seedInvoicesPerSriState($ws->id);

    Sanctum::actingAs($user);
    $ids = listedInvoiceIds($this, $ws->id, '?sri_status=not_sent');

    expect($ids->all())->toBe([$invoices['not_sent']->id]);
});

it('ignores an unknown SRI state instead of returning a filtered-looking list', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    seedInvoicesPerSriState($ws->id);

    Sanctum::actingAs($user);
    $ids = listedInvoiceIds($this, $ws->id, '?sri_status=inventado');

    expect($ids)->toHaveCount(4);
});

it('combines the SRI filter with the collection status filter', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $rejectedAndPaid = Invoice::factory()->paid()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'sri_status'   => 'rejected',
    ]);
    Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'sri_status'   => 'rejected',
    ]);

    Sanctum::actingAs($user);
    $ids = listedInvoiceIds($this, $ws->id, '?sri_status=rejected&status=paid');

    expect($ids->all())->toBe([$rejectedAndPaid->id]);
});

it('never lets the SRI filter reach another workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    [, , $otherWs] = makeDoctorWithWorkspace();
    $foreign = seedInvoicesPerSriState($otherWs->id);

    Sanctum::actingAs($user);
    $ids = listedInvoiceIds($this, $ws->id, '?sri_status=needs_attention');

    expect($ids)->toBeEmpty()
        ->and($ids)->not->toContain($foreign['rejected']->id);
});

it('summarises how many vouchers sit in each SRI state', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Invoice::factory()->count(3)->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'sri_status'   => 'pending',
    ]);
    Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'sri_status'   => 'rejected',
    ]);
    Invoice::factory()->count(2)->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'sri_status'   => 'authorized',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($ws->id))
        ->assertOk()
        ->assertExactJson([
            'pending'    => 3,
            'rejected'   => 1,
            'authorized' => 2,
            'not_sent'   => 0,
            // Authorized, but with no date from the SRI: the month bucket is
            // keyed off the authority's reply, so these stay out of it.
            'authorized_this_month' => 0,
        ]);
});

it('counts zero in every SRI state for a workspace with no invoices', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($ws->id))
        ->assertOk()
        ->assertExactJson([
            'pending'    => 0,
            'rejected'   => 0,
            'authorized' => 0,
            'not_sent'   => 0,
            'authorized_this_month' => 0,
        ]);
});

it('bounds the reassuring counter to the current month, not to all time', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $authorizedOn = fn (string $when): Invoice => Invoice::factory()->create([
        'workspace_id'           => $ws->id,
        'patient_id'             => $patient->id,
        'sri_status'             => 'authorized',
        'sri_authorization_date' => $when,
    ]);

    $authorizedOn(now()->startOfMonth()->toDateTimeString());          // first instant in
    $authorizedOn(now()->toDateTimeString());
    $authorizedOn(now()->startOfMonth()->subSecond()->toDateTimeString()); // last instant out
    $authorizedOn(now()->subYear()->toDateTimeString());

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($ws->id))
        ->assertOk()
        // The lifetime total keeps its meaning — the panel just stops leading
        // with a number that only grows.
        ->assertJsonPath('authorized', 4)
        ->assertJsonPath('authorized_this_month', 2);
});

it('keeps counting an annulled invoice the SRI already authorized this month', function () {
    // Voiding the invoice in Elosar does not un-file the comprobante; that is
    // what the credit note is for. Same rule as the lifetime counter.
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Invoice::factory()->create([
        'workspace_id'           => $ws->id,
        'patient_id'             => $patient->id,
        'sri_status'             => 'authorized',
        'sri_authorization_date' => now()->toDateTimeString(),
    ])->forceFill(['status' => 'void'])->save();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('authorized_this_month', 1);
});

it('does not count another workspace vouchers in the summary', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    [, , $otherWs] = makeDoctorWithWorkspace();
    seedInvoicesPerSriState($otherWs->id);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($ws->id))
        ->assertOk()
        ->assertJson(['pending' => 0, 'rejected' => 0, 'authorized' => 0, 'not_sent' => 0]);
});

it('counts only their own attentions for an employed doctor', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    Invoice::factory()->create([
        'workspace_id'        => $s['clinic']->id,
        'patient_id'          => $patient->id,
        'attending_doctor_id' => $s['memDoctor']->id,
        'sri_status'          => 'rejected',
    ]);
    Invoice::factory()->count(2)->create([
        'workspace_id'        => $s['clinic']->id,
        'patient_id'          => $patient->id,
        'attending_doctor_id' => $s['ownerDoctor']->id,
        'sri_status'          => 'rejected',
    ]);

    Sanctum::actingAs($s['memUser']);
    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($s['clinic']->id))
        ->assertOk()
        ->assertJson(['rejected' => 1]);

    // The owner sees the clinic whole.
    Sanctum::actingAs($s['ownerUser']);
    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($s['clinic']->id))
        ->assertOk()
        ->assertJson(['rejected' => 3]);
});

it('requires billing.view to read the SRI summary', function () {
    $s = makeClinicScenario();

    // Nursing has clinical access but no business with the money.
    Sanctum::actingAs($s['nurseUser']);
    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($s['clinic']->id))
        ->assertForbidden();

    $cashier = makeStaff('Caja Demo', 'caja_'.uniqid().'@clinica.test');
    app(ClinicService::class)->attachMember($s['clinic'], $cashier, 'billing');

    Sanctum::actingAs($cashier);
    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($s['clinic']->id))
        ->assertOk();
});

it('requires authentication for the SRI summary', function () {
    [, , $ws] = makeDoctorWithWorkspace();

    $this->getJson('/api/v1/invoices/sri-summary', sriFilterHeaders($ws->id))
        ->assertUnauthorized();
});
