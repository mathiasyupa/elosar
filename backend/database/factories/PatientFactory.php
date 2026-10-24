<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 *
 * NOTE: Patient uses BelongsToWorkspace. In tests, workspace_id can be passed
 * directly or injected by ActiveWorkspace when a session is active.
 * For isolation tests, always pass workspace_id explicitly.
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'workspace_id'        => Workspace::factory(),
            'name'                => fake('es_ES')->name(),
            'id_number'           => $this->validEcuadorianCedula(),
            'document_type'       => 'cedula',
            'document_country'    => 'EC',
            'birth_date'          => fake()->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
            'gender'              => fake()->randomElement(['male', 'female']),
            'civil_status'        => fake()->randomElement(['single', 'married', 'divorced', 'widowed', 'common_law']),
            'city'                => fake('es_ES')->city(),
            'address'             => fake('es_ES')->streetAddress(),
            'email'               => fake()->unique()->safeEmail(),
            'phone'               => fake()->phoneNumber(),
            'personal_background' => [
                'allergies'          => 'None',
                'surgeries'          => 'None',
                'current_medication' => 'None',
                'habits'             => 'None',
            ],
        ];
    }

    private function validEcuadorianCedula(): string
    {
        $province = str_pad((string) fake()->numberBetween(1, 24), 2, '0', STR_PAD_LEFT);
        $third = fake()->numberBetween(0, 6);
        $digits = $province.$third.fake()->numerify('######');

        $coeficients = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $value = (int) $digits[$i] * $coeficients[$i];
            $sum += $value >= 10 ? $value - 9 : $value;
        }

        $checksum = (10 - ($sum % 10)) % 10;

        return $digits.$checksum;
    }

    public function withAllergies(string $allergies = null): static
    {
        return $this->state([
            'personal_background' => [
                'allergies'          => $allergies ?? fake()->sentence(),
                'surgeries'          => 'None',
                'current_medication' => 'None',
                'habits'             => 'None',
            ],
        ]);
    }
}
