<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StudentAvailability>
 */
class StudentAvailabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startHour = fake()->numberBetween(8, 16);
        $startMinute = fake()->randomElement(['00', '15', '30', '45']);
        $endHour = $startHour + fake()->numberBetween(1, 4);
        $endMinute = fake()->randomElement(['00', '15', '30', '45']);

        return [
            'student_id' => Student::factory(),
            'day_of_week' => fake()->numberBetween(0, 6), // 0=Monday, 6=Sunday
            'start_time' => sprintf('%02d:%s', $startHour, $startMinute),
            'end_time' => sprintf('%02d:%s', min($endHour, 20), $endMinute),
        ];
    }
}
