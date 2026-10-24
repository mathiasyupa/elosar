<?php

namespace Database\Seeders;

use App\Models\Medication;
use Illuminate\Database\Seeder;

/**
 * Global outpatient medication catalog for Ecuador (Spanish patient-facing labels
 * are composed at prescription time from structured defaults).
 */
class MedicationSeeder extends Seeder
{
    public function run(): void
    {
        $medications = [
            ['name' => 'Paracetamol', 'concentration' => '500mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 5, 'route' => 'oral', 'is_controlled' => false, 'default_instructions' => 'Tomar después de las comidas.'],
            ['name' => 'Ibuprofeno', 'concentration' => '400mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 5, 'route' => 'oral', 'is_controlled' => false, 'default_instructions' => 'Tomar con alimentos.'],
            ['name' => 'Amoxicilina', 'concentration' => '500mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'capsule', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 7, 'route' => 'oral', 'is_controlled' => false, 'default_instructions' => 'Completar el tratamiento aunque mejoren los síntomas.'],
            ['name' => 'Amoxicilina + Ácido Clavulánico', 'concentration' => '875/125mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 7, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Azitromicina', 'concentration' => '500mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 3, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Cefalexina', 'concentration' => '500mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'capsule', 'default_frequency' => 'every_6_hours', 'default_duration_days' => 7, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Metronidazol', 'concentration' => '500mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 7, 'route' => 'oral', 'is_controlled' => false, 'default_instructions' => 'Evitar alcohol durante el tratamiento.'],
            ['name' => 'Omeprazol', 'concentration' => '20mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'capsule', 'default_frequency' => 'once_daily', 'default_duration_days' => 14, 'route' => 'oral', 'is_controlled' => false, 'default_instructions' => 'Tomar en ayunas.'],
            ['name' => 'Ranitidina', 'concentration' => '150mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 14, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Losartán', 'concentration' => '50mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Enalapril', 'concentration' => '10mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Amlodipino', 'concentration' => '5mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Metformina', 'concentration' => '850mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false, 'default_instructions' => 'Tomar con las comidas.'],
            ['name' => 'Glibenclamida', 'concentration' => '5mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Atorvastatina', 'concentration' => '40mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'at_bedtime', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Simvastatina', 'concentration' => '20mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'at_bedtime', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Loratadina', 'concentration' => '10mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 7, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Cetirizina', 'concentration' => '10mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 7, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Dexametasona', 'concentration' => '4mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 3, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Prednisone', 'concentration' => '20mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 5, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Salbutamol inhalador', 'concentration' => '100mcg', 'default_dose_quantity' => 2, 'default_dose_unit' => 'puff', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 7, 'route' => 'inhaled', 'is_controlled' => false, 'default_instructions' => 'Agitar antes de usar.'],
            ['name' => 'Budesonida inhalador', 'concentration' => '200mcg', 'default_dose_quantity' => 2, 'default_dose_unit' => 'puff', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 30, 'route' => 'inhaled', 'is_controlled' => false],
            ['name' => 'Diclofenaco', 'concentration' => '50mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 5, 'route' => 'oral', 'is_controlled' => false, 'default_instructions' => 'Tomar con alimentos.'],
            ['name' => 'Naproxeno', 'concentration' => '250mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 5, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Tramadol', 'concentration' => '50mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'capsule', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 5, 'route' => 'oral', 'is_controlled' => true],
            ['name' => 'Sertralina', 'concentration' => '50mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => true, 'default_instructions' => 'Tomar siempre a la misma hora.'],
            ['name' => 'Clonazepam', 'concentration' => '0.5mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'at_bedtime', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => true],
            ['name' => 'Levotiroxina', 'concentration' => '50mcg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false, 'default_instructions' => 'Tomar en ayunas, 30 min antes del desayuno.'],
            ['name' => 'Hierro sulfato', 'concentration' => '325mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Ácido fólico', 'concentration' => '5mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Suero oral', 'concentration' => '500ml', 'default_dose_quantity' => 1, 'default_dose_unit' => 'ml', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 3, 'route' => 'oral', 'is_controlled' => false, 'default_instructions' => 'Sorber lentamente durante el día.'],
            ['name' => 'Loperamida', 'concentration' => '2mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'capsule', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 3, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Ondansetrón', 'concentration' => '8mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 3, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Metoclopramida', 'concentration' => '10mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 3, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Aciclovir', 'concentration' => '400mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 7, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Fluconazol', 'concentration' => '150mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'capsule', 'default_frequency' => 'once_daily', 'default_duration_days' => 1, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Nistatina suspensión', 'concentration' => '100000 UI/ml', 'default_dose_quantity' => 5, 'default_dose_unit' => 'ml', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 7, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Hidrocortisona crema', 'concentration' => '1%', 'default_dose_quantity' => 1, 'default_dose_unit' => 'other', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 7, 'route' => 'topical', 'is_controlled' => false, 'default_instructions' => 'Aplicar capa fina en la zona afectada.'],
            ['name' => 'Clotrimazol crema', 'concentration' => '1%', 'default_dose_quantity' => 1, 'default_dose_unit' => 'other', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 7, 'route' => 'topical', 'is_controlled' => false],
            ['name' => 'Gentamicina gotas óticas', 'concentration' => '0.3%', 'default_dose_quantity' => 3, 'default_dose_unit' => 'drop', 'default_frequency' => 'every_8_hours', 'default_duration_days' => 7, 'route' => 'other', 'is_controlled' => false],
            ['name' => 'Carbamazepina', 'concentration' => '200mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => true],
            ['name' => 'Amitriptilina', 'concentration' => '25mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'at_bedtime', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => true],
            ['name' => 'Warfarina', 'concentration' => '5mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => true],
            ['name' => 'Furosemida', 'concentration' => '40mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 7, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Espironolactona', 'concentration' => '25mg', 'default_dose_quantity' => 1, 'default_dose_unit' => 'tablet', 'default_frequency' => 'once_daily', 'default_duration_days' => 30, 'route' => 'oral', 'is_controlled' => false],
            ['name' => 'Insulina NPH', 'concentration' => '100 UI/ml', 'default_dose_quantity' => 1, 'default_dose_unit' => 'other', 'default_frequency' => 'every_12_hours', 'default_duration_days' => 30, 'route' => 'sc', 'is_controlled' => false, 'default_instructions' => 'Rotar sitio de aplicación.'],
        ];

        foreach ($medications as $i => $med) {
            Medication::query()->updateOrCreate(
                ['workspace_id' => null, 'name' => $med['name'], 'concentration' => $med['concentration'] ?? null],
                array_merge($med, ['workspace_id' => null, 'sort_order' => $i, 'is_active' => true]),
            );
        }
    }
}
