<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

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

        Student::with('courseLevels')->get()->each(function (Student $student) use ($coursesByLevel, $cohortStarts, &$count) {
            // The schedules taken so far, so a second track never overlaps the first one
            $taken = collect();

            foreach ($student->courseLevels as $level) {
                $course = $coursesByLevel[$level->id] ?? null;

                if (! $course || $this->overlapsAny($course->schedules, $taken)) {
                    continue;
                }

                $enrolledAt = $cohortStarts[$course->id]->copy()->addDays(rand(0, 5));

                foreach ($course->schedules as $schedule) {
                    StudentEnrollment::create([
                        'student_id' => $student->id,
                        'schedule_id' => $schedule->id,
                        'status' => 'active',
                        'enrolled_at' => $enrolledAt,
                    ]);
                    $taken->push($schedule);
                    $count++;
                }
            }
        });

        $this->command->info("Created {$count} student enrollments.");
    }

    private function overlapsAny(Collection $schedules, Collection $taken): bool
    {
        return $schedules->contains(fn (Schedule $s) => $taken->contains(
            fn (Schedule $t) => (int) $t->day_of_week === (int) $s->day_of_week
                && Schedule::timesOverlap($s->starts_at, $s->ends_at, $t->starts_at, $t->ends_at)
        ));
    }
}
