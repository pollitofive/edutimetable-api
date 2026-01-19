<?php

namespace App\GraphQL\Mutations;

use App\Models\StudentEnrollment;
use Illuminate\Validation\ValidationException;

class UpdateStudentEnrollment
{
    public function __invoke($_, array $args): StudentEnrollment
    {
        $enrollment = StudentEnrollment::find($args['id']);

        if (! $enrollment) {
            throw ValidationException::withMessages([
                'id' => ['Enrollment not found'],
            ]);
        }

        // When using @spread, the input fields are spread into $args directly
        // Remove the 'id' from data as it's not part of the input
        $data = $args;
        unset($data['id']);

        // If changing schedule_id or student_id, validate the change
        if (isset($data['schedule_id']) || isset($data['student_id'])) {
            $newStudentId = $data['student_id'] ?? $enrollment->student_id;
            $newScheduleId = $data['schedule_id'] ?? $enrollment->schedule_id;

            // Check if this would create a duplicate enrollment
            $existingEnrollment = StudentEnrollment::where('student_id', $newStudentId)
                ->where('schedule_id', $newScheduleId)
                ->where('id', '!=', $enrollment->id)
                ->first();

            if ($existingEnrollment) {
                throw ValidationException::withMessages([
                    'schedule_id' => ['This enrollment combination already exists'],
                ]);
            }
        }

        // Update the enrollment
        $enrollment->update(array_filter([
            'student_id' => $data['student_id'] ?? null,
            'schedule_id' => $data['schedule_id'] ?? null,
            'enrolled_at' => $data['enrolled_at'] ?? null,
            'status' => $data['status'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($value) => $value !== null));

        return $enrollment->fresh();
    }
}
