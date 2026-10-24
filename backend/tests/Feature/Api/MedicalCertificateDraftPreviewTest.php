<?php

use App\Models\MedicalCertificate;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
| The certificate being written, as a real PDF beside the form, never saved.
*/

it('renders the draft as a PDF without saving anything', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    foreach ([
        ['type' => MedicalCertificate::TYPE_REST, 'title' => 'Certificado de reposo', 'body' => 'Reposo por 3 días.', 'rest_days' => 3],
        ['type' => 'medical', 'title' => 'Certificado médico', 'diagnosis' => 'Amigdalitis aguda', 'attended_at' => now()->toDateString()],
    ] as $draft) {
        $response = $this->post('/api/v1/patients/'.$patient->id.'/certificates/preview', $draft, ['X-Workspace-Id' => (string) $ws->id, 'Accept' => 'application/json']);
        $response->assertOk();
        expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }

    expect(MedicalCertificate::query()->count())->toBe(0);
});

it('hides another clinic\'s patient', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    [, , $other] = makeDoctorWithWorkspace();
    $stranger = Patient::factory()->create(['workspace_id' => $other->id]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients/'.$stranger->id.'/certificates/preview', [
        'type' => MedicalCertificate::TYPE_REST, 'title' => 'Reposo', 'body' => 'x',
    ], ['X-Workspace-Id' => (string) $ws->id])->assertNotFound();
});
