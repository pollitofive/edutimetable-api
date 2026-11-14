<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Support\Collection;

class DashboardService
{
    public function summary(): array
    {
        $schedules = Schedule::with(['course.courseLevel.track', 'teacher', 'activeEnrollments'])
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();

        // A course offering is a group of schedules (one per day); a student
        // enrolled in it has one active enrollment per day of the group
        $groups = $schedules->groupBy(fn (Schedule $s) => $this->groupKey($s));

        return [
            'totals' => $this->totals($groups),
            'courses_with_capacity' => $this->coursesWithCapacity($groups),
            'teacher_load' => $this->teacherLoad($schedules),
        ];
    }

    private function totals(Collection $groups): array
    {
        $activeEnrollments = $groups->sum(fn (Collection $slots) => $this->studentIds($slots)->count());

        // From the business' own schedules, like the rest of the numbers, so an
        // enrollment pointing to another business' schedule never counts
        $activeStudents = $groups->flatMap(fn (Collection $slots) => $this->studentIds($slots))->unique()->count();

        return [
            'active_students' => $activeStudents,
            'courses' => Course::count(),
            'teachers' => Teacher::count(),
            'active_enrollments' => $activeEnrollments,
        ];
    }

    private function coursesWithCapacity(Collection $groups): array
    {
        return $groups
            ->map(function (Collection $slots) {
                $first = $slots->first();
                $enrolled = $this->studentIds($slots)->count();

                return [
                    'group_id' => $first->group_id,
                    'schedule_id' => $first->id,
                    'course' => ['id' => $first->course->id, 'name' => $first->course->name],
                    'track' => ['id' => $first->course->courseLevel->track->id, 'name' => $first->course->courseLevel->track->name],
                    'level' => $first->course->courseLevel->name,
                    'teachers' => $slots->pluck('teacher.name')->unique()->values()->toArray(),
                    'slots' => $slots->map(fn (Schedule $s) => [
                        'day_of_week' => $s->day_of_week,
                        'starts_at' => substr($s->starts_at, 0, 5),
                        'ends_at' => substr($s->ends_at, 0, 5),
                    ])->values()->toArray(),
                    'capacity' => $first->capacity,
                    'enrolled' => $enrolled,
                    'available_spots' => $first->capacity - $enrolled,
                ];
            })
            ->filter(fn (array $g) => $g['available_spots'] > 0)
            // Fullest first: the ones closest to closing are the easiest to fill
            ->sortByDesc(fn (array $g) => $g['capacity'] > 0 ? $g['enrolled'] / $g['capacity'] : 0)
            ->values()
            ->toArray();
    }

    private function teacherLoad(Collection $schedules): array
    {
        $byTeacher = $schedules->groupBy('teacher_id');

        return Teacher::orderBy('name')->get()
            ->map(function (Teacher $teacher) use ($byTeacher) {
                $slots = $byTeacher->get($teacher->id, collect());
                $minutes = $slots->sum(fn (Schedule $s) => $this->minutes($s->ends_at) - $this->minutes($s->starts_at));

                return [
                    'teacher' => ['id' => $teacher->id, 'name' => $teacher->name],
                    'weekly_hours' => round($minutes / 60, 2),
                    'active_students' => $this->studentIds($slots)->count(),
                ];
            })
            ->sortByDesc('weekly_hours')
            ->values()
            ->toArray();
    }

    private function groupKey(Schedule $schedule): string
    {
        return $schedule->group_id ?? "schedule-{$schedule->id}";
    }

    private function studentIds(Collection $slots): Collection
    {
        return $slots->flatMap(fn (Schedule $s) => $s->activeEnrollments->pluck('student_id'))->unique();
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }
}
