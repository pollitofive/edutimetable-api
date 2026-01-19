<?php

namespace App\GraphQL\Mutations;

use App\GraphQL\Validators\Concerns\ValidatesAvailabilityOverlaps;
use App\Models\Student;
use App\Models\StudentAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkCreateStudentAvailabilities
{
    use ValidatesAvailabilityOverlaps;

    /**
     * Create multiple student availabilities in bulk
     *
     * @param  null  $_
     * @return \Illuminate\Support\Collection
     *
     * @throws ValidationException
     */
    public function __invoke($_, array $args)
    {
        $studentId = (int) $args['student_id'];
        $availabilities = $args['availabilities'];

        // Validate at least one availability is provided
        if (empty($availabilities)) {
            throw ValidationException::withMessages([
                'availabilities' => ['At least one availability slot is required.'],
            ]);
        }

        // Verify student exists
        $student = Student::findOrFail($studentId);

        // Validate all slots before processing
        $this->validateAvailabilities($student->id, $availabilities);

        $createdAvailabilities = DB::transaction(function () use ($student, $availabilities) {
            $created = collect();

            foreach ($availabilities as $availability) {
                // Create availability
                $created->push(
                    StudentAvailability::create([
                        'student_id' => $student->id,
                        'day_of_week' => (int) $availability['day_of_week'],
                        'start_time' => $availability['start_time'],
                        'end_time' => $availability['end_time'],
                    ])
                );
            }

            return $created;
        });

        return $createdAvailabilities->all();
    }

    /**
     * Validate all availabilities for overlaps and time constraints
     *
     * @throws ValidationException
     */
    protected function validateAvailabilities(int $studentId, array $availabilities): void
    {
        foreach ($availabilities as $index => $availability) {
            $slotNumber = $index + 1;
            $dayName = $this->getDayName((int) $availability['day_of_week']);

            // Validate time constraint
            if ($availability['start_time'] >= $availability['end_time']) {
                throw ValidationException::withMessages([
                    'availabilities' => [
                        sprintf(
                            'Slot #%d (%s %s-%s): End time must be after start time.',
                            $slotNumber,
                            $dayName,
                            $availability['start_time'],
                            $availability['end_time']
                        ),
                    ],
                ]);
            }

            // Check for overlaps with existing database slots
            $overlapping = $this->findOverlappingAvailability(
                $studentId,
                (int) $availability['day_of_week'],
                $availability['start_time'],
                $availability['end_time']
            );

            if ($overlapping) {
                throw ValidationException::withMessages([
                    'availabilities' => [
                        sprintf(
                            'Slot #%d (%s %s-%s) overlaps with an existing availability from %s to %s.',
                            $slotNumber,
                            $dayName,
                            substr($availability['start_time'], 0, 5),
                            substr($availability['end_time'], 0, 5),
                            substr($overlapping->start_time, 0, 5),
                            substr($overlapping->end_time, 0, 5)
                        ),
                    ],
                ]);
            }

            // Check for overlaps within the input array itself
            for ($j = 0; $j < $index; $j++) {
                $otherSlot = $availabilities[$j];
                $otherSlotNumber = $j + 1;

                if ($this->slotsOverlap(
                    (int) $availability['day_of_week'],
                    $availability['start_time'],
                    $availability['end_time'],
                    (int) $otherSlot['day_of_week'],
                    $otherSlot['start_time'],
                    $otherSlot['end_time']
                )) {
                    $otherDayName = $this->getDayName((int) $otherSlot['day_of_week']);
                    throw ValidationException::withMessages([
                        'availabilities' => [
                            sprintf(
                                'Slot #%d (%s %s-%s) overlaps with Slot #%d (%s %s-%s).',
                                $slotNumber,
                                $dayName,
                                substr($availability['start_time'], 0, 5),
                                substr($availability['end_time'], 0, 5),
                                $otherSlotNumber,
                                $otherDayName,
                                substr($otherSlot['start_time'], 0, 5),
                                substr($otherSlot['end_time'], 0, 5)
                            ),
                        ],
                    ]);
                }
            }
        }
    }
}
