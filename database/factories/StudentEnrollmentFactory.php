<?php

namespace Database\Factories;

use App\Models\Schedule;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StudentEnrollment>
 */
class StudentEnrollmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'schedule_id' => Schedule::factory(),
            'enrolled_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'status' => $this->faker->randomElement(['active', 'completed', 'dropped', 'pending']),
            'notes' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    /**
     * Indicate that the enrollment is active
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the enrollment is completed
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Indicate that the enrollment is pending
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the enrollment is dropped
     */
    public function dropped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dropped',
        ]);
    }
}
