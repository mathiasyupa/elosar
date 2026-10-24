<?php

use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
| A birth date has to be possible. A typo such as 1087 for 1987 was stored as is
| and then printed "939 años" on every list and document.
*/

function birthHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('rejects a birth date before 1900 or in the future when creating a patient', function (string $date, string $message) {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients', minimalPatientData(['birth_date' => $date]), birthHeaders($ws->id))
        ->assertUnprocessable()
        ->assertJsonPath('errors.birth_date.0', $message);
})->with([
    'a typo in the year' => ['1087-05-12', 'La fecha de nacimiento no puede ser anterior a 1900. Revisa el año.'],
    'tomorrow' => [fn () => now()->addDay()->toDateString(), 'La fecha de nacimiento no puede ser posterior a hoy.'],
]);

it('still accepts a newborn and a very old patient', function (string $date) {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients', minimalPatientData(['birth_date' => $date]), birthHeaders($ws->id))
        ->assertCreated();
})->with([
    'born today' => [fn () => now()->toDateString()],
    'born in 1920' => ['1920-03-01'],
]);

it('rejects an impossible birth date when editing a patient', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $this->putJson('/api/v1/patients/'.$patient->id, ['birth_date' => '1087-05-12'], birthHeaders($ws->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('birth_date');
});

it('refuses the import and names the row with an impossible birth date', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/patients/import', [
        'rows' => [[
            'name' => 'Evelyn Ejemplo',
            'id_number' => validEcuadorianCedula(),
            'birth_date' => '1087-05-12',
            'gender' => 'female',
            'phone' => '0987654321',
        ]],
    ], birthHeaders($ws->id))->assertUnprocessable()->json('data');

    // The import is all or nothing: one bad row and nothing is saved.
    expect($response['created'])->toBe(0)
        ->and($response['errors'])->toBe(1)
        ->and($response['rows'][0]['message'])->toContain('anterior a 1900')
        ->and(Patient::query()->where('name', 'Evelyn Ejemplo')->exists())->toBeFalse();
});
