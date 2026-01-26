<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CourseLevel>
 */
class CourseLevelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tracks = ['English', 'Portuguese', 'Spanish', 'French'];
        $levels = [
            ['name' => 'Beginner', 'slug' => 'beginner', 'sort_order' => 10],
            ['name' => 'Pre-Intermediate', 'slug' => 'pre-intermediate', 'sort_order' => 20],
            ['name' => 'Intermediate', 'slug' => 'intermediate', 'sort_order' => 30],
            ['name' => 'Upper-Intermediate', 'slug' => 'upper-intermediate', 'sort_order' => 40],
            ['name' => 'Advanced', 'slug' => 'advanced', 'sort_order' => 50],
        ];

        $level = $this->faker->randomElement($levels);
        $uniqueSuffix = $this->faker->unique()->numerify('###');

        return [
            'track' => $this->faker->randomElement($tracks),
            'name' => $level['name'],
            'slug' => $level['slug'].'-'.$uniqueSuffix,
            'sort_order' => $level['sort_order'],
            'next_level_id' => null,
        ];
    }
}
