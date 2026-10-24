<?php

use Illuminate\Support\Facades\Schema;

/**
 * Confirms the workspace-led composite indexes exist after migration. Guards
 * against an accidental drop or a renamed column slipping the index away.
 */
function indexNames(string $table): array
{
    return collect(Schema::getIndexes($table))
        ->pluck('name')
        ->all();
}

it('creates the workspace-led composite indexes', function () {
    expect(indexNames('appointments'))
        ->toContain('appointments_workspace_id_appointment_date_index')
        ->toContain('appointments_workspace_id_patient_id_index');

    expect(indexNames('medical_records'))
        ->toContain('medical_records_workspace_id_patient_id_index');

    expect(indexNames('patients'))
        ->toContain('patients_workspace_id_id_number_index');
});
