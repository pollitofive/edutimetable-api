<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
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
        static $counter = 0;
        $counter++;

        // Use a sequence to avoid duplicates
        $dayOfWeek = ($counter % 5) + 1; // 1..5 (Mon..Fri)
        $hour = 8 + (($counter * 2) % 10); // 8, 10, 12, 14, 16, 8, 10...
        $start = $hour;
        $end = $start + 2; // 2h duration

        return [
            'course_id' => Course::factory(),
            'teacher_id' => Teacher::factory(),
            'day_of_week' => $dayOfWeek,
            'starts_at' => sprintf('%02d:00:00', $start),
            'ends_at' => sprintf('%02d:00:00', $end),
            'description' => fake()->sentence(),
        ];
    }
}
