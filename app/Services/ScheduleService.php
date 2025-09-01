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
}
