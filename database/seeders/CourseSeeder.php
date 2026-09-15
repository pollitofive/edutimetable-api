<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CourseSeeder extends Seeder
{
    /**
     * One weekly pattern per level, applied to every track: days of week (0=Sunday..6=Saturday)
     * plus class duration in minutes. Mirrors the frequencies the ticket asks for.
     */
    private const LEVEL_PATTERNS = [
        'beginner' => ['days' => [1, 3, 5], 'duration' => 60],
        'pre-intermediate' => ['days' => [1, 3, 5], 'duration' => 60],
        'intermediate' => ['days' => [2, 4], 'duration' => 90],
        'upper-intermediate' => ['days' => [2, 4], 'duration' => 90],
        'advanced' => ['days' => [6], 'duration' => 120],
    ];

    /**
     * Starting hour per track+level, chosen so that same-level courses across tracks
     * (which tend to land on the same teacher, see assignTeacher()) never share a time slot.
     */
    private const START_TIMES = [
        'English' => ['beginner' => '09:00', 'pre-intermediate' => '10:30', 'intermediate' => '17:00', 'upper-intermediate' => '18:45', 'advanced' => '10:00'],
        'Portuguese' => ['beginner' => '19:00', 'pre-intermediate' => '08:00', 'intermediate' => '20:00', 'upper-intermediate' => '08:30', 'advanced' => '17:00'],
        'Spanish' => ['beginner' => '14:00', 'pre-intermediate' => '15:30', 'intermediate' => '18:00', 'upper-intermediate' => '19:45', 'advanced' => '11:00'],
        'French' => ['beginner' => '16:00', 'pre-intermediate' => '17:30', 'intermediate' => '09:00', 'upper-intermediate' => '10:45', 'advanced' => '15:00'],
    ];

    private array $teacherBookedSlots = []; // teacher_id => [[day, starts_at, ends_at], ...]

    private int $teacherRotation = 0;

    public function run(): void
    {
        $teachers = Teacher::all();
        $courseCount = 0;
        $scheduleCount = 0;

        foreach (self::START_TIMES as $trackName => $levelStartTimes) {
            $courseLevels = CourseLevel::whereHas('track', fn ($q) => $q->where('name', $trackName))
                ->get()
                ->keyBy('slug');

            foreach ($levelStartTimes as $levelSlug => $startTime) {
                $courseLevel = $courseLevels[$levelSlug];
                $pattern = self::LEVEL_PATTERNS[$levelSlug];
                $endsAt = $this->addMinutes($startTime, $pattern['duration']);

                $course = Course::create([
                    'name' => "{$trackName} {$courseLevel->name}",
                    'course_level_id' => $courseLevel->id,
                ]);
                $courseCount++;

                $teacher = $this->assignTeacher($teachers, $pattern['days'], $startTime, $endsAt);
                $groupId = (string) Str::uuid();
                $capacity = match (count($pattern['days'])) {
                    1 => 10,
                    2 => 12,
                    default => 14,
                };

                foreach ($pattern['days'] as $day) {
                    Schedule::create([
                        'course_id' => $course->id,
                        'teacher_id' => $teacher->id,
                        'group_id' => $groupId,
                        'day_of_week' => $day,
                        'starts_at' => $startTime,
                        'ends_at' => $endsAt,
                        'capacity' => $capacity,
                        'description' => null,
                    ]);
                    $scheduleCount++;
                }
            }
        }

        $this->command->info("Created {$courseCount} courses with {$scheduleCount} schedules.");
    }

    /**
     * Round-robins teachers, skipping anyone already booked on an overlapping day/time
     * so the demo never shows one teacher double-booked.
     */
    private function assignTeacher($teachers, array $days, string $startsAt, string $endsAt): Teacher
    {
        $total = $teachers->count();

        for ($i = 0; $i < $total; $i++) {
            $candidate = $teachers[($this->teacherRotation + $i) % $total];
            $slots = $this->teacherBookedSlots[$candidate->id] ?? [];

            $hasConflict = false;
            foreach ($days as $day) {
                foreach ($slots as [$bookedDay, $bookedStart, $bookedEnd]) {
                    if ($bookedDay === $day && $startsAt < $bookedEnd && $endsAt > $bookedStart) {
                        $hasConflict = true;
                        break 2;
                    }
                }
            }

            if (! $hasConflict) {
                $this->teacherRotation = ($this->teacherRotation + $i + 1) % $total;

                foreach ($days as $day) {
                    $this->teacherBookedSlots[$candidate->id][] = [$day, $startsAt, $endsAt];
                }

                return $candidate;
            }
        }

        throw new \RuntimeException('No teacher available without a schedule conflict.');
    }

    private function addMinutes(string $time, int $minutes): string
    {
        return date('H:i:s', strtotime($time) + $minutes * 60);
    }
}