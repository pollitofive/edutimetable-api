<?php

namespace App\Services;

use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    /**
     * @param int   $courseId
     * @param int   $teacherId
     * @param array $data ['day_of_week' => int 0..6, 'starts_at' => 'HH:MM', 'ends_at' => 'HH:MM', 'description' => string|null]
     */
    public function createSchedule(int $courseId, int $teacherId, array $data): Schedule
    {
        // 1) Basic validation
        $v = Validator::make(
            ['course_id' => $courseId, 'teacher_id' => $teacherId] + $data,
            [
                'course_id'   => ['required','integer','exists:courses,id'],
                'teacher_id'  => ['required','integer','exists:teachers,id'],
                'day_of_week' => ['required','integer','between:0,6'],
                'starts_at'   => ['required','date_format:H:i'],
                'ends_at'     => ['required','date_format:H:i'],
                'description' => ['nullable','string'],
            ]
        );

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $start = Carbon::createFromFormat('H:i', $data['starts_at']);
        $end   = Carbon::createFromFormat('H:i', $data['ends_at']);

        if (! $start->lt($end)) {
            throw ValidationException::withMessages(['time' => 'starts_at must be before ends_at']);
        }

        // 2) Overlap check - CHANGED: Check for same TEACHER + same day (not same course)
        // This prevents the same teacher from teaching multiple courses at the same time
        // But allows the same course to be taught by different teachers at the same time
        $overlaps = Schedule::query()
            ->where('teacher_id', $teacherId)
            ->where('day_of_week', $data['day_of_week'])
            ->where(function ($q) use ($start, $end) {
                // NOT (end <= existing_start OR start >= existing_end)
                // => overlaps when (start < existing_end) AND (end > existing_start)
                $q->where('starts_at', '<', $end->format('H:i:00'))
                    ->where('ends_at',   '>', $start->format('H:i:00'));
            })
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['overlap' => 'Teacher already has a schedule at this time on this day']);
        }

        // 3) Create with new group_id
        return Schedule::create([
            'course_id'   => $courseId,
            'teacher_id'  => $teacherId,
            'day_of_week' => $data['day_of_week'],
            'starts_at'   => $start->format('H:i:00'),
            'ends_at'     => $end->format('H:i:00'),
            'description' => $data['description'] ?? null,
            'group_id'    => (string) Str::uuid(),
        ]);
    }

    /**
     * @param int   $scheduleId
     * @param array $data ['course_id' => int, 'teacher_id' => int, 'day_of_week' => int 0..6, 'starts_at' => 'HH:MM', 'ends_at' => 'HH:MM', 'description' => string|null]
     */
    public function updateSchedule(int $scheduleId, array $data): Schedule
    {
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
                'course_id'   => ['required','integer','exists:courses,id'],
                'teacher_id'  => ['required','integer','exists:teachers,id'],
                'day_of_week' => ['required','integer','between:0,6'],
                'starts_at'   => ['required','date_format:H:i'],
                'ends_at'     => ['required','date_format:H:i'],
                'description' => ['nullable','string'],
            ]
        );

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $start = Carbon::createFromFormat('H:i', $mergedData['starts_at']);
        $end   = Carbon::createFromFormat('H:i', $mergedData['ends_at']);

        if (! $start->lt($end)) {
            throw ValidationException::withMessages(['time' => 'starts_at must be before ends_at']);
        }

        // 2) Overlap check - CHANGED: Check for same TEACHER + same day (not same course)
        // This prevents the same teacher from teaching multiple courses at the same time
        // But allows the same course to be taught by different teachers at the same time
        $overlaps = Schedule::query()
            ->where('id', '!=', $scheduleId)  // Exclude current schedule
            ->where('teacher_id', $mergedData['teacher_id'])
            ->where('day_of_week', $mergedData['day_of_week'])
            ->where(function ($q) use ($start, $end) {
                $q->where('starts_at', '<', $end->format('H:i:00'))
                    ->where('ends_at',   '>', $start->format('H:i:00'));
            })
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['overlap' => 'Teacher already has a schedule at this time on this day']);
        }

        // 3) Update
        $updateData = [
            'course_id'   => $mergedData['course_id'],
            'teacher_id'  => $mergedData['teacher_id'],
            'day_of_week' => $mergedData['day_of_week'],
            'starts_at'   => $start->format('H:i:00'),
            'ends_at'     => $end->format('H:i:00'),
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
