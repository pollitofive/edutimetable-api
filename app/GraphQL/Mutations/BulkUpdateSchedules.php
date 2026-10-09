<?php

namespace App\GraphQL\Mutations;

use App\GraphQL\Validators\Concerns\ValidatesScheduleOverlaps;
use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BulkUpdateSchedules
{
    use ValidatesScheduleOverlaps;

    /**
     * Sync a course's schedules with the given slots, in place: slots with an id update
     * that schedule, slots without one are created, and existing schedules left out are
     * deleted. Schedules are never deleted and recreated, so student_enrollments (which
     * cascade on delete) are preserved.
     *
     * Once the group has active students, day/time changes and adding/removing slots are
     * rejected: moving a course with students goes through the schedule change simulator.
     *
     * @param  null  $_
     * @return \Illuminate\Support\Collection
     *
     * @throws ValidationException
     */
    public function __invoke($_, array $args)
    {
        $courseId = (int) $args['course_id'];
        $description = $args['description']; // Required field
        $capacity = isset($args['capacity']) ? (int) $args['capacity'] : null;
        $groupId = $args['group_id'] ?? null; // Optional - sent by frontend for updates
        $schedules = $args['schedules'];

        // Validate at least one schedule is provided
        if (empty($schedules)) {
            throw ValidationException::withMessages([
                'schedules' => [__('schedule.at_least_one')],
            ]);
        }

        // Verify course exists
        $course = Course::findOrFail($courseId);

        // If group_id is provided, sync schedules with that group_id
        // Otherwise, sync all schedules for this course (backward compatibility)
        $existing = ($groupId
            ? Schedule::where('group_id', $groupId)
            : Schedule::where('course_id', $course->id))
            ->withCount('activeEnrollments')
            ->get()
            ->keyBy('id');

        // Validate all slots before processing
        $this->validateSlotIds($schedules, $existing);
        $this->validateEnrolledGroup($schedules, $existing, $capacity);
        // Check both internal overlaps and database overlaps with other courses
        $this->validateSchedules($course->id, $schedules, $groupId);

        $savedSchedules = DB::transaction(function () use ($course, $description, $capacity, $groupId, $schedules, $existing) {
            $keptIds = collect($schedules)->pluck('id')->filter()->map(fn ($id) => (int) $id);
            Schedule::whereIn('id', $existing->keys()->diff($keptIds))->delete();

            // Use existing group_id or generate a new one
            $finalGroupId = $groupId ?? (string) Str::uuid();

            $saved = collect();

            foreach ($schedules as $schedule) {
                $attributes = [
                    'course_id' => $course->id,
                    'teacher_id' => (int) $schedule['teacher_id'],
                    'day_of_week' => (int) $schedule['day_of_week'],
                    // Normalize time format to HH:MM:SS for database
                    'starts_at' => $this->normalizeTime($schedule['starts_at']),
                    'ends_at' => $this->normalizeTime($schedule['ends_at']),
                    'description' => $description,
                    'group_id' => $finalGroupId,
                ];

                if ($capacity !== null) {
                    $attributes['capacity'] = $capacity;
                }

                if (! empty($schedule['id'])) {
                    $model = $existing->get((int) $schedule['id']);
                    $model->update($attributes);
                    $saved->push($model);
                } else {
                    $saved->push(Schedule::create($attributes));
                }
            }

            return $saved;
        });

        return $savedSchedules->all();
    }

    /**
     * Slot ids must reference schedules of the group (or course) being edited
     *
     * @throws ValidationException
     */
    protected function validateSlotIds(array $schedules, Collection $existing): void
    {
        foreach ($schedules as $index => $schedule) {
            if (! empty($schedule['id']) && ! $existing->has((int) $schedule['id'])) {
                throw ValidationException::withMessages([
                    'schedules' => [__('schedule.slot_not_in_group', ['slot' => $index + 1])],
                ]);
            }
        }
    }

    /**
     * With active students in the group, only teacher/description/capacity can change,
     * and capacity can't drop below the students already enrolled
     *
     * @throws ValidationException
     */
    protected function validateEnrolledGroup(array $schedules, Collection $existing, ?int $capacity): void
    {
        $enrolled = (int) $existing->max('active_enrollments_count');

        if ($enrolled === 0) {
            return;
        }

        if ($capacity !== null && $capacity < $enrolled) {
            throw ValidationException::withMessages([
                'capacity' => [__('schedule.capacity_below_enrolled', ['capacity' => $capacity, 'enrolled' => $enrolled])],
            ]);
        }

        $keptIds = collect($schedules)->pluck('id')->filter()->map(fn ($id) => (int) $id);

        if ($keptIds->count() !== count($schedules) || $existing->keys()->diff($keptIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'schedules' => [__('schedule.locked_slots', ['count' => $enrolled])],
            ]);
        }

        foreach ($schedules as $index => $schedule) {
            $current = $existing->get((int) $schedule['id']);

            if ((int) $schedule['day_of_week'] !== (int) $current->day_of_week
                || $this->normalizeTime($schedule['starts_at']) !== $this->normalizeTime($current->starts_at)
                || $this->normalizeTime($schedule['ends_at']) !== $this->normalizeTime($current->ends_at)) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        __('schedule.locked_time', [
                            'slot' => $index + 1,
                            'day' => $this->getDayName((int) $current->day_of_week),
                            'start' => substr($current->starts_at, 0, 5),
                            'end' => substr($current->ends_at, 0, 5),
                            'count' => $enrolled,
                        ]),
                    ],
                ]);
            }
        }
    }

    /**
     * Validate all schedules for overlaps and time constraints
     *
     * CHANGED: Now validates TEACHER conflicts instead of COURSE conflicts
     * Checks both database overlaps (excluding current group) and internal overlaps
     *
     * @throws ValidationException
     */
    protected function validateSchedules(int $courseId, array $schedules, ?string $groupId = null): void
    {
        foreach ($schedules as $index => $schedule) {
            $slotNumber = $index + 1;
            $dayName = $this->getDayName((int) $schedule['day_of_week']);

            // Validate teacher_id is present
            if (! isset($schedule['teacher_id'])) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        __('schedule.teacher_required', [
                            'slot' => $slotNumber,
                            'day' => $dayName,
                            'start' => $schedule['starts_at'],
                            'end' => $schedule['ends_at'],
                        ]),
                    ],
                ]);
            }

            // Validate time constraint (end time must be after start time)
            if ($schedule['starts_at'] >= $schedule['ends_at']) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        __('schedule.end_after_start', [
                            'slot' => $slotNumber,
                            'day' => $dayName,
                            'start' => $schedule['starts_at'],
                            'end' => $schedule['ends_at'],
                        ]),
                    ],
                ]);
            }

            // Check for overlaps with existing database schedules
            // If group_id is provided, exclude schedules with that group_id
            // Otherwise, exclude schedules from the current course (backward compatibility)
            $query = Schedule::where('teacher_id', (int) $schedule['teacher_id'])
                ->where('day_of_week', (int) $schedule['day_of_week'])
                ->where(function ($q) use ($schedule) {
                    $startsAt = $this->normalizeTime($schedule['starts_at']);
                    $endsAt = $this->normalizeTime($schedule['ends_at']);
                    $q->where('starts_at', '<', $endsAt)
                        ->where('ends_at', '>', $startsAt);
                });

            // Exclude schedules that will be replaced
            if ($groupId) {
                $query->where('group_id', '!=', $groupId);
            } else {
                $query->where('course_id', '!=', $courseId);
            }

            $overlapping = $query->first();

            if ($overlapping) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        __('schedule.teacher_overlap_db_other_course', [
                            'slot' => $slotNumber,
                            'day' => $dayName,
                            'start' => $schedule['starts_at'],
                            'end' => $schedule['ends_at'],
                            'existing_start' => substr($overlapping->starts_at, 0, 5),
                            'existing_end' => substr($overlapping->ends_at, 0, 5),
                        ]),
                    ],
                ]);
            }

            // Check for teacher conflicts within the input array
            // Same teacher cannot teach multiple slots at the same time
            for ($j = 0; $j < $index; $j++) {
                $otherSlot = $schedules[$j];
                $otherSlotNumber = $j + 1;

                // Only check if same teacher
                if ((int) $schedule['teacher_id'] === (int) $otherSlot['teacher_id']) {
                    if ($this->slotsOverlap(
                        (int) $schedule['day_of_week'],
                        $this->normalizeTime($schedule['starts_at']),
                        $this->normalizeTime($schedule['ends_at']),
                        (int) $otherSlot['day_of_week'],
                        $this->normalizeTime($otherSlot['starts_at']),
                        $this->normalizeTime($otherSlot['ends_at'])
                    )) {
                        $otherDayName = $this->getDayName((int) $otherSlot['day_of_week']);
                        throw ValidationException::withMessages([
                            'schedules' => [
                                __('schedule.teacher_conflict_slots', [
                                    'slot1' => $slotNumber,
                                    'day1' => $dayName,
                                    'start1' => $schedule['starts_at'],
                                    'end1' => $schedule['ends_at'],
                                    'slot2' => $otherSlotNumber,
                                    'day2' => $otherDayName,
                                    'start2' => $otherSlot['starts_at'],
                                    'end2' => $otherSlot['ends_at'],
                                ]),
                            ],
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Normalize time from HH:MM to HH:MM:SS format
     */
    protected function normalizeTime(string $time): string
    {
        // If time is already in HH:MM:SS format, return as is
        if (substr_count($time, ':') === 2) {
            return $time;
        }

        // Convert HH:MM to HH:MM:SS
        return $time.':00';
    }
}
