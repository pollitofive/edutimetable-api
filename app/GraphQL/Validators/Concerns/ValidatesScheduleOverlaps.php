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
     * CHANGED: Now checks for TEACHER overlap instead of COURSE overlap
     * This prevents the same teacher from teaching multiple courses at the same time
     *
     * @param  int|null  $excludeId  ID to exclude from check (for updates)
     */
    protected function hasOverlapWithDatabase(
        int $teacherId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        ?int $excludeId = null
    ): bool {
        $query = Schedule::where('teacher_id', $teacherId)
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
     */
    protected function getDayName(int $dayOfWeek): string
    {
        $days = __('schedule.days');

        return $days[$dayOfWeek] ?? "Day $dayOfWeek";
    }

    /**
     * Find the overlapping schedule from database
     *
     * CHANGED: Now checks for TEACHER overlap instead of COURSE overlap
     */
    protected function findOverlappingSchedule(
        int $teacherId,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        ?int $excludeId = null
    ): ?Schedule {
        $query = Schedule::where('teacher_id', $teacherId)
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
