<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "What if" simulator for moving a course (schedule group) to new days/times.
 *
 * It is the only way to change the day or time of a course with active students:
 * schedules are updated in place, so schedule ids (and therefore enrollments) are kept.
 * A teacher overlap or two slots of the course overlapping each other blocks the
 * change; students left without availability or overlapping another of their courses
 * are only reported, and can optionally be dropped in the same apply.
 */
class ScheduleChangeService
{
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly EnrollmentService $enrollmentService,
    ) {}

    /**
     * @param  array<int, array{schedule_id: int, day_of_week: int, starts_at: string, ends_at: string}>  $slots  proposed day/time for every schedule of the group
     */
    public function preview(array $slots): array
    {
        return $this->analyze($this->loadGroup($slots), $this->normalize($slots));
    }

    /**
     * @param  array<int, array{schedule_id: int, day_of_week: int, starts_at: string, ends_at: string}>  $slots
     * @param  array<int>  $dropStudentIds  affected students to drop from the whole group
     */
    public function apply(array $slots, array $dropStudentIds = []): array
    {
        return DB::transaction(function () use ($slots, $dropStudentIds) {
            $group = $this->loadGroup($slots, lock: true);
            $result = $this->analyze($group, $this->normalize($slots));

            if (! $result['can_apply']) {
                throw ValidationException::withMessages([
                    'slots' => __('schedule.change.has_conflicts'),
                ]);
            }

            $dropStudentIds = array_values(array_unique(array_map('intval', $dropStudentIds)));
            $affectedIds = collect($result['affected_students'])->pluck('student.id')->all();

            if (array_diff($dropStudentIds, $affectedIds)) {
                throw ValidationException::withMessages([
                    'drop_student_ids' => __('schedule.change.drop_not_affected'),
                ]);
            }

            // In place: same rows, so enrollments keep pointing to them
            foreach ($result['changes'] as $change) {
                $group->firstWhere('id', $change['schedule_id'])->update([
                    'day_of_week' => $change['to']['day_of_week'],
                    'starts_at' => $change['to']['starts_at'].':00',
                    'ends_at' => $change['to']['ends_at'].':00',
                ]);
            }

            foreach ($dropStudentIds as $studentId) {
                $this->enrollmentService->dropStudentFromSchedules($studentId, $group->pluck('id'));
            }

            return [
                'changes' => $result['changes'],
                'dropped_students' => count($dropStudentIds),
            ];
        });
    }

    /**
     * The schedules of the group the slots belong to. The slots must be exactly
     * the whole group: adding or removing days is not supported here.
     */
    private function loadGroup(array $slots, bool $lock = false): Collection
    {
        $ids = collect($slots)->pluck('schedule_id')->map(fn ($id) => (int) $id);

        if ($ids->isEmpty() || $ids->unique()->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'slots' => __('schedule.change.slots_mismatch'),
            ]);
        }

        $first = Schedule::find($ids->first());

        if (! $first) {
            throw ValidationException::withMessages([
                'slots' => __('schedule.change.slots_mismatch'),
            ]);
        }

        $group = Schedule::with(['teacher', 'course'])
            ->when(
                $first->group_id,
                fn ($q) => $q->where('group_id', $first->group_id),
                fn ($q) => $q->whereKey($first->id)
            )
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();

        if ($group->pluck('id')->sort()->values()->all() !== $ids->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'slots' => __('schedule.change.slots_mismatch'),
            ]);
        }

        return $group;
    }

    /**
     * Proposed slots keyed by schedule id, with times as HH:MM:SS like the database.
     *
     * @return Collection<int, array{day_of_week: int, starts_at: string, ends_at: string}>
     */
    private function normalize(array $slots): Collection
    {
        return collect($slots)->mapWithKeys(function ($slot) {
            $startsAt = substr($slot['starts_at'], 0, 5).':00';
            $endsAt = substr($slot['ends_at'], 0, 5).':00';

            if ($startsAt >= $endsAt) {
                throw ValidationException::withMessages([
                    'slots' => __('schedule.starts_before_ends'),
                ]);
            }

            return [(int) $slot['schedule_id'] => [
                'day_of_week' => (int) $slot['day_of_week'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]];
        });
    }

    private function analyze(Collection $group, Collection $proposed): array
    {
        $groupIds = $group->pluck('id')->all();

        $changed = $group->filter(function (Schedule $s) use ($proposed) {
            $to = $proposed[$s->id];

            return $to['day_of_week'] !== (int) $s->day_of_week
                || $to['starts_at'] !== $s->starts_at
                || $to['ends_at'] !== $s->ends_at;
        })->values();

        if ($changed->isEmpty()) {
            throw ValidationException::withMessages([
                'slots' => __('schedule.change.no_changes'),
            ]);
        }

        $internalConflicts = $this->internalConflicts($proposed);
        $teacherConflicts = $this->teacherConflicts($changed, $proposed, $groupIds);

        return [
            'can_apply' => empty($internalConflicts) && empty($teacherConflicts),
            'changes' => $changed->map(fn (Schedule $s) => [
                'schedule_id' => $s->id,
                'from' => $this->formatSlot((int) $s->day_of_week, $s->starts_at, $s->ends_at),
                'to' => $this->formatSlot(...$proposed[$s->id]),
            ])->all(),
            'internal_conflicts' => $internalConflicts,
            'teacher_conflicts' => $teacherConflicts,
            'affected_students' => $this->affectedStudents($changed, $proposed, $groupIds),
        ];
    }

    /**
     * Two slots of the same course ending up overlapping each other on the same day.
     */
    private function internalConflicts(Collection $proposed): array
    {
        $slots = $proposed->values();
        $conflicts = [];

        for ($i = 0; $i < $slots->count(); $i++) {
            for ($j = $i + 1; $j < $slots->count(); $j++) {
                $a = $slots[$i];
                $b = $slots[$j];

                if ($a['day_of_week'] === $b['day_of_week']
                    && Schedule::timesOverlap($a['starts_at'], $a['ends_at'], $b['starts_at'], $b['ends_at'])) {
                    $conflicts[] = [
                        'slot' => $this->formatSlot(...$a),
                        'other' => $this->formatSlot(...$b),
                    ];
                }
            }
        }

        return $conflicts;
    }

    private function teacherConflicts(Collection $changed, Collection $proposed, array $groupIds): array
    {
        return $changed->flatMap(function (Schedule $s) use ($proposed, $groupIds) {
            $to = $proposed[$s->id];

            return $this->scheduleService
                ->teacherOverlapQuery($s->teacher_id, $to['day_of_week'], $to['starts_at'], $to['ends_at'], $groupIds)
                ->with('course')
                ->get()
                ->map(fn (Schedule $other) => [
                    'schedule_id' => $s->id,
                    'teacher' => ['id' => $s->teacher->id, 'name' => $s->teacher->name],
                    'slot' => $this->formatSlot(...$to),
                    'conflict' => [
                        'schedule_id' => $other->id,
                        'course_name' => $other->course->name,
                    ] + $this->formatSlot((int) $other->day_of_week, $other->starts_at, $other->ends_at),
                ]);
        })->values()->all();
    }

    /**
     * Active students of the group that, in some changed slot, are left without
     * availability or overlapping another course they attend.
     */
    private function affectedStudents(Collection $changed, Collection $proposed, array $groupIds): array
    {
        $studentIds = StudentEnrollment::whereIn('schedule_id', $groupIds)
            ->where('status', 'active')
            ->distinct()
            ->pluck('student_id');

        $students = Student::with(['availabilities', 'activeEnrollments.schedule.course'])
            ->whereIn('id', $studentIds)
            ->orderBy('name')
            ->get();

        return $students->map(function (Student $student) use ($changed, $proposed, $groupIds) {
            $issues = [];

            foreach ($changed as $schedule) {
                $to = $proposed[$schedule->id];

                if (! $student->isAvailableFor($to['day_of_week'], $to['starts_at'], $to['ends_at'])) {
                    $issues[] = ['type' => 'unavailable'] + $this->formatSlot(...$to);
                }

                foreach ($student->conflictingSchedules($to['day_of_week'], $to['starts_at'], $to['ends_at'], $groupIds) as $other) {
                    $issues[] = ['type' => 'overlap'] + $this->formatSlot(...$to) + [
                        'other' => [
                            'course_name' => $other->course->name,
                        ] + $this->formatSlot((int) $other->day_of_week, $other->starts_at, $other->ends_at),
                    ];
                }
            }

            return $issues ? [
                'student' => ['id' => $student->id, 'name' => $student->name],
                'issues' => $issues,
                // So the user can see what times would suit the student instead
                'availabilities' => $student->availabilities
                    ->sortBy(['day_of_week', 'start_time'])
                    ->map(fn ($a) => $this->formatSlot((int) $a->day_of_week, $a->start_time, $a->end_time))
                    ->values()
                    ->all(),
            ] : null;
        })->filter()->values()->all();
    }

    private function formatSlot(int $day_of_week, string $starts_at, string $ends_at): array
    {
        return [
            'day_of_week' => $day_of_week,
            'starts_at' => substr($starts_at, 0, 5),
            'ends_at' => substr($ends_at, 0, 5),
        ];
    }
}
