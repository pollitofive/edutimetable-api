<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\StudentAvailability;
use Illuminate\Database\Seeder;

class StudentAvailabilitySeeder extends Seeder
{
    private const DAY_START = '08:00:00';

    private const DAY_END = '21:30:00';

    // Extra "free time" blocks unrelated to class, used to round out a student's profile.
    private const EXTRA_BLOCKS = [
        ['start_time' => '08:00', 'end_time' => '12:00'],
        ['start_time' => '14:00', 'end_time' => '18:00'],
        ['start_time' => '17:00', 'end_time' => '21:00'],
    ];

    public function run(): void
    {
        $count = 0;

        Student::with('schedules')->get()->each(function (Student $student) use (&$count) {
            $daysWithClass = [];

            foreach ($student->schedules as $schedule) {
                $day = $schedule->day_of_week;

                // Widen the class time a bit so availability comfortably covers it.
                $start = $this->clip($this->addMinutes($schedule->starts_at, -30));
                $end = $this->clip($this->addMinutes($schedule->ends_at, 60));

                if (isset($daysWithClass[$day])) {
                    $start = min($start, $daysWithClass[$day]['start_time']);
                    $end = max($end, $daysWithClass[$day]['end_time']);
                }

                $daysWithClass[$day] = ['start_time' => $start, 'end_time' => $end];
            }

            foreach ($daysWithClass as $day => $block) {
                StudentAvailability::create([
                    'student_id' => $student->id,
                    'day_of_week' => $day,
                    'start_time' => $block['start_time'],
                    'end_time' => $block['end_time'],
                ]);
                $count++;
            }

            // Some students also have general free time beyond their class days.
            $freeDays = array_diff([0, 1, 2, 3, 4, 5, 6], array_keys($daysWithClass));
            if ($freeDays && rand(1, 100) <= 30) {
                $extraDay = $freeDays[array_rand($freeDays)];
                $block = self::EXTRA_BLOCKS[array_rand(self::EXTRA_BLOCKS)];

                StudentAvailability::create([
                    'student_id' => $student->id,
                    'day_of_week' => $extraDay,
                    'start_time' => $block['start_time'],
                    'end_time' => $block['end_time'],
                ]);
                $count++;
            }
        });

        $this->command->info("Created {$count} student availabilities.");
    }

    private function addMinutes(string $time, int $minutes): string
    {
        return date('H:i:s', strtotime($time) + $minutes * 60);
    }

    private function clip(string $time): string
    {
        return max(self::DAY_START, min(self::DAY_END, $time));
    }
}