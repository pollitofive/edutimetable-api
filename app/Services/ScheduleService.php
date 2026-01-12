<?php

namespace App\Services;

use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    /**
     * @param int   $courseId
     * @param array $data ['day_of_week' => int 0..6, 'starts_at' => 'HH:MM', 'ends_at' => 'HH:MM']
     */
    public function createSchedule(int $courseId, array $data): Schedule
    {
        // 1) Basic validation
        $v = Validator::make(
            ['course_id' => $courseId] + $data,
            [
                'course_id'   => ['required','integer','exists:courses,id'],
                'day_of_week' => ['required','integer','between:0,6'],
                'starts_at'   => ['required','date_format:H:i'],
                'ends_at'     => ['required','date_format:H:i'],
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

        // 2) Overlap check (same course + same day)
        $overlaps = Schedule::query()
            ->where('course_id', $courseId)
            ->where('day_of_week', $data['day_of_week'])
            ->where(function ($q) use ($start, $end) {
                // NOT (end <= existing_start OR start >= existing_end)
                // => overlaps when (start < existing_end) AND (end > existing_start)
                $q->where('starts_at', '<', $end->format('H:i:00'))
                    ->where('ends_at',   '>', $start->format('H:i:00'));
            })
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['overlap' => 'Schedule overlaps an existing timeslot for this course and day']);
        }

        // 3) Create
        return Schedule::create([
            'course_id'   => $courseId,
            'day_of_week' => $data['day_of_week'],
            'starts_at'   => $start->format('H:i:00'),
            'ends_at'     => $end->format('H:i:00'),
        ]);
    }

    /**
     * @param int   $scheduleId
     * @param array $data ['course_id' => int, 'day_of_week' => int 0..6, 'starts_at' => 'HH:MM', 'ends_at' => 'HH:MM']
     */
    public function updateSchedule(int $scheduleId, array $data): Schedule
    {
        $schedule = Schedule::findOrFail($scheduleId);

        // Merge current values with new data to get complete set for validation
        // Normalize time format from database (HH:MM:SS) to HH:MM if needed
        $mergedData = [
            'course_id' => $data['course_id'] ?? $schedule->course_id,
            'day_of_week' => $data['day_of_week'] ?? $schedule->day_of_week,
            'starts_at' => $data['starts_at'] ?? substr($schedule->starts_at, 0, 5),
            'ends_at' => $data['ends_at'] ?? substr($schedule->ends_at, 0, 5),
        ];

        // 1) Basic validation
        $v = Validator::make(
            $mergedData,
            [
                'course_id'   => ['required','integer','exists:courses,id'],
                'day_of_week' => ['required','integer','between:0,6'],
                'starts_at'   => ['required','date_format:H:i'],
                'ends_at'     => ['required','date_format:H:i'],
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

        // 2) Overlap check (same course + same day, excluding current schedule)
        $overlaps = Schedule::query()
            ->where('id', '!=', $scheduleId)  // Exclude current schedule
            ->where('course_id', $mergedData['course_id'])
            ->where('day_of_week', $mergedData['day_of_week'])
            ->where(function ($q) use ($start, $end) {
                $q->where('starts_at', '<', $end->format('H:i:00'))
                    ->where('ends_at',   '>', $start->format('H:i:00'));
            })
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['overlap' => 'Schedule overlaps an existing timeslot for this course and day']);
        }

        // 3) Update
        $schedule->update([
            'course_id'   => $mergedData['course_id'],
            'day_of_week' => $mergedData['day_of_week'],
            'starts_at'   => $start->format('H:i:00'),
            'ends_at'     => $end->format('H:i:00'),
        ]);

        return $schedule->fresh();
    }
}
