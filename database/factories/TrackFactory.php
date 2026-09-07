<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Track>
 */
class TrackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $language = $this->faker->randomElement(['English', 'Portuguese', 'Spanish', 'French']);

        // Suffixed to satisfy the (business_id, name) unique constraint even when
        // several tracks are created for the same business without an explicit name.
        return [
            'name' => $language.' '.$this->faker->unique()->numerify('###'),
        ];
    }
}