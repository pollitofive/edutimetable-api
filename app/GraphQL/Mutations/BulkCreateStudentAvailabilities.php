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
                'availabilities' => [__('availability.at_least_one')],
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
                        __('availability.end_after_start', [
                            'slot' => $slotNumber,
                            'day' => $dayName,
                            'start' => $availability['start_time'],
                            'end' => $availability['end_time'],
                        ]),
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
                        __('availability.overlap_bulk', [
                            'slot' => $slotNumber,
                            'day' => $dayName,
                            'slot_start' => substr($availability['start_time'], 0, 5),
                            'slot_end' => substr($availability['end_time'], 0, 5),
                            'start' => substr($overlapping->start_time, 0, 5),
                            'end' => substr($overlapping->end_time, 0, 5),
                        ]),
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
                            __('availability.overlap_slots', [
                                'slot1' => $slotNumber,
                                'day1' => $dayName,
                                'start1' => substr($availability['start_time'], 0, 5),
                                'end1' => substr($availability['end_time'], 0, 5),
                                'slot2' => $otherSlotNumber,
                                'day2' => $otherDayName,
                                'start2' => substr($otherSlot['start_time'], 0, 5),
                                'end2' => substr($otherSlot['end_time'], 0, 5),
                            ]),
                        ],
                    ]);
                }
            }
        }
    }
}
