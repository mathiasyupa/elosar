<?php

use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

function searchNames($test, int $workspaceId, string $q): array
{
    return collect(
        $test->getJson('/api/v1/patients?q='.urlencode($q), ['X-Workspace-Id' => (string) $workspaceId])
            ->assertOk()
            ->json('data')
    )->pluck('name')->all();
}

it('finds a patient whatever order the names are typed in', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'María José Andrade Ruiz']);
    Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Pedro Vera']);
    Sanctum::actingAs($user);

    expect(searchNames($this, $ws->id, 'vera pedro'))->toBe(['Pedro Vera'])
        ->and(searchNames($this, $ws->id, 'andrade maria'))->toBe(['María José Andrade Ruiz'])
        ->and(searchNames($this, $ws->id, 'RUIZ  josé'))->toBe(['María José Andrade Ruiz']);
});

it('ignores accents and ñ, and still needs every word to match', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Pedro Núñez Peña']);
    Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Pedro Salazar']);
    Sanctum::actingAs($user);

    expect(searchNames($this, $ws->id, 'nunez pena'))->toBe(['Pedro Núñez Peña'])
        ->and(searchNames($this, $ws->id, 'pedro salazar'))->toBe(['Pedro Salazar'])
        ->and(searchNames($this, $ws->id, 'pedro gomez'))->toBe([]);
});

it('still finds a patient by part of the cédula', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Ana Vera', 'id_number' => '1712345678']);
    Sanctum::actingAs($user);

    expect(searchNames($this, $ws->id, '2345'))->toBe(['Ana Vera']);
});
