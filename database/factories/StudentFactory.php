<?php

namespace Database\Factories;

use App\Models\CourseLevel;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
        ];
    }

    /**
     * Gives the student levels: the ones passed, or one of a new track.
     *
     * @param  array<CourseLevel|int>|null  $levels
     */
    public function withLevels(?array $levels = null): static
    {
        return $this->afterCreating(function (Student $student) use ($levels) {
            $levels ??= [CourseLevel::factory()->create(['business_id' => $student->business_id])];
            $student->syncCourseLevels(array_map(fn ($l) => $l instanceof CourseLevel ? $l->id : $l, $levels));
        });
    }
}
