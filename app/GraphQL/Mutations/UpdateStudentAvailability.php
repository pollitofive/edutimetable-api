<?php

namespace App\GraphQL\Mutations;

use App\GraphQL\Validators\Concerns\ValidatesAvailabilityOverlaps;
use App\Models\StudentAvailability;
use Illuminate\Validation\ValidationException;

class UpdateStudentAvailability
{
    use ValidatesAvailabilityOverlaps;

    /**
     * Update a student availability with overlap validation
     *
     * @param  null  $_
     * @return StudentAvailability
     *
     * @throws ValidationException
     */
    public function __invoke($_, array $args)
    {
        $availabilityId = (int) $args['id'];
        $availability = StudentAvailability::findOrFail($availabilityId);

        // Determine the values to check (use existing if not provided in update)
        $studentId = isset($args['student_id']) ? (int) $args['student_id'] : $availability->student_id;
        $dayOfWeek = isset($args['day_of_week']) ? (int) $args['day_of_week'] : $availability->day_of_week;
        $startTime = $args['start_time'] ?? $availability->start_time;
        $endTime = $args['end_time'] ?? $availability->end_time;

        // Validate time constraint
        if ($startTime >= $endTime) {
            throw ValidationException::withMessages([
                'end_time' => [__('availability.end_after_start_simple')],
            ]);
        }

        // Check for overlaps with other availabilities (excluding this one)
        $overlapping = $this->findOverlappingAvailability(
            $studentId,
            $dayOfWeek,
            $startTime,
            $endTime,
            $availabilityId
        );

        if ($overlapping) {
            $dayName = $this->getDayName($dayOfWeek);
            throw ValidationException::withMessages([
                'time_slot' => [
                    __('availability.overlap_single', [
                        'day' => $dayName,
                        'start' => substr($overlapping->start_time, 0, 5),
                        'end' => substr($overlapping->end_time, 0, 5),
                    ]),
                ],
            ]);
        }

        // Update the availability
        $availability->update([
            'student_id' => $studentId,
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        return $availability->fresh();
    }
}
