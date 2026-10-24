<?php

use App\Models\Cie10Code;
use Laravel\Sanctum\Sanctum;

/*
| Guards the bundled full CIE-10 catalog (database/data/cie10.csv.gz, the
| official DGIS/CEMECE Spanish edition). Verifies the shipped file decompresses,
| imports the full catalog with dotified codes, and that clinical search returns
| many real results — the whole point of shipping the catalog.
*/

function importBundledCie10Catalog(): void
{
    $gzPath = database_path('data/cie10.csv.gz');
    expect(is_file($gzPath))->toBeTrue('Bundled catalog database/data/cie10.csv.gz is missing.');

    $csvPath = tempnam(sys_get_temp_dir(), 'cie10_bundled_').'.csv';
    $in = gzopen($gzPath, 'rb');
    $out = fopen($csvPath, 'wb');
    while (! gzeof($in)) {
        fwrite($out, gzread($in, 1 << 20));
    }
    gzclose($in);
    fclose($out);

    test()->artisan('cie10:import', ['file' => $csvPath, '--truncate' => true, '--dotify' => true])
        ->assertExitCode(0);

    @unlink($csvPath);
}

it('imports the full bundled CIE-10 catalog with dotified codes', function () {
    importBundledCie10Catalog();

    // The official catalog has ~14k codes; assert it loaded far beyond the
    // curated subset and that 4-char codes were dotified (A030 -> A03.0).
    expect(Cie10Code::count())->toBeGreaterThan(10000)
        ->and(Cie10Code::where('code', 'A03.0')->exists())->toBeTrue()
        ->and(Cie10Code::where('code', 'E11.9')->exists())->toBeTrue();
});

it('serves dozens of CIE-10 results for a real clinical search', function () {
    importBundledCie10Catalog();

    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    // "SINDROME" (no accent) must reach the accented "SÍNDROME" descriptions
    // via unaccent and return many hits — not the single result we had before.
    $response = $this->getJson('/api/v1/cie10?q=SINDROME', $h)->assertOk();

    expect($response->json('meta.total'))->toBeGreaterThan(50)
        ->and(count($response->json('data')))->toBeGreaterThan(10);
});
