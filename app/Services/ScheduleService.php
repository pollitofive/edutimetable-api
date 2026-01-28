<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    /**
     * Error codes for structured validation messages
     */
    public const ERROR_TIME_INVALID = 'SCHEDULE_TIME_INVALID';

    public const ERROR_OVERLAP_TEACHER = 'SCHEDULE_OVERLAP_TEACHER';

    public const ERROR_OVERLAP_COURSE = 'SCHEDULE_OVERLAP_COURSE';

    public const ERROR_FOREIGN_TENANT_REF = 'SCHEDULE_FOREIGN_TENANT_REF';

    /**
     * @param  array  $data  ['day_of_week' => int 0..6, 'starts_at' => 'HH:MM', 'ends_at' => 'HH:MM', 'description' => string|null]
     */
    public function createSchedule(int $courseId, int $teacherId, array $data): Schedule
    {
        // 1) Basic validation
        $v = Validator::make(
            ['course_id' => $courseId, 'teacher_id' => $teacherId] + $data,
            [
                'course_id' => ['required', 'integer', 'exists:courses,id'],
                'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
                'day_of_week' => ['required', 'integer', 'between:0,6'],
                'starts_at' => ['required', 'date_format:H:i'],
                'ends_at' => ['required', 'date_format:H:i'],
                'description' => ['nullable', 'string'],
            ]
        );

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        // 2) Validate tenant ownership (course and teacher belong to current business)
        $course = Course::find($courseId);
        $teacher = Teacher::find($teacherId);

        if (! $course) {
            throw ValidationException::withMessages([
                'course_id' => __('schedule.course_not_exists'),
            ]);
        }

        if (! $teacher) {
            throw ValidationException::withMessages([
                'teacher_id' => __('schedule.teacher_not_exists'),
            ]);
        }

        // 3) Validate time range
        $start = Carbon::createFromFormat('H:i', $data['starts_at']);
        $end = Carbon::createFromFormat('H:i', $data['ends_at']);

        if (! $start->lt($end)) {
            throw ValidationException::withMessages([
                'time' => __('schedule.starts_before_ends'),
            ]);
        }

        // 4) Overlap check for TEACHER (required)
        // Prevents the same teacher from teaching multiple courses at the same time
        $teacherOverlaps = Schedule::query()
            ->where('teacher_id', $teacherId)
            ->where('day_of_week', $data['day_of_week'])
            ->where(function ($q) use ($start, $end) {
                // Overlaps when (start < existing_end) AND (end > existing_start)
                $q->where('starts_at', '<', $end->format('H:i:00'))
                    ->where('ends_at', '>', $start->format('H:i:00'));
            })
            ->exists();

        if ($teacherOverlaps) {
            throw ValidationException::withMessages([
                'overlap' => __('schedule.teacher_overlap'),
            ]);
        }

        // 5) Optional: Overlap check for COURSE
        // Prevents the same course from having overlapping schedules
        // NOTE: This validation is currently DISABLED by default to allow multiple teachers
        // teaching the same course at the same time (parallel sections).
        // Uncomment if your business logic requires strict course-time uniqueness.
        /*
        $courseOverlaps = Schedule::query()
            ->where('course_id', $courseId)
            ->where('day_of_week', $data['day_of_week'])
            ->where(function ($q) use ($start, $end) {
                $q->where('starts_at', '<', $end->format('H:i:00'))
                    ->where('ends_at', '>', $start->format('H:i:00'));
            })
            ->exists();

        if ($courseOverlaps) {
            throw ValidationException::withMessages([
                'overlap' => 'Course already has a schedule at this time on this day',
                'code' => self::ERROR_OVERLAP_COURSE,
            ]);
        }
        */

        // 6) Create with new group_id
        // business_id is set automatically by BelongsToBusiness trait
        return Schedule::create([
            'course_id' => $courseId,
            'teacher_id' => $teacherId,
            'day_of_week' => $data['day_of_week'],
            'starts_at' => $start->format('H:i:00'),
            'ends_at' => $end->format('H:i:00'),
            'description' => $data['description'] ?? null,
            'group_id' => (string) Str::uuid(),
        ]);
    }

    /**
     * @param  array  $data  ['course_id' => int, 'teacher_id' => int, 'day_of_week' => int 0..6, 'starts_at' => 'HH:MM', 'ends_at' => 'HH:MM', 'description' => string|null]
     */
    public function updateSchedule(int $scheduleId, array $data): Schedule
    {
        // Find schedule (already scoped by business via global scope)
        $schedule = Schedule::findOrFail($scheduleId);

        // Merge current values with new data to get complete set for validation
        // Normalize time format from database (HH:MM:SS) to HH:MM if needed
        $mergedData = [
            'course_id' => $data['course_id'] ?? $schedule->course_id,
            'teacher_id' => $data['teacher_id'] ?? $schedule->teacher_id,
            'day_of_week' => $data['day_of_week'] ?? $schedule->day_of_week,
            'starts_at' => $data['starts_at'] ?? substr($schedule->starts_at, 0, 5),
            'ends_at' => $data['ends_at'] ?? substr($schedule->ends_at, 0, 5),
        ];

        // 1) Basic validation
        $v = Validator::make(
            $mergedData + ['description' => $data['description'] ?? null],
            [
                'course_id' => ['required', 'integer', 'exists:courses,id'],
                'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
                'day_of_week' => ['required', 'integer', 'between:0,6'],
                'starts_at' => ['required', 'date_format:H:i'],
                'ends_at' => ['required', 'date_format:H:i'],
                'description' => ['nullable', 'string'],
            ]
        );

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        // 2) Validate tenant ownership if course_id or teacher_id changed
        if (array_key_exists('course_id', $data) && $data['course_id'] != $schedule->course_id) {
            $course = Course::find($mergedData['course_id']);
            if (! $course) {
                throw ValidationException::withMessages([
                    'course_id' => __('schedule.course_not_exists'),
                ]);
            }
        }

        if (array_key_exists('teacher_id', $data) && $data['teacher_id'] != $schedule->teacher_id) {
            $teacher = Teacher::find($mergedData['teacher_id']);
            if (! $teacher) {
                throw ValidationException::withMessages([
                    'teacher_id' => __('schedule.teacher_not_exists'),
                ]);
            }
        }

        // 3) Validate time range
        $start = Carbon::createFromFormat('H:i', $mergedData['starts_at']);
        $end = Carbon::createFromFormat('H:i', $mergedData['ends_at']);

        if (! $start->lt($end)) {
            throw ValidationException::withMessages([
                'time' => __('schedule.starts_before_ends'),
            ]);
        }

        // 4) Overlap check for TEACHER (required)
        // Prevents the same teacher from teaching multiple courses at the same time
        $teacherOverlaps = Schedule::query()
            ->where('id', '!=', $scheduleId)  // Exclude current schedule
            ->where('teacher_id', $mergedData['teacher_id'])
            ->where('day_of_week', $mergedData['day_of_week'])
            ->where(function ($q) use ($start, $end) {
                $q->where('starts_at', '<', $end->format('H:i:00'))
                    ->where('ends_at', '>', $start->format('H:i:00'));
            })
            ->exists();

        if ($teacherOverlaps) {
            throw ValidationException::withMessages([
                'overlap' => __('schedule.teacher_overlap'),
            ]);
        }

        // 5) Optional: Overlap check for COURSE
        // Prevents the same course from having overlapping schedules
        // NOTE: This validation is currently DISABLED by default to allow multiple teachers
        // teaching the same course at the same time (parallel sections).
        // Uncomment if your business logic requires strict course-time uniqueness.
        /*
        $courseOverlaps = Schedule::query()
            ->where('id', '!=', $scheduleId)
            ->where('course_id', $mergedData['course_id'])
            ->where('day_of_week', $mergedData['day_of_week'])
            ->where(function ($q) use ($start, $end) {
                $q->where('starts_at', '<', $end->format('H:i:00'))
                    ->where('ends_at', '>', $start->format('H:i:00'));
            })
            ->exists();

        if ($courseOverlaps) {
            throw ValidationException::withMessages([
                'overlap' => 'Course already has a schedule at this time on this day',
                'code' => self::ERROR_OVERLAP_COURSE,
            ]);
        }
        */

        // 6) Update
        $updateData = [
            'course_id' => $mergedData['course_id'],
            'teacher_id' => $mergedData['teacher_id'],
            'day_of_week' => $mergedData['day_of_week'],
            'starts_at' => $start->format('H:i:00'),
            'ends_at' => $end->format('H:i:00'),
        ];

        // Only update description if it's explicitly provided in the input
        if (array_key_exists('description', $data)) {
            $updateData['description'] = $data['description'];
        }

        // Only update group_id if it's explicitly provided in the input
        if (array_key_exists('group_id', $data)) {
            $updateData['group_id'] = $data['group_id'];
        }

        $schedule->update($updateData);

        return $schedule->fresh();
    }
}
