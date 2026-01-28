<?php

namespace App\GraphQL\Validators;

use App\GraphQL\Validators\Concerns\ValidatesAvailabilityOverlaps;
use Illuminate\Validation\Validator;

class CreateStudentAvailabilityInputValidator
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
                        __('availability.end_after_start_simple')
                    );

                    return; // Don't check overlaps if times are invalid
                }
            }

            // Check for overlapping availabilities
            if (isset($data['student_id'], $data['day_of_week'], $data['start_time'], $data['end_time'])) {
                $overlapping = $this->findOverlappingAvailability(
                    (int) $data['student_id'],
                    (int) $data['day_of_week'],
                    $data['start_time'],
                    $data['end_time']
                );

                if ($overlapping) {
                    $dayName = $this->getDayName((int) $data['day_of_week']);
                    $validator->errors()->add(
                        'time_slot',
                        __('availability.overlap_single', [
                            'day' => $dayName,
                            'start' => substr($overlapping->start_time, 0, 5),
                            'end' => substr($overlapping->end_time, 0, 5),
                        ])
                    );
                }
            }
        });
    }
}
