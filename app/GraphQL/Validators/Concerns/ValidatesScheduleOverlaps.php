<?php

namespace App\GraphQL\Validators\Concerns;

use App\Models\Schedule;

trait ValidatesScheduleOverlaps
{
    /**
     * Check if a schedule time slot overlaps with existing schedules in the database
     *
     * Two time slots overlap if:
     * (starts_at_1 < ends_at_2) AND (ends_at_1 > starts_at_2)
     *
     * @param int $courseId
     * @param int $dayOfWeek
     * @param string $startsAt
     * @param string $endsAt
     * @param int|null $excludeId ID to exclude from check (for updates)
     * @return bool
     */
    protected function hasOverlapWithDatabase(
        int $courseId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        ?int $excludeId = null
    ): bool {
        $query = Schedule::where('course_id', $courseId)
            ->where('day_of_week', $dayOfWeek)
            ->where(function ($q) use ($startsAt, $endsAt) {
                // Overlap condition: new slot overlaps if it starts before existing ends
                // AND ends after existing starts
                $q->where('starts_at', '<', $endsAt)
                  ->where('ends_at', '>', $startsAt);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Check if two schedule time slots overlap with each other
     *
     * @param int $dayOfWeek1
     * @param string $startsAt1
     * @param string $endsAt1
     * @param int $dayOfWeek2
     * @param string $startsAt2
     * @param string $endsAt2
     * @return bool
     */
    protected function slotsOverlap(
        int $dayOfWeek1,
        string $startsAt1,
        string $endsAt1,
        int $dayOfWeek2,
        string $startsAt2,
        string $endsAt2
    ): bool {
        // Different days can't overlap
        if ($dayOfWeek1 !== $dayOfWeek2) {
            return false;
        }

        // Check overlap: slot1 starts before slot2 ends AND slot1 ends after slot2 starts
        return $startsAt1 < $endsAt2 && $endsAt1 > $startsAt2;
    }

    /**
     * Get a human-readable day name
     *
     * @param int $dayOfWeek
     * @return string
     */
    protected function getDayName(int $dayOfWeek): string
    {
        $days = [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];

        return $days[$dayOfWeek] ?? "Day $dayOfWeek";
    }

    /**
     * Find the overlapping schedule from database
     *
     * @param int $courseId
     * @param int $dayOfWeek
     * @param string $startsAt
     * @param string $endsAt
     * @param int|null $excludeId
     * @return Schedule|null
     */
    protected function findOverlappingSchedule(
        int $courseId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        ?int $excludeId = null
    ): ?Schedule {
        $query = Schedule::where('course_id', $courseId)
            ->where('day_of_week', $dayOfWeek)
            ->where(function ($q) use ($startsAt, $endsAt) {
                $q->where('starts_at', '<', $endsAt)
                  ->where('ends_at', '>', $startsAt);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }
}