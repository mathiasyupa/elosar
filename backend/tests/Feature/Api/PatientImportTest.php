<?php

use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

function importHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('imports patients in bulk with relaxed contact validation', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $payload = [
        'rows' => [
            [
                'name' => 'María Pérez',
                'id_number' => validEcuadorianCedula(),
                'document_type' => 'cedula',
                'document_country' => 'EC',
                'birth_date' => '1990-05-15',
                'gender' => null,
                'city' => 'Ibarra',
                'email' => null,
                'phone' => '0991234567',
            ],
            [
                'name' => 'Carlos Ruiz',
                'id_number' => '26526641',
                'document_type' => 'foreign_id',
                'document_country' => 'VE',
                'birth_date' => '1985-10-20',
                'gender' => 'male',
                'city' => null,
                'email' => null,
                'phone' => '0987654321',
            ],
        ],
    ];

    $response = $this->postJson('/api/v1/patients/import', $payload, importHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($response['created'])->toBe(2)
        ->and($response['errors'])->toBe(0);

    $this->assertDatabaseHas('patients', [
        'name' => 'María Pérez',
        'document_type' => 'cedula',
        'document_country' => 'EC',
        'workspace_id' => $ws->id,
        'gender' => null,
        'address' => null,
    ]);

    $this->assertDatabaseHas('patients', [
        'name' => 'Carlos Ruiz',
        'document_type' => 'foreign_id',
        'document_country' => 'VE',
        'workspace_id' => $ws->id,
    ]);
});

it('imports a decoded and trimmed address', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients/import', [
        'rows' => [[
            'name' => 'Lucía Arteaga',
            'id_number' => validEcuadorianCedula(),
            'document_type' => 'cedula',
            'document_country' => 'EC',
            'birth_date' => '1990-05-15',
            'address' => '  Av. Eloy Alfaro &amp; N34-120  ',
        ]],
    ], importHeaders($workspace->id))
        ->assertOk()
        ->assertJsonPath('data.created', 1);

    $this->assertDatabaseHas('patients', [
        'workspace_id' => $workspace->id,
        'name' => 'Lucía Arteaga',
        'address' => 'Av. Eloy Alfaro & N34-120',
    ]);
});

it('ignores city and workspace ID supplied in an import row', function () {
    [, , $foreignWorkspace] = makeDoctorWithWorkspace();
    [, $user, $workspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients/import', [
        'rows' => [[
            'name' => 'Paciente Aislado',
            'id_number' => validEcuadorianCedula(),
            'document_type' => 'cedula',
            'document_country' => 'EC',
            'birth_date' => '1990-05-15',
            'address' => 'Av. República E7-21',
            'city' => str_repeat('Q', 121),
            'workspace_id' => $foreignWorkspace->id,
        ]],
    ], importHeaders($workspace->id))
        ->assertOk()
        ->assertJsonPath('data.created', 1);

    $this->assertDatabaseHas('patients', [
        'workspace_id' => $workspace->id,
        'name' => 'Paciente Aislado',
        'address' => 'Av. República E7-21',
        'city' => null,
    ]);
    $this->assertDatabaseMissing('patients', [
        'workspace_id' => $foreignWorkspace->id,
        'name' => 'Paciente Aislado',
    ]);
});

it('accepts historical rows without an address', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients/import', [
        'rows' => [[
            'name' => 'Paciente Histórico',
            'id_number' => validEcuadorianCedula(),
            'document_type' => 'cedula',
            'document_country' => 'EC',
            'birth_date' => '1990-05-15',
            'city' => 'Ibarra',
        ]],
    ], importHeaders($workspace->id))
        ->assertOk()
        ->assertJsonPath('data.created', 1);

    $this->assertDatabaseHas('patients', [
        'workspace_id' => $workspace->id,
        'name' => 'Paciente Histórico',
        'address' => null,
        'city' => null,
    ]);
});

it('rejects an address longer than 255 characters', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients/import', [
        'rows' => [[
            'name' => 'Dirección Larga',
            'id_number' => validEcuadorianCedula(),
            'document_type' => 'cedula',
            'document_country' => 'EC',
            'birth_date' => '1990-05-15',
            'address' => str_repeat('A', 256),
        ]],
    ], importHeaders($workspace->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rows.0.address');

    $this->assertDatabaseMissing('patients', ['name' => 'Dirección Larga']);
});

it('reports duplicate patients within the same workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $existing = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'id_number' => '26526641',
        'document_type' => 'foreign_id',
        'document_country' => 'VE',
    ]);

    Sanctum::actingAs($user);

    $payload = [
        'rows' => [
            [
                'name' => 'Carlos Ruiz',
                'id_number' => $existing->id_number,
                'document_type' => 'foreign_id',
                'document_country' => 'VE',
                'birth_date' => '1985-10-20',
                'gender' => 'male',
                'phone' => '0987654321',
            ],
        ],
    ];

    $response = $this->postJson('/api/v1/patients/import', $payload, importHeaders($ws->id))
        ->assertUnprocessable()
        ->json('data');

    expect($response['created'])->toBe(0)
        ->and($response['errors'])->toBe(1)
        ->and($response['committed'])->toBeFalse();

    expect($response['rows'][0]['message'])->toContain('Ya existe un paciente')
        ->and($response['rows'][0]['message'])->not->toContain('workspace');
});

it('rejects importing a document held by a soft-deleted patient in the same workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $document = validEcuadorianCedula();
    $archivedPatient = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'id_number' => $document,
        'document_type' => 'cedula',
        'document_country' => 'EC',
    ]);
    $archivedPatient->delete();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/patients/import', [
        'rows' => [[
            'name' => 'Paciente archivado',
            'id_number' => $document,
            'document_type' => 'cedula',
            'document_country' => 'EC',
            'birth_date' => '1990-05-15',
            'gender' => null,
            'city' => 'Ibarra',
            'email' => null,
            'phone' => '0991234567',
        ]],
    ], importHeaders($ws->id))
        ->assertUnprocessable()
        ->json('data');

    expect($response['committed'])->toBeFalse()
        ->and($response['errors'])->toBe(1)
        ->and($response['rows'][0]['message'])->toContain('paciente archivado');

    $this->assertSoftDeleted('patients', ['id' => $archivedPatient->id]);
    $this->assertDatabaseCount('patients', 1);
});

it('does not create any patient when a batch contains an invalid row', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/patients/import', [
        'rows' => [
            [
                'name' => 'Paciente Válido',
                'id_number' => validEcuadorianCedula(),
                'document_type' => 'cedula',
                'document_country' => 'EC',
                'birth_date' => '1990-05-15',
                'gender' => null,
                'city' => 'Ibarra',
                'email' => null,
                'phone' => '0991234567',
            ],
            [
                'name' => 'Documento Incompleto',
                'id_number' => '123456789',
                'document_type' => 'cedula',
                'document_country' => 'EC',
                'birth_date' => '1990-05-15',
                'gender' => null,
                'city' => 'Ibarra',
                'email' => null,
                'phone' => '0991234567',
            ],
        ],
    ], importHeaders($ws->id));

    $response->assertUnprocessable()
        ->assertJsonPath('data.committed', false)
        ->assertJsonPath('data.created', 0)
        ->assertJsonPath('data.errors', 1);

    $this->assertDatabaseMissing('patients', [
        'workspace_id' => $ws->id,
        'name' => 'Paciente Válido',
    ]);
});

it('does not accept an impossible birth date from a direct import request', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/patients/import', [
        'rows' => [[
            'source_line' => 2,
            'name' => 'Fecha Imposible',
            'id_number' => '1710034065',
            'document_type' => 'cedula',
            'document_country' => 'EC',
            'birth_date' => '1990-02-30',
        ]],
    ], importHeaders($ws->id));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('rows.0.birth_date');

    $this->assertDatabaseMissing('patients', ['name' => 'Fecha Imposible']);
});

it('allows the same document in a separate workspace without crossing patient records', function () {
    [, , $firstWorkspace] = makeDoctorWithWorkspace();
    $document = validEcuadorianCedula();

    Patient::factory()->create([
        'workspace_id' => $firstWorkspace->id,
        'id_number' => $document,
        'document_type' => 'cedula',
        'document_country' => 'EC',
    ]);

    [, $user, $secondWorkspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/patients/import', [
        'rows' => [[
            'name' => 'Paciente de otra clínica',
            'id_number' => $document,
            'document_type' => 'cedula',
            'document_country' => 'EC',
            'birth_date' => '1990-05-15',
            'gender' => null,
            'city' => 'Ibarra',
            'email' => null,
            'phone' => '0991234567',
        ]],
    ], importHeaders($secondWorkspace->id));

    $response->assertOk()
        ->assertJsonPath('data.committed', true)
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.errors', 0);

    $this->assertDatabaseHas('patients', [
        'workspace_id' => $secondWorkspace->id,
        'id_number' => $document,
        'document_type' => 'cedula',
        'document_country' => 'EC',
    ]);
    $this->assertDatabaseCount('patients', 2);
});

it('previews the full batch without creating patients', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/patients/import', [
        'dry_run' => true,
        'rows' => [[
            'name' => 'Paciente por revisar',
            'id_number' => validEcuadorianCedula(),
            'document_type' => 'cedula',
            'document_country' => 'EC',
            'birth_date' => '1990-05-15',
            'gender' => null,
            'city' => 'Ibarra',
            'email' => null,
            'phone' => '0991234567',
        ]],
    ], importHeaders($ws->id));

    $response->assertOk()
        ->assertJsonPath('data.committed', false)
        ->assertJsonPath('data.ready', true)
        ->assertJsonPath('data.created', 0)
        ->assertJsonPath('data.errors', 0);

    $this->assertDatabaseCount('patients', 0);
});
