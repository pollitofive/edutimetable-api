<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->numberBetween(8, 18);     // 08..18
        $end   = $start + 1;                              // 1h duration

        return [
            'course_id'  => Course::factory(),
            'day_of_week'=> $this->faker->numberBetween(1, 5), // Mon..Fri
            'starts_at'  => sprintf('%02d:00:00', $start),
            'ends_at'    => sprintf('%02d:00:00', $end),
        ];
    }
}
