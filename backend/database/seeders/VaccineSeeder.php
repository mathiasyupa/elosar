<?php

namespace Database\Seeders;

use App\Models\Vaccine;
use Illuminate\Database\Seeder;

/**
 * Ecuador PAI (Programa Ampliado de Inmunizaciones) vaccine catalog — the rows
 * of the vaccination card (the competitor's "Vacunas y Tratamientos" grid).
 * Names in Spanish (clinical content for the market). max_doses / max_boosters
 * drive how many dose columns the card shows per vaccine.
 */
class VaccineSeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;

        foreach ($this->catalog() as [$code, $name, $category, $doses, $boosters]) {
            Vaccine::query()->updateOrCreate(
                ['name' => $name],
                [
                    'code'         => $code,
                    'category'     => $category,
                    'max_doses'    => $doses,
                    'max_boosters' => $boosters,
                    'is_active'    => true,
                    'sort_order'   => $sort++,
                ],
            );
        }
    }

    /**
     * @return list<array{0:?string,1:string,2:string,3:int,4:int}>
     */
    private function catalog(): array
    {
        return [
            ['BCG',    'BCG - Tuberculosis',                          'PAI', 1, 0],
            ['HB',     'Hepatitis B (recién nacido)',                 'PAI', 1, 0],
            ['PENTA',  'Pentavalente (DPT + HB + Hib)',               'PAI', 3, 0],
            ['OPV',    'OPV - Polio oral',                            'PAI', 3, 2],
            ['IPV',    'IPV - Polio inactivada',                      'PAI', 2, 0],
            ['PCV',    'Neumococo conjugada (PCV)',                   'PAI', 3, 1],
            ['RV',     'Rotavirus',                                   'PAI', 2, 0],
            ['INFLU',  'Influenza',                                   'PAI', 2, 3],
            ['SRP',    'SRP (Sarampión, Rubéola, Paperas)',           'PAI', 2, 0],
            ['SR',     'SR (Sarampión, Rubéola)',                     'PAI', 1, 0],
            ['VAR',    'Varicela',                                    'PAI', 2, 0],
            ['FA',     'Fiebre Amarilla',                             'PAI', 1, 0],
            ['DPT',    'DPT - Difteria, Tos ferina, Tétanos',         'PAI', 0, 3],
            ['DTPA',   'dTpa/Tdap (embarazadas / adultos)',           'PAI', 1, 1],
            ['HPV',    'HPV - Virus del Papiloma Humano',             'PAI', 3, 0],
            ['HEPA',   'Hepatitis A',                                 'PAI', 2, 0],
            ['MCV4',   'MCV4 - Meningococo conjugada',                'No PAI', 2, 1],
            ['TD',     'Td - Difteria y Tétanos (adulto)',            'PAI', 3, 3],
            ['ANTIRR', 'Antirrábica',                                 'No PAI', 5, 0],
            ['COVID',  'COVID-19',                                    'PAI', 2, 3],
            ['ANTIOF', 'Suero antiofídico',                           'Tratamiento', 1, 0],
        ];
    }
}
