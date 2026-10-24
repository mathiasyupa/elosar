<?php

use App\Models\Doctor;
use App\Support\LetterheadData;
use App\Support\PrescriptionLayout;

function rxItems(int $count): array
{
    return array_map(fn (int $i) => [
        'name' => "MEDICAMENTO $i 500 mg", 'brand' => "Genérico $i",
        'ind' => '1 tableta (VO), cada 8 horas, por 7 días', 'extra' => 'Tomar después de las comidas.', 'ctrl' => false,
    ], range(1, $count));
}

it('prints a short receta in medium type, never large, filling the sheet', function () {
    $layout = PrescriptionLayout::choose(rxItems(2), ['Alergias: No refiere'], 1, true, 53, 274, true);

    expect($layout['density'])->toBe('medium')
        ->and($layout['fill'])->toBeTrue()
        ->and($layout['top'])->toBeLessThan(274.0);
});

it('steps the type down as medications are added, never back up', function () {
    $rank = ['large' => 0, 'medium' => 1, 'compact' => 2, 'tight' => 3];
    $densities = collect([2, 4, 6, 8, 10])->map(
        fn (int $n) => $rank[PrescriptionLayout::choose(rxItems($n), ['Alergias: No refiere'], 1, false, 40, 187, true)['density']],
    );

    expect($densities->all())->toBe($densities->sort()->values()->all());
});

it('lets a receta longer than one sheet flow instead of forcing a fixed height', function () {
    expect(PrescriptionLayout::choose(rxItems(30), [], 1, true, 53, 274, false))
        ->toBe(['density' => 'tight', 'fill' => false, 'top' => null]);
});

it('signs every document as «Dr.», keeping a single title', function () {
    $name = fn (string $n) => LetterheadData::doctorName(new Doctor(['name' => $n]));

    expect($name('Andrés Salazar'))->toBe('Dr. Andrés Salazar')
        ->and($name('Dr. Andrés Salazar'))->toBe('Dr. Andrés Salazar')
        ->and($name('Dra. Valeria Ortiz'))->toBe('Dr. Valeria Ortiz')
        ->and($name('MD Pedro Vera'))->toBe('Dr. Pedro Vera');
});

it('signs as «Dra.» once the doctor chose it', function () {
    $doctor = new Doctor(['name' => 'Dr. Ana Torres']);
    $doctor->honorific = 'Dra.';

    expect(LetterheadData::doctorName($doctor))->toBe('Dra. Ana Torres');
});
