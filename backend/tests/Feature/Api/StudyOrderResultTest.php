<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\StudyOrder;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Storage;

/**
 * Closing the loop on a lab order.
 *
 * The order used to be fire-and-forget — issued, printed, never heard from
 * again — so the front desk could not answer the question it gets all day:
 * "¿ya llegó el resultado de la paciente?".
 *
 * Deliberately not structured analyte values: the result is a document, filed
 * against the order that asked for it, reusing MedicalAttachment with its
 * private disk, authenticated download and audit.
 */
function resultHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function makeOrderFor(Patient $patient, MedicalRecord $record, int $workspaceId): StudyOrder
{
    return StudyOrder::create([
        'workspace_id' => $workspaceId,
        'medical_record_id' => $record->id,
        'patient_id' => $patient->id,
        'type' => StudyOrder::TYPE_LABORATORY,
        'priority' => 'routine',
    ]);
}

it('starts every order as pending', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patient->id]);
    $order = makeOrderFor($patient, $record, (int) $ws->id);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/study-orders/{$order->id}", resultHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.result_status', 'pending')
        ->assertJsonPath('data.result_status_label', 'Pendiente')
        ->assertJsonPath('data.result_received_at', null);
});

it('stamps the arrival date when the result comes back', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patient->id]);
    $order = makeOrderFor($patient, $record, (int) $ws->id);

    Sanctum::actingAs($user);

    $data = $this->putJson("/api/v1/study-orders/{$order->id}/result", [
        'result_status' => 'completed',
        'result_notes' => 'Hemoglobina en rango',
    ], resultHeaders($ws->id))->assertOk()->json('data');

    expect($data['result_status'])->toBe('completed')
        ->and($data['result_received_at'])->not->toBeNull()
        ->and($data['result_notes'])->toBe('Hemoglobina en rango');
});

it('clears the arrival date if the order goes back to pending', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patient->id]);
    $order = makeOrderFor($patient, $record, (int) $ws->id);

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/study-orders/{$order->id}/result", ['result_status' => 'completed'], resultHeaders($ws->id))->assertOk();
    $data = $this->putJson("/api/v1/study-orders/{$order->id}/result", ['result_status' => 'pending'], resultHeaders($ws->id))
        ->assertOk()->json('data');

    // A date must never outlive the fact it refers to.
    expect($data['result_received_at'])->toBeNull();
});

it('files the uploaded document against its order and marks it received', function () {
    Storage::fake('local');

    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patient->id]);
    $order = makeOrderFor($patient, $record, (int) $ws->id);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/records/{$record->id}/attachments", [
        'file' => UploadedFile::fake()->create('resultado.pdf', 120, 'application/pdf'),
        'title' => 'Biometría',
        'study_order_id' => $order->id,
    ], resultHeaders($ws->id))->assertStatus(201)->assertJsonPath('study_order_id', $order->id);

    // Filing the result IS the news that it arrived — no second step.
    expect($order->fresh()->result_status)->toBe('completed')
        ->and($order->fresh()->result_received_at)->not->toBeNull();

    $this->getJson("/api/v1/study-orders/{$order->id}", resultHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.results.0.title', 'Biometría');
});

it('refuses to file a result under a different patient', function () {
    Storage::fake('local');

    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patientA = Patient::factory()->create(['workspace_id' => $ws->id]);
    $patientB = Patient::factory()->create(['workspace_id' => $ws->id]);
    $recordA = MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patientA->id]);
    $recordB = MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patientB->id]);
    $orderB = makeOrderFor($patientB, $recordB, (int) $ws->id);

    Sanctum::actingAs($user);

    // Same clinic, wrong chart: this is how a result ends up on the wrong person.
    $this->postJson("/api/v1/records/{$recordA->id}/attachments", [
        'file' => UploadedFile::fake()->create('resultado.pdf', 120, 'application/pdf'),
        'study_order_id' => $orderB->id,
    ], resultHeaders($ws->id))->assertStatus(422);
});

it('hides an order from another clinic', function () {
    [$doctorA, $userA, $wsA] = makeDoctorWithWorkspace();
    [$doctorB, $userB, $wsB] = makeDoctorWithWorkspace();

    $patient = Patient::factory()->create(['workspace_id' => $wsA->id]);
    $record = MedicalRecord::factory()->create(['workspace_id' => $wsA->id, 'patient_id' => $patient->id]);
    $order = makeOrderFor($patient, $record, (int) $wsA->id);

    Sanctum::actingAs($userB);

    $this->putJson("/api/v1/study-orders/{$order->id}/result", ['result_status' => 'completed'], resultHeaders($wsB->id))
        ->assertStatus(404);
});
