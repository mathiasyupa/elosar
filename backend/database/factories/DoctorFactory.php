<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doctor>
 *
 * NOTE: When creating a Doctor without workspace_id, the `booted()` model event
 * fires automatically and creates a personal Workspace. This is expected behavior.
 * Use ->withoutModelEvents() in the test if it needs to be avoided.
 */
class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    public function definition(): array
    {
        return [
            'name' => fake('es_ES')->name(),
            'specialty_id' => Specialty::factory(),
            'id_number' => fake()->numerify('##########'),
            'city' => fake('es_ES')->city(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'medical_license' => fake()->numerify('MED-#####'),
            'signature_path' => null,
            'workspace_id' => null, // filled automatically by the booted() event
            'user_id' => null,
        ];
    }

    /**
     * Doctor with a linked authentication user.
     * Needed for tests that require actingAs().
     * Auth::user()->doctor resolves via HasOne(user_id) — only update doctor.user_id.
     */
    /**
     * Link the owning account as a creation attribute, not afterCreating:
     * Doctor::booted() provisions the personal workspace and its entitlement
     * from `user_id` while the model is being created, so a link applied
     * afterwards would leave that workspace ownerless and read-only.
     */
    public function withUser(?User $user = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => ($user ?? User::factory()->create())->id,
        ]);
    }
}
