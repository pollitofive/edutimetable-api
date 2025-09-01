<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'       => 'English ' . strtoupper($this->faker->randomLetter()) . ' ' . $this->faker->randomDigit(),
            'level'      => $this->faker->randomElement(['A1','A2','B1','B2','C1','C2']),
            'year'       => (int) now()->year,
            'teacher_id' => Teacher::factory(),
        ];
    }
}
