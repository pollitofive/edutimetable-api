<?php

namespace App\GraphQL\Mutations;

use App\GraphQL\Validators\Concerns\ValidatesScheduleOverlaps;
use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BulkUpdateSchedules
{
    use ValidatesScheduleOverlaps;

    /**
     * Replace all course schedules with new ones
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
        $groupId = $args['group_id'] ?? null; // Optional - sent by frontend for updates
        $schedules = $args['schedules'];

        // Validate at least one schedule is provided
        if (empty($schedules)) {
            throw ValidationException::withMessages([
                'schedules' => ['At least one schedule slot is required.'],
            ]);
        }

        // Verify course exists
        $course = Course::findOrFail($courseId);

        // Validate all slots before processing
        // Check both internal overlaps and database overlaps with other courses
        $this->validateSchedules($course->id, $schedules, $groupId);

        $createdSchedules = DB::transaction(function () use ($course, $description, $groupId, $schedules) {
            // If group_id is provided, delete schedules with that group_id
            // Otherwise, delete all schedules for this course (backward compatibility)
            if ($groupId) {
                Schedule::where('group_id', $groupId)->delete();
            } else {
                Schedule::where('course_id', $course->id)->delete();
            }

            // Use existing group_id or generate a new one
            $finalGroupId = $groupId ?? (string) Str::uuid();

            $created = collect();

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
                        'group_id' => $finalGroupId,
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
     * Checks both database overlaps (excluding current group) and internal overlaps
     *
     * @throws ValidationException
     */
    protected function validateSchedules(int $courseId, array $schedules, ?string $groupId = null): void
    {
        foreach ($schedules as $index => $schedule) {
            $slotNumber = $index + 1;
            $dayName = $this->getDayName((int) $schedule['day_of_week']);

            // Validate teacher_id is present
            if (! isset($schedule['teacher_id'])) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        sprintf(
                            'Schedule slot #%d (%s %s-%s): teacher_id is required.',
                            $slotNumber,
                            $dayName,
                            $schedule['starts_at'],
                            $schedule['ends_at']
                        ),
                    ],
                ]);
            }

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
                        ),
                    ],
                ]);
            }

            // Check for overlaps with existing database schedules
            // If group_id is provided, exclude schedules with that group_id
            // Otherwise, exclude schedules from the current course (backward compatibility)
            $query = Schedule::where('teacher_id', (int) $schedule['teacher_id'])
                ->where('day_of_week', (int) $schedule['day_of_week'])
                ->where(function ($q) use ($schedule) {
                    $startsAt = $this->normalizeTime($schedule['starts_at']);
                    $endsAt = $this->normalizeTime($schedule['ends_at']);
                    $q->where('starts_at', '<', $endsAt)
                        ->where('ends_at', '>', $startsAt);
                });

            // Exclude schedules that will be replaced
            if ($groupId) {
                $query->where('group_id', '!=', $groupId);
            } else {
                $query->where('course_id', '!=', $courseId);
            }

            $overlapping = $query->first();

            if ($overlapping) {
                throw ValidationException::withMessages([
                    'schedules' => [
                        sprintf(
                            'Schedule slot #%d (%s %s-%s): Teacher already has a schedule from %s to %s for another course.',
                            $slotNumber,
                            $dayName,
                            $schedule['starts_at'],
                            $schedule['ends_at'],
                            substr($overlapping->starts_at, 0, 5),
                            substr($overlapping->ends_at, 0, 5)
                        ),
                    ],
                ]);
            }

            // Check for teacher conflicts within the input array
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
                                sprintf(
                                    'Schedule slot #%d (%s %s-%s): Teacher conflict with slot #%d (%s %s-%s).',
                                    $slotNumber,
                                    $dayName,
                                    $schedule['starts_at'],
                                    $schedule['ends_at'],
                                    $otherSlotNumber,
                                    $otherDayName,
                                    $otherSlot['starts_at'],
                                    $otherSlot['ends_at']
                                ),
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
