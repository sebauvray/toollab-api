<?php

namespace Database\Factories;

use Carbon\Carbon;
use App\Models\School;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Classroom>
 */
class ClassroomFactory extends Factory
{
    private const CLASSROOM_TYPES = [
        'Man',
        'Women',
        'Children'
    ];
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grade = fake()->numberBetween(1, 12);
        $section = chr(fake()->numberBetween(65, 70));

        return [
            'name' => $grade . $section,
            'years' => Carbon::now()->year,
            'type' => fake()->randomElement(self::CLASSROOM_TYPES),
            'size' => fake()->numberBetween(5,25),
            'school_id' => School::factory()
        ];
    }

    /**
    * Associates the class with a specific school
    */
    public function forSchool(School $school): static
    {
        return $this->state(fn (array $attributes) => [
            'school_id' => $school->id
        ]);
    }
    /**
    * Configures the class for a specific year
    */
    public function forYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'years' => $year
        ]);
    }
}
