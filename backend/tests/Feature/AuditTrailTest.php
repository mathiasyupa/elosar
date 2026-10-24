<?php

use App\Models\AccessLog;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Workspace;
use App\Support\AccessLogger;
use App\Support\ActiveWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Write-audit via PostgreSQL trigger (migration 2026_06_15_000003) and
 * read-access logging (App\Support\AccessLogger).
 *
 * The `app.audit_user_id` GUC is session-scoped (not transactional), so reset
 * it before each test to keep attribution assertions deterministic.
 */
beforeEach(function () {
    DB::statement("select set_config('app.audit_user_id', '', false)");
});

it('captures an INSERT into a protected table', function () {
    $ws = Workspace::factory()->create();
    app(ActiveWorkspace::class)->bind($ws->id);

    $patient = Patient::create(minimalPatientData(['workspace_id' => $ws->id]));

    $entry = AuditLog::where('table_name', 'patients')
        ->where('record_id', $patient->id)
        ->where('operation', 'INSERT')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_data['name'])->toBe($patient->name)
        ->and($entry->old_data)->toBeNull();
});

it('captures an UPDATE with old and new snapshots', function () {
    $ws = Workspace::factory()->create();
    app(ActiveWorkspace::class)->bind($ws->id);

    $patient = Patient::create(minimalPatientData(['workspace_id' => $ws->id, 'name' => 'Old Name']));
    $patient->update(['name' => 'New Name']);

    $entry = AuditLog::where('table_name', 'patients')
        ->where('record_id', $patient->id)
        ->where('operation', 'UPDATE')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->old_data['name'])->toBe('Old Name')
        ->and($entry->new_data['name'])->toBe('New Name');
});

it('captures writes that bypass Eloquent entirely', function () {
    $ws = Workspace::factory()->create();
    app(ActiveWorkspace::class)->bind($ws->id);

    $patient = Patient::create(minimalPatientData(['workspace_id' => $ws->id]));

    // Raw query-builder update: no model events fire — only the DB trigger sees it.
    DB::table('patients')->where('id', $patient->id)->update(['name' => 'Raw Update']);

    $entry = AuditLog::where('table_name', 'patients')
        ->where('record_id', $patient->id)
        ->where('operation', 'UPDATE')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_data['name'])->toBe('Raw Update');
});

it('attributes the change to the app user when set', function () {
    DB::statement("select set_config('app.audit_user_id', '4242', false)");

    $ws = Workspace::factory()->create();
    app(ActiveWorkspace::class)->bind($ws->id);

    $patient = Patient::create(minimalPatientData(['workspace_id' => $ws->id]));

    $entry = AuditLog::where('table_name', 'patients')
        ->where('record_id', $patient->id)
        ->first();

    expect($entry->user_id)->toBe(4242);
});

it('strips the bulky sri_xml column from captured snapshots', function () {
    $ws      = Workspace::factory()->create();
    $patient = Patient::withoutGlobalScopes()->create(minimalPatientData(['workspace_id' => $ws->id]));
    app(ActiveWorkspace::class)->bind($ws->id);

    $invoice = Invoice::create([
        'workspace_id'   => $ws->id,
        'patient_id'     => $patient->id,
        'invoice_number' => 'F-0001',
        'issued_at'      => now()->toDateString(),
        'status'         => 'issued',
        'subtotal'       => '100.00',
        'iva_amount'     => '15.00',
        'total'          => '115.00',
        'amount_paid'    => '0.00',
        'sri_xml'        => '<factura>...huge signed payload...</factura>',
    ]);

    $entry = AuditLog::where('table_name', 'invoices')
        ->where('record_id', $invoice->id)
        ->where('operation', 'INSERT')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_data)->not->toHaveKey('sri_xml')
        ->and($entry->new_data['invoice_number'])->toBe('F-0001');
});

it('logs read access to a patient file', function () {
    $ws = Workspace::factory()->create();
    app(ActiveWorkspace::class)->bind($ws->id);

    $patient = Patient::create(minimalPatientData(['workspace_id' => $ws->id]));

    app(AccessLogger::class)->log('patient', $patient->id);

    $log = AccessLog::where('subject_type', 'patient')
        ->where('subject_id', $patient->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('view')
        ->and($log->workspace_id)->toBe($ws->id);
});
