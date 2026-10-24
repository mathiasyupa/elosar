<?php

namespace Database\Factories;

use App\Models\MedicalRecord;
use App\Models\RecordPrescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordPrescription>
 *
 * Medication names and instructions are intentionally in Spanish (patient-facing
 * clinical content for the Ecuadorian market).
 */
class RecordPrescriptionFactory extends Factory
{
    protected $model = RecordPrescription::class;

    /**
     * @var list<array{name:string,concentration:string,controlled:bool}>
     */
    private static array $medications = [
        ['name' => 'Paracetamol',                    'concentration' => '500mg',     'controlled' => false],
        ['name' => 'Ibuprofeno',                     'concentration' => '400mg',     'controlled' => false],
        ['name' => 'Amoxicilina',                    'concentration' => '500mg',     'controlled' => false],
        ['name' => 'Amoxicilina + Ácido Clavulánico', 'concentration' => '875/125mg', 'controlled' => false],
        ['name' => 'Losartán',                       'concentration' => '50mg',      'controlled' => false],
        ['name' => 'Metformina',                     'concentration' => '850mg',     'controlled' => false],
        ['name' => 'Atorvastatina',                  'concentration' => '40mg',      'controlled' => false],
        ['name' => 'Omeprazol',                      'concentration' => '20mg',      'controlled' => false],
        ['name' => 'Loratadina',                     'concentration' => '10mg',      'controlled' => false],
        ['name' => 'Sertralina',                     'concentration' => '50mg',      'controlled' => true],
        ['name' => 'Clonazepam',                     'concentration' => '0.5mg',     'controlled' => true],
        ['name' => 'Salbutamol inhalador',           'concentration' => '100mcg',    'controlled' => false],
    ];

    /**
     * Intakes per day for each frequency the factory emits, so the dispensing
     * quantity below is arithmetic rather than a random number that contradicts
     * the printed posology.
     *
     * @var array<string, int>
     */
    private const DOSES_PER_DAY = [
        'cada 6 horas'   => 4,
        'cada 8 horas'   => 3,
        'cada 12 horas'  => 2,
        'cada 24 horas'  => 1,
        'una vez al día' => 1,
    ];

    public function definition(): array
    {
        $med = fake()->randomElement(self::$medications);

        $dosage       = fake()->randomElement(['1 tableta', '2 tabletas', '5ml', '10ml', '1 cápsula']);
        $frequency    = fake()->randomElement(array_keys(self::DOSES_PER_DAY));
        $durationDays = fake()->randomElement([3, 5, 7, 10, 14, 30]);

        // Units per intake — works for every form the factory emits: tablets and
        // capsules come out as a count, millilitres as a total volume.
        $perIntake = (int) $dosage;

        return [
            'medical_record_id' => MedicalRecord::factory(),
            'medication_name'   => $med['name'],
            'concentration'     => $med['concentration'],
            'dosage'            => $dosage,
            'route'             => fake()->randomElement(['oral', 'sublingual', 'topical', 'inhaled', 'im']),
            'frequency'         => $frequency,
            'duration_days'     => $durationDays,
            'quantity'          => $perIntake * self::DOSES_PER_DAY[$frequency] * $durationDays,
            'instructions'      => fake()->optional(0.5)->randomElement([
                'Tomar después de las comidas.',
                'No suspender sin indicación médica.',
                'Tomar con abundante agua.',
                'Administrar en la noche.',
            ]),
            'is_controlled'     => $med['controlled'],
        ];
    }

    public function controlled(): static
    {
        return $this->state(['is_controlled' => true]);
    }
}
