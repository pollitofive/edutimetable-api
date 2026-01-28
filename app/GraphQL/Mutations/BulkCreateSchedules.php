<?php

namespace App\GraphQL\Mutations;

use App\GraphQL\Validators\Concerns\ValidatesScheduleOverlaps;
use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BulkCreateSchedules
{
    use ValidatesScheduleOverlaps;

    /**
     * Create multiple schedules in bulk for a course
     *
     * @param  null  $_
     * @return \Illuminate\Support\Collection
     *
     * @throws ValidationException
     */
    public function __invoke($_, array $args)
    {
        $courseId = (int) $args['course_id'];
        $description = $args['description']; // Required field
        $schedules = $args['schedules'];

        // Validate at least one schedule is provided
        if (empty($schedules)) {
            throw ValidationException::withMessages([
                'schedules' => [__('schedule.at_least_one')],
            ]);
        }

        // Verify course exists
        $course = Course::findOrFail($courseId);

        // Validate all slots before processing
        $this->validateSchedules($course->id, $schedules);

        $createdSchedules = DB::transaction(function () use ($course, $description, $schedules) {
            $created = collect();

            // Generate a single UUID for all schedules in this batch
            $groupId = (string) Str::uuid();

            foreach ($schedules as $schedule) {
                // Normalize time format to HH:MM:SS for database
                $startsAt = $this->normalizeTime($schedule['starts_at']);
                $endsAt = $this->normalizeTime($schedule['ends_at']);

                // Create schedule with teacher_id, description, and group_id
                $created->push(
                    Schedule::create([
                        'course_id' => $course->id,
                        'teacher_id' => (int) $schedule['teacher_id'],
                        'day_of_week' => (int) $schedule['day_of_week'],
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'description' => $description,
                        'group_id' => $groupId,
                    ])
                );
            }

            return $created;
        });

        return $createdSchedules->all();
    }

    /**
     * Validate all schedules for overlaps and time constraints
     *
     * CHANGED: Now validates TEACHER conflicts instead of COURSE conflicts
     *
     * @throws ValidationException
     */
    protected function validateSchedules(int $courseId, array $schedules): void
    {
        foreach ($schedules as $index => $schedule) {
            $slotNumber = $index + 1;
            $dayName = $this->getDayName((int) $schedule['day_of_week']);

            // Validate teacher_id is present
            if (! isset($schedule['teacher_id'])) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        __('schedule.teacher_required', [
                            'slot' => $slotNumber,
                            'day' => $dayName,
                            'start' => $schedule['starts_at'],
                            'end' => $schedule['ends_at'],
                        ]),
                    ],
                ]);
            }

            // Validate time constraint (end time must be after start time)
            if ($schedule['starts_at'] >= $schedule['ends_at']) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        __('schedule.end_after_start', [
                            'slot' => $slotNumber,
                            'day' => $dayName,
                            'start' => $schedule['starts_at'],
                            'end' => $schedule['ends_at'],
                        ]),
                    ],
                ]);
            }

            // CHANGED: Check for overlaps with existing database schedules based on TEACHER
            $overlapping = $this->findOverlappingSchedule(
                (int) $schedule['teacher_id'],  // CHANGED from courseId to teacherId
                (int) $schedule['day_of_week'],
                $this->normalizeTime($schedule['starts_at']),
                $this->normalizeTime($schedule['ends_at'])
            );

            if ($overlapping) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        __('schedule.teacher_overlap_db', [
                            'slot' => $slotNumber,
                            'day' => $dayName,
                            'start' => $schedule['starts_at'],
                            'end' => $schedule['ends_at'],
                            'existing_start' => substr($overlapping->starts_at, 0, 5),
                            'existing_end' => substr($overlapping->ends_at, 0, 5),
                        ]),
                    ],
                ]);
            }

            // CHANGED: Check for teacher conflicts within the input array itself
            // Same teacher cannot teach multiple slots at the same time
            for ($j = 0; $j < $index; $j++) {
                $otherSlot = $schedules[$j];
                $otherSlotNumber = $j + 1;

                // Only check if same teacher
                if ((int) $schedule['teacher_id'] === (int) $otherSlot['teacher_id']) {
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
                                __('schedule.teacher_conflict_slots', [
                                    'slot1' => $slotNumber,
                                    'day1' => $dayName,
                                    'start1' => $schedule['starts_at'],
                                    'end1' => $schedule['ends_at'],
                                    'slot2' => $otherSlotNumber,
                                    'day2' => $otherDayName,
                                    'start2' => $otherSlot['starts_at'],
                                    'end2' => $otherSlot['ends_at'],
                                ]),
                            ],
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Normalize time from HH:MM to HH:MM:SS format
     */
    protected function normalizeTime(string $time): string
    {
        // If time is already in HH:MM:SS format, return as is
        if (substr_count($time, ':') === 2) {
            return $time;
        }

        // Convert HH:MM to HH:MM:SS
        return $time.':00';
    }
}
