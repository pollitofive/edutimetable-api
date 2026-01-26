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
        $levelName = $this->faker->randomElement(['Beginner', 'Pre-Intermediate', 'Intermediate', 'Upper-Intermediate', 'Advanced']);

        return [
            'name' => $this->faker->randomElement($subjects).' '.$levelName.' '.strtoupper($this->faker->unique()->bothify('?##')),
            'course_level_id' => \App\Models\CourseLevel::factory(),
        ];
    }
}
