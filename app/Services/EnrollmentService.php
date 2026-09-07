<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    public function getSchedules(array $filters = []): Collection
    {
        $query = Schedule::with([
            'course.courseLevel.track',
            'teacher',
            'enrollments' => fn ($q) => $q->where('status', 'active')->with('student.courseLevel.track'),
        ])->withCount([
            'enrollments as enrolled_count' => fn ($q) => $q->where('status', 'active'),
        ]);

        if (! empty($filters['course_ids'])) {
            $query->whereIn('course_id', (array) $filters['course_ids']);
        }

        if (! empty($filters['teacher_ids'])) {
            $query->whereIn('teacher_id', (array) $filters['teacher_ids']);
        }

        if (! empty($filters['days_of_week'])) {
            $query->whereIn('day_of_week', array_map('intval', (array) $filters['days_of_week']));
        }

        if (! empty($filters['track_ids'])) {
            $query->whereHas('course.courseLevel', fn ($q) => $q->whereIn('track_id', (array) $filters['track_ids']));
        }

        return $query->get()->map(fn ($s) => $this->formatSchedule($s));
    }

    public function getEligibleStudents(int $scheduleId, string $search = ''): array
    {
        $schedule = Schedule::with('course.courseLevel.track')->findOrFail($scheduleId);
        $trackId = $schedule->course->courseLevel->track_id;

        $enrolledIds = StudentEnrollment::where('schedule_id', $scheduleId)
            ->where('status', 'active')
            ->pluck('student_id')
            ->toArray();

        $students = Student::with(['courseLevel.track', 'availabilities'])
            ->whereHas('courseLevel', fn ($q) => $q->where('track_id', $trackId))
            ->when($search !== '', fn ($q) => $q->search($search))
            ->orderBy('name')
            ->get();

        return $students->map(function (Student $student) use ($schedule, $enrolledIds) {
            $alreadyEnrolled = in_array($student->id, $enrolledIds);
            $isAvailable = ! $alreadyEnrolled && $this->isStudentAvailable($student, $schedule);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'already_enrolled' => $alreadyEnrolled,
                'is_available' => $isAvailable,
                'course_level' => $student->courseLevel ? [
                    'name' => $student->courseLevel->name,
                    'track' => ['id' => $student->courseLevel->track->id, 'name' => $student->courseLevel->track->name],
                ] : null,
            ];
        })->values()->toArray();
    }

    public function getCompatibleSchedules(int $studentId): array
    {
        $student = Student::with(['courseLevel.track', 'availabilities'])->findOrFail($studentId);

        if (! $student->course_level_id) {
            return ['student' => $this->formatStudent($student), 'schedule_groups' => []];
        }

        $schedules = Schedule::with(['course', 'teacher'])
            ->withCount(['enrollments as enrolled_count' => fn ($q) => $q->where('status', 'active')])
            ->whereHas('course', fn ($q) => $q->where('course_level_id', $student->course_level_id))
            ->get();

        $enrolledIds = StudentEnrollment::where('student_id', $studentId)
            ->where('status', 'active')
            ->pluck('schedule_id')
            ->toArray();

        return [
            'student' => $this->formatStudent($student),
            'schedule_groups' => $this->buildScheduleGroups($schedules, $student, $enrolledIds),
        ];
    }

    public function searchStudents(string $search = ''): Collection
    {
        return Student::with('courseLevel.track')
            ->when($search !== '', fn ($q) => $q->search($search))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'phone' => $s->phone,
                'course_level' => $s->courseLevel ? [
                    'name' => $s->courseLevel->name,
                    'track' => ['id' => $s->courseLevel->track->id, 'name' => $s->courseLevel->track->name],
                ] : null,
            ]);
    }

    public function createEnrollments(int $studentId, array $scheduleIds): array
    {
        Student::findOrFail($studentId);

        return DB::transaction(function () use ($studentId, $scheduleIds) {
            $result = [];
            foreach ($scheduleIds as $scheduleId) {
                $result[] = $this->createSingleEnrollment($studentId, (int) $scheduleId);
            }

            return $result;
        });
    }

    private function createSingleEnrollment(int $studentId, int $scheduleId): StudentEnrollment
    {
        $schedule = Schedule::withCount([
            'enrollments as enrolled_count' => fn ($q) => $q->where('status', 'active'),
        ])->findOrFail($scheduleId);

        if (StudentEnrollment::where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->where('status', 'active')
            ->exists()) {
            throw ValidationException::withMessages([
                'student_id' => __('enrollment.already_enrolled'),
            ]);
        }

        if ($schedule->enrolled_count >= $schedule->capacity) {
            throw ValidationException::withMessages([
                'schedule_id' => __('enrollment.no_spots'),
            ]);
        }

        return StudentEnrollment::create([
            'student_id' => $studentId,
            'schedule_id' => $scheduleId,
            'enrolled_at' => now(),
            'status' => 'active',
        ]);
    }

    private function formatSchedule(Schedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'course_id' => $schedule->course_id,
            'teacher_id' => $schedule->teacher_id,
            'day_of_week' => $schedule->day_of_week,
            'starts_at' => substr($schedule->starts_at, 0, 5),
            'ends_at' => substr($schedule->ends_at, 0, 5),
            'description' => $schedule->description,
            'group_id' => $schedule->group_id,
            'capacity' => $schedule->capacity,
            'enrolled_count' => $schedule->enrolled_count,
            'available_spots' => $schedule->capacity - $schedule->enrolled_count,
            'course' => [
                'id' => $schedule->course->id,
                'name' => $schedule->course->name,
                'course_level' => [
                    'id' => $schedule->course->courseLevel->id,
                    'track' => ['id' => $schedule->course->courseLevel->track->id, 'name' => $schedule->course->courseLevel->track->name],
                    'name' => $schedule->course->courseLevel->name,
                ],
            ],
            'teacher' => [
                'id' => $schedule->teacher->id,
                'name' => $schedule->teacher->name,
            ],
            'active_enrollments' => $schedule->enrollments->map(fn ($e) => [
                'id' => $e->id,
                'student' => [
                    'id' => $e->student->id,
                    'name' => $e->student->name,
                    'email' => $e->student->email,
                    'course_level' => $e->student->courseLevel ? [
                        'name' => $e->student->courseLevel->name,
                        'track' => ['id' => $e->student->courseLevel->track->id, 'name' => $e->student->courseLevel->track->name],
                    ] : null,
                ],
                'status' => $e->status,
            ])->values()->toArray(),
        ];
    }

    private function formatStudent(Student $student): array
    {
        return [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => $student->phone,
            'course_level' => $student->courseLevel ? [
                'name' => $student->courseLevel->name,
                'track' => ['id' => $student->courseLevel->track->id, 'name' => $student->courseLevel->track->name],
            ] : null,
            'availabilities' => $student->availabilities
                ->sortBy('day_of_week')
                ->map(fn ($a) => [
                    'day_of_week' => $a->day_of_week,
                    'day_name' => __('schedule.days.'.$a->day_of_week),
                    'start_time' => substr($a->start_time, 0, 5),
                    'end_time' => substr($a->end_time, 0, 5),
                ])
                ->values()
                ->toArray(),
        ];
    }

    private function buildScheduleGroups(Collection $schedules, Student $student, array $enrolledIds): array
    {
        $grouped = $schedules->groupBy(fn ($s) => $s->group_id ?? ('solo_'.$s->id));

        return $grouped->map(function (Collection $group) use ($student, $enrolledIds) {
            $first = $group->first();
            $isEnrolled = $group->contains(fn ($s) => in_array($s->id, $enrolledIds));
            $allAvailable = $group->every(fn ($s) => $this->isStudentAvailable($student, $s));
            $hasCapacity = $group->every(fn ($s) => $s->enrolled_count < $s->capacity);

            $daysLabel = $group
                ->sortBy('day_of_week')
                ->map(fn ($s) => __('schedule.days_short.'.$s->day_of_week))
                ->implode(' y ');

            return [
                'group_id' => $first->group_id,
                'schedule_ids' => $group->pluck('id')->toArray(),
                'course_name' => $first->course->name,
                'teacher_name' => $first->teacher->name,
                'days_label' => $daysLabel,
                'starts_at' => substr($first->starts_at, 0, 5),
                'ends_at' => substr($first->ends_at, 0, 5),
                'enrolled_count' => $first->enrolled_count,
                'capacity' => $first->capacity,
                'available_spots' => $first->capacity - $first->enrolled_count,
                'is_compatible' => ! $isEnrolled && $allAvailable && $hasCapacity,
                'already_enrolled' => $isEnrolled,
            ];
        })->values()->toArray();
    }

    private function isStudentAvailable(Student $student, Schedule $schedule): bool
    {
        return $student->availabilities->contains(function ($avail) use ($schedule) {
            return (int) $avail->day_of_week === (int) $schedule->day_of_week
                && $avail->start_time <= $schedule->starts_at
                && $avail->end_time >= $schedule->ends_at;
        });
    }
}