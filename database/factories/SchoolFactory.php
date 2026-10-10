<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\School>
 */
class SchoolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' School',
            'email' => fake()->companyEmail(),
            'address' => fake()->streetAddress(),
            'zipcode' => fake()->postcode(),
            'city' => fake()->city(),
            'country' => fake()->country(),
            'logo' => null,
            'access' => true,
        ];
    }

    /** École suspendue par le super-admin (SchoolContext bloque son équipe). */
    public function suspended(string $reason = 'Suspendue pour test'): static
    {
        return $this->state(fn () => ['access' => false, 'suspended_at' => now(), 'suspension_reason' => $reason]);
    }
}
