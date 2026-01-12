<?php

namespace App\GraphQL\Mutations;

use App\GraphQL\Validators\Concerns\ValidatesScheduleOverlaps;
use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkUpdateSchedules
{
    use ValidatesScheduleOverlaps;

    /**
     * Replace all course schedules with new ones
     *
     * @param  null  $_
     * @param  array  $args
     * @return \Illuminate\Support\Collection
     * @throws ValidationException
     */
    public function __invoke($_, array $args)
    {
        $courseId = (int) $args['course_id'];
        $schedules = $args['schedules'];

        // Validate at least one schedule is provided
        if (empty($schedules)) {
            throw ValidationException::withMessages([
                'schedules' => ['At least one schedule slot is required.']
            ]);
        }

        // Verify course exists
        $course = Course::findOrFail($courseId);

        // Validate all slots before processing
        // Only check internal overlaps since we're replacing everything
        $this->validateInternalOverlaps($schedules);

        $createdSchedules = DB::transaction(function () use ($course, $schedules) {
            // Delete all existing schedules for this course
            Schedule::where('course_id', $course->id)->delete();

            $created = collect();

            foreach ($schedules as $schedule) {
                // Normalize time format to HH:MM:SS for database
                $startsAt = $this->normalizeTime($schedule['starts_at']);
                $endsAt = $this->normalizeTime($schedule['ends_at']);

                // Create schedule
                $created->push(
                    Schedule::create([
                        'course_id' => $course->id,
                        'day_of_week' => (int) $schedule['day_of_week'],
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ])
                );
            }

            return $created;
        });

        return $createdSchedules->all();
    }

    /**
     * Validate that schedule slots don't overlap within the input array
     *
     * @param array $schedules
     * @throws ValidationException
     */
    protected function validateInternalOverlaps(array $schedules): void
    {
        foreach ($schedules as $index => $schedule) {
            $slotNumber = $index + 1;
            $dayName = $this->getDayName((int) $schedule['day_of_week']);

            // Validate time constraint (end time must be after start time)
            if ($schedule['starts_at'] >= $schedule['ends_at']) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        sprintf(
                            'Schedule slot #%d (%s %s-%s): End time must be after start time.',
                            $slotNumber,
                            $dayName,
                            $schedule['starts_at'],
                            $schedule['ends_at']
                        )
                    ]
                ]);
            }

            // Check for overlaps within the input array itself
            for ($j = 0; $j < $index; $j++) {
                $otherSlot = $schedules[$j];
                $otherSlotNumber = $j + 1;

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
                            sprintf(
                                'Schedule slot #%d (%s %s-%s) overlaps with slot #%d (%s %s-%s).',
                                $slotNumber,
                                $dayName,
                                $schedule['starts_at'],
                                $schedule['ends_at'],
                                $otherSlotNumber,
                                $otherDayName,
                                $otherSlot['starts_at'],
                                $otherSlot['ends_at']
                            )
                        ]
                    ]);
                }
            }
        }
    }

    /**
     * Normalize time from HH:MM to HH:MM:SS format
     *
     * @param string $time
     * @return string
     */
    protected function normalizeTime(string $time): string
    {
        // If time is already in HH:MM:SS format, return as is
        if (substr_count($time, ':') === 2) {
            return $time;
        }

        // Convert HH:MM to HH:MM:SS
        return $time . ':00';
    }
}