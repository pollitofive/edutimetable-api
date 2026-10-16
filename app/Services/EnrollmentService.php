<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

        $schedules = $query->get();

        // Whole groups, regardless of the filters, so the client knows every
        // day of a course even when the day filter hides some of them
        $groupSlots = Schedule::whereIn('group_id', $schedules->pluck('group_id')->filter()->unique())
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('group_id');

        return $schedules->map(fn ($s) => $this->formatSchedule(
            $s,
            $s->group_id ? $groupSlots->get($s->group_id, collect([$s])) : collect([$s])
        ));
    }

    public function getEligibleStudents(int $scheduleId, string $search = ''): array
    {
        $schedule = Schedule::with('course.courseLevel.track')->findOrFail($scheduleId);
        $trackId = $schedule->course->courseLevel->track_id;

        // The student is enrolled in every day of the group, so every day must fit
        $group = $this->groupSchedules($schedule);

        $enrolledIds = StudentEnrollment::where('schedule_id', $scheduleId)
            ->where('status', 'active')
            ->pluck('student_id')
            ->toArray();

        $students = Student::with(['courseLevel.track', 'availabilities', 'activeEnrollments.schedule.course'])
            ->whereHas('courseLevel', fn ($q) => $q->where('track_id', $trackId))
            ->when($search !== '', fn ($q) => $q->search($search))
            ->orderBy('name')
            ->get();

        return $students->map(function (Student $student) use ($group, $enrolledIds) {
            $alreadyEnrolled = in_array($student->id, $enrolledIds);
            $blocked = $alreadyEnrolled
                ? ['blocked_reason' => 'enrolled', 'conflict' => null]
                : $this->blockingReason($student, $group);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'already_enrolled' => $alreadyEnrolled,
                'is_available' => $blocked['blocked_reason'] === null,
                ...$blocked,
                'course_level' => $student->courseLevel ? [
                    'name' => $student->courseLevel->name,
                    'track' => ['id' => $student->courseLevel->track->id, 'name' => $student->courseLevel->track->name],
                ] : null,
            ];
        })->values()->toArray();
    }

    public function getCompatibleSchedules(int $studentId): array
    {
        $student = Student::with(['courseLevel.track', 'availabilities', 'activeEnrollments.schedule.course'])->findOrFail($studentId);

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

    public function getEnrollments(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        $query = StudentEnrollment::with(['student', 'schedule.course', 'schedule.teacher'])
            ->orderByDesc('created_at');

        if (! empty($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }

        if (! empty($filters['schedule_id'])) {
            $query->where('schedule_id', $filters['schedule_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function updateEnrollment(int $id, array $data): StudentEnrollment
    {
        $enrollment = StudentEnrollment::with(['student', 'schedule.course', 'schedule.teacher'])->findOrFail($id);
        $enrollment->update($data);

        return $enrollment->fresh(['student', 'schedule.course', 'schedule.teacher']);
    }

    public function createEnrollments(int $studentId, array $scheduleIds): array
    {
        $student = Student::with('availabilities')->findOrFail($studentId);

        return DB::transaction(function () use ($student, $scheduleIds) {
            $result = [];
            foreach ($scheduleIds as $scheduleId) {
                $result[] = $this->createSingleEnrollment($student, (int) $scheduleId);
            }

            return $result;
        });
    }

    private function createSingleEnrollment(Student $student, int $scheduleId): StudentEnrollment
    {
        $studentId = $student->id;
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

        // Reloaded on every slot so the ones just created in this same request count too
        $student->load('activeEnrollments.schedule.course');
        $blocked = $this->blockingReason($student, collect([$schedule]), $this->groupSchedules($schedule)->pluck('id')->all());

        if ($blocked['blocked_reason'] === 'unavailable') {
            throw ValidationException::withMessages([
                'schedule_id' => __('enrollment.unavailable', $this->slotParams($schedule->day_of_week, $schedule->starts_at, $schedule->ends_at)),
            ]);
        }

        if ($blocked['blocked_reason'] === 'overlap') {
            $conflict = $blocked['conflict'];

            throw ValidationException::withMessages([
                'schedule_id' => __('enrollment.schedule_conflict', ['course' => $conflict['course_name']]
                    + $this->slotParams($conflict['day_of_week'], $conflict['starts_at'], $conflict['ends_at'])),
            ]);
        }

        // A previous enrollment (e.g. dropped) is reactivated instead of
        // inserting a new row, since (business, student, schedule) is unique.
        $existing = StudentEnrollment::where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->first();

        if ($existing) {
            $existing->update(['status' => 'active', 'enrolled_at' => now()]);

            return $existing;
        }

        return StudentEnrollment::create([
            'student_id' => $studentId,
            'schedule_id' => $scheduleId,
            'enrolled_at' => now(),
            'status' => 'active',
        ]);
    }

    /**
     * Drops the student from the whole schedule group the given enrollment
     * belongs to (every day of the group), keeping the rows as history.
     */
    public function dropEnrollment(int $id): int
    {
        $enrollment = StudentEnrollment::with('schedule')->findOrFail($id);

        if ($enrollment->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => __('enrollment.not_active'),
            ]);
        }

        $groupId = $enrollment->schedule->group_id;
        $scheduleIds = $groupId
            ? Schedule::where('group_id', $groupId)->pluck('id')
            : collect([$enrollment->schedule_id]);

        return $this->dropStudentFromSchedules($enrollment->student_id, $scheduleIds);
    }

    /**
     * Marks the student's active enrollments in the given schedules as dropped.
     */
    public function dropStudentFromSchedules(int $studentId, Collection|array $scheduleIds): int
    {
        return StudentEnrollment::where('student_id', $studentId)
            ->whereIn('schedule_id', $scheduleIds)
            ->where('status', 'active')
            ->update(['status' => 'dropped']);
    }

    /**
     * @param  Collection<int, Schedule>  $groupSlots  every schedule of the schedule's group, itself included
     */
    private function formatSchedule(Schedule $schedule, Collection $groupSlots): array
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
            'group_slots' => $groupSlots->map(fn ($s) => [
                'id' => $s->id,
                'teacher_id' => $s->teacher_id,
                'day_of_week' => $s->day_of_week,
                'starts_at' => substr($s->starts_at, 0, 5),
                'ends_at' => substr($s->ends_at, 0, 5),
            ])->values()->toArray(),
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
            $hasCapacity = $group->every(fn ($s) => $s->enrolled_count < $s->capacity);

            $blocked = match (true) {
                $isEnrolled => ['blocked_reason' => 'enrolled', 'conflict' => null],
                ! $hasCapacity => ['blocked_reason' => 'full', 'conflict' => null],
                default => $this->blockingReason($student, $group),
            };

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
                'is_compatible' => $blocked['blocked_reason'] === null,
                'already_enrolled' => $isEnrolled,
                ...$blocked,
            ];
        })->values()->toArray();
    }

    /**
     * Why the student can't take the given schedules: a slot outside their availability
     * ('unavailable') or overlapping another schedule they attend ('overlap', with that
     * schedule as 'conflict'). Schedules in $excludeIds (by default the given ones) don't
     * count as overlaps. Needs availabilities and activeEnrollments.schedule.course loaded.
     *
     * @param  Collection<int, Schedule>  $schedules
     * @param  array<int>|null  $excludeIds
     * @return array{blocked_reason: string|null, conflict: array|null}
     */
    private function blockingReason(Student $student, Collection $schedules, ?array $excludeIds = null): array
    {
        $excludeIds ??= $schedules->pluck('id')->all();

        foreach ($schedules as $s) {
            if (! $student->isAvailableFor((int) $s->day_of_week, $s->starts_at, $s->ends_at)) {
                return ['blocked_reason' => 'unavailable', 'conflict' => null];
            }
        }

        foreach ($schedules as $s) {
            $other = $student->conflictingSchedules((int) $s->day_of_week, $s->starts_at, $s->ends_at, $excludeIds)->first();

            if ($other) {
                return ['blocked_reason' => 'overlap', 'conflict' => [
                    'course_name' => $other->course->name,
                    'day_of_week' => (int) $other->day_of_week,
                    'starts_at' => substr($other->starts_at, 0, 5),
                    'ends_at' => substr($other->ends_at, 0, 5),
                ]];
            }
        }

        return ['blocked_reason' => null, 'conflict' => null];
    }

    /**
     * Every schedule of the given schedule's group, itself included.
     *
     * @return Collection<int, Schedule>
     */
    private function groupSchedules(Schedule $schedule): Collection
    {
        return $schedule->group_id
            ? Schedule::where('group_id', $schedule->group_id)->get()
            : collect([$schedule]);
    }

    private function slotParams(int|string $dayOfWeek, string $startsAt, string $endsAt): array
    {
        return [
            'day' => __('schedule.days.'.(int) $dayOfWeek),
            'starts_at' => substr($startsAt, 0, 5),
            'ends_at' => substr($endsAt, 0, 5),
        ];
    }
}
