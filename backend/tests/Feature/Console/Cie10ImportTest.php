<?php

use App\Models\Cie10Code;

/*
| cie10:import — imports the full CIE-10 catalog from a tabular CSV (DGIS /
| datos.gob.mx style: ';'-delimited, CATALOG_KEY/NOMBRE columns). Verifies
| auto-detection, dotify, chapter-derived category, range/blank skipping and
| idempotent upsert.
*/

function writeCie10Csv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'cie10_').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('imports a CSV, derives chapter categories and dotifies codes', function () {
    $csv = <<<CSV
    CATALOG_KEY;NOMBRE
    A000;Cólera debido a Vibrio cholerae 01, biotipo cholerae
    A00-B99;Ciertas enfermedades infecciosas (rango, no importar)
    J00;Rinofaringitis aguda (resfriado común)
    J069;Infección aguda de las vías respiratorias superiores
    ;Fila sin código
    ZZZ;Código inválido
    CSV;

    $path = writeCie10Csv($csv);

    $this->artisan('cie10:import', ['file' => $path, '--truncate' => true, '--dotify' => true])
        ->assertExitCode(0);

    // 3 valid leaf codes; the range, the blank and the invalid row are skipped.
    expect(Cie10Code::count())->toBe(3);

    $a = Cie10Code::where('code', 'A00.0')->first();
    expect($a)->not->toBeNull()
        ->and($a->category)->toBe('Ciertas enfermedades infecciosas y parasitarias');

    $j = Cie10Code::where('code', 'J06.9')->first();
    expect($j)->not->toBeNull()
        ->and($j->category)->toBe('Enfermedades del sistema respiratorio');

    expect(Cie10Code::where('code', 'J00')->exists())->toBeTrue();

    unlink($path);
});

it('is idempotent on re-import (upsert by code, no duplicates)', function () {
    $csv = "CODIGO,NOMBRE\nA000,Cólera\nJ00,Resfriado común\n";
    $path = writeCie10Csv($csv);

    $this->artisan('cie10:import', ['file' => $path, '--truncate' => true, '--dotify' => true])->assertExitCode(0);
    $first = Cie10Code::count();

    // Re-run updates descriptions in place, never duplicates.
    $this->artisan('cie10:import', ['file' => $path, '--dotify' => true])->assertExitCode(0);

    expect(Cie10Code::count())->toBe($first);
    unlink($path);
});

it('fails clearly when code/description columns cannot be resolved', function () {
    $path = writeCie10Csv("foo,bar\n1,2\n");

    $this->artisan('cie10:import', ['file' => $path])->assertExitCode(1);
    unlink($path);
});
