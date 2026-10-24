<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $workspace = Workspace::factory()->create();

        return [
            'workspace_id'     => $workspace->id,
            'patient_id'       => Patient::factory()->state(['workspace_id' => $workspace->id]),
            'doctor_id'        => Doctor::factory(),
            'appointment_date' => fake()->dateTimeBetween('now', '+3 months'),
            'status'           => 'scheduled',
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'appointment_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'status'           => 'completed',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }

    public function past(): static
    {
        return $this->state([
            'appointment_date' => fake()->dateTimeBetween('-3 months', '-1 day'),
        ]);
    }
}
