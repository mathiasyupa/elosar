<?php

use App\Models\Cie10Code;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\seed;

it('finds multiple CIE-10 codes by description without accents', function () {
    seed(\Database\Seeders\Cie10Seeder::class);

    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    $response = $this->getJson('/api/v1/cie10?q=SINDROME', $h)->assertOk();

    $codes = collect($response->json('data'))->pluck('code');

    expect($codes)->toContain('K58.9')
        ->and($codes->count())->toBeGreaterThan(1)
        ->and($response->json('meta.total'))->toBeGreaterThan(1);
});

it('lists CIE-10 codes by category without a search term', function () {
    seed(\Database\Seeders\Cie10Seeder::class);

    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    $this->getJson('/api/v1/cie10?category=Síndromes', $h)
        ->assertOk()
        ->assertJsonFragment(['category' => 'Síndromes'])
        ->assertJsonPath('meta.total', fn ($total) => $total >= 10);
});

it('returns distinct CIE-10 categories', function () {
    seed(\Database\Seeders\Cie10Seeder::class);

    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cie10/categories', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonFragment(['Síndromes'])
        ->assertJsonFragment(['Respiratorio']);
});

it('finds CIE-10 codes by partial code', function () {
    Cie10Code::create([
        'code'        => 'E11.9',
        'description' => 'Diabetes mellitus tipo 2 sin complicaciones',
        'category'    => 'Endocrino',
    ]);

    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cie10?q=diabetes', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonFragment(['code' => 'E11.9']);
});

it('returns empty results when query is too short and no category', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cie10?q=J', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
