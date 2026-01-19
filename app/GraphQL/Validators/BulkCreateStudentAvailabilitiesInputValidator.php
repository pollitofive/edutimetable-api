<?php

namespace App\GraphQL\Validators;

use Illuminate\Validation\Validator;

class BulkCreateStudentAvailabilitiesInputValidator
{
    public function __invoke(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            if (isset($data['availabilities']) && is_array($data['availabilities'])) {
                foreach ($data['availabilities'] as $index => $availability) {
                    // Validate each availability slot's time constraint
                    if (isset($availability['start_time']) && isset($availability['end_time'])) {
                        if ($availability['start_time'] >= $availability['end_time']) {
                            $validator->errors()->add(
                                "availabilities.{$index}.end_time",
                                'End time must be after start time.'
                            );
                        }
                    }
                }
            }
        });
    }
}
