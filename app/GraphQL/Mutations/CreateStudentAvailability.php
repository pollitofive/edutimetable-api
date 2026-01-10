<?php

namespace App\GraphQL\Mutations;

use App\GraphQL\Validators\Concerns\ValidatesAvailabilityOverlaps;
use App\Models\StudentAvailability;
use Illuminate\Validation\ValidationException;

class CreateStudentAvailability
{
    use ValidatesAvailabilityOverlaps;

    /**
     * Create a student availability with overlap validation
     *
     * @param  null  $_
     * @param  array  $args
     * @return StudentAvailability
     * @throws ValidationException
     */
    public function __invoke($_, array $args)
    {
        // Validate time constraint
        if ($args['start_time'] >= $args['end_time']) {
            throw ValidationException::withMessages([
                'end_time' => ['End time must be after start time.']
            ]);
        }

        // Check for overlaps
        $overlapping = $this->findOverlappingAvailability(
            (int) $args['student_id'],
            (int) $args['day_of_week'],
            $args['start_time'],
            $args['end_time']
        );

        if ($overlapping) {
            $dayName = $this->getDayName((int) $args['day_of_week']);
            throw ValidationException::withMessages([
                'time_slot' => [
                    sprintf(
                        'This availability overlaps with an existing slot on %s from %s to %s.',
                        $dayName,
                        substr($overlapping->start_time, 0, 5),
                        substr($overlapping->end_time, 0, 5)
                    )
                ]
            ]);
        }

        // Create the availability
        return StudentAvailability::create([
            'student_id' => (int) $args['student_id'],
            'day_of_week' => (int) $args['day_of_week'],
            'start_time' => $args['start_time'],
            'end_time' => $args['end_time'],
        ]);
    }
}