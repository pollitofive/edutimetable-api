<?php

namespace App\GraphQL\Validators;

use App\GraphQL\Validators\Concerns\ValidatesAvailabilityOverlaps;
use App\Models\StudentAvailability;
use Illuminate\Validation\Validator;

class UpdateStudentAvailabilityInputValidator
{
    use ValidatesAvailabilityOverlaps;

    public function __invoke(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            // Validate end time is after start time
            if (isset($data['start_time']) && isset($data['end_time'])) {
                if ($data['start_time'] >= $data['end_time']) {
                    $validator->errors()->add(
                        'end_time',
                        'End time must be after start time.'
                    );

                    return; // Don't check overlaps if times are invalid
                }
            }

            // Check for overlapping availabilities
            // Need to get the ID being updated from the context
            $args = $validator->getData();

            // The 'id' comes from the mutation arguments (not the input)
            // We need to check if we're updating fields that could cause overlaps
            if (! isset($args['id'])) {
                return; // Can't validate without the ID
            }

            $availabilityId = (int) $args['id'];
            $availability = StudentAvailability::find($availabilityId);

            if (! $availability) {
                return; // Will be handled by model not found
            }

            // Determine the values to check (use existing if not provided in update)
            $studentId = isset($data['student_id']) ? (int) $data['student_id'] : $availability->student_id;
            $dayOfWeek = isset($data['day_of_week']) ? (int) $data['day_of_week'] : $availability->day_of_week;
            $startTime = $data['start_time'] ?? $availability->start_time;
            $endTime = $data['end_time'] ?? $availability->end_time;

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
                $validator->errors()->add(
                    'time_slot',
                    sprintf(
                        'This availability overlaps with an existing slot on %s from %s to %s.',
                        $dayName,
                        substr($overlapping->start_time, 0, 5),
                        substr($overlapping->end_time, 0, 5)
                    )
                );
            }
        });
    }
}
