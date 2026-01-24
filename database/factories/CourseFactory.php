<?php

namespace Database\Factories;

use App\Models\Course;
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
        $subjects = ['English', 'Math', 'Science', 'History', 'Geography', 'Art', 'Music', 'Physics', 'Chemistry', 'Biology'];
        $level = $this->faker->randomElement(['A1', 'A2', 'B1', 'B2', 'C1', 'C2']);

        return [
            'name' => $this->faker->randomElement($subjects).' '.$level.' '.strtoupper($this->faker->unique()->bothify('?##')),
            'level' => $level,
            'year' => (int) now()->year,
            // teacher_id REMOVED - teachers are now assigned via schedules
        ];
    }
}
