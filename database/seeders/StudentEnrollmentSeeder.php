<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Database\Seeder;

class StudentEnrollmentSeeder extends Seeder
{
    public function run(): void
    {
        // One course per course_level, so course_level_id uniquely identifies the course.
        $coursesByLevel = Course::all()->keyBy('course_level_id');

        // A shared cohort start date per course keeps enrollment dates believable
        // (a class starts together, not scattered over the last 60 days).
        $cohortStarts = $coursesByLevel->mapWithKeys(
            fn ($course) => [$course->id => now()->subMonths(rand(1, 5))->startOfWeek()]
        );

        $count = 0;

        Student::all()->each(function (Student $student) use ($coursesByLevel, $cohortStarts, &$count) {
            $course = $coursesByLevel[$student->course_level_id] ?? null;

            if (! $course) {
                return;
            }

            $enrolledAt = $cohortStarts[$course->id]->copy()->addDays(rand(0, 5));

            foreach ($course->schedules as $schedule) {
                StudentEnrollment::create([
                    'student_id' => $student->id,
                    'schedule_id' => $schedule->id,
                    'status' => 'active',
                    'enrolled_at' => $enrolledAt,
                ]);
                $count++;
            }
        });

        $this->command->info("Created {$count} student enrollments.");
    }
}