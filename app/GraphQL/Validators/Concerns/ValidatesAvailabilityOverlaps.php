<?php

namespace App\GraphQL\Validators\Concerns;

use App\Models\StudentAvailability;

trait ValidatesAvailabilityOverlaps
{
    /**
     * Check if a time slot overlaps with existing availabilities
     *
     * Two time slots overlap if:
     * (start_time_1 < end_time_2) AND (end_time_1 > start_time_2)
     *
     * @param  int|null  $excludeId  ID to exclude from check (for updates)
     */
    protected function hasOverlapWithDatabase(
        int $studentId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        ?int $excludeId = null
    ): bool {
        $query = StudentAvailability::where('student_id', $studentId)
            ->where('day_of_week', $dayOfWeek)
            ->where(function ($q) use ($startTime, $endTime) {
                // Overlap condition: new slot overlaps if it starts before existing ends
                // AND ends after existing starts
                $q->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Check if two time slots overlap with each other
     */
    protected function slotsOverlap(
        int $dayOfWeek1,
        string $startTime1,
        string $endTime1,
        int $dayOfWeek2,
        string $startTime2,
        string $endTime2
    ): bool {
        // Different days can't overlap
        if ($dayOfWeek1 !== $dayOfWeek2) {
            return false;
        }

        // Check overlap: slot1 starts before slot2 ends AND slot1 ends after slot2 starts
        return $startTime1 < $endTime2 && $endTime1 > $startTime2;
    }

    /**
     * Get a human-readable day name (translated)
     */
    protected function getDayName(int $dayOfWeek): string
    {
        return __("availability.days.$dayOfWeek") ?? "Day $dayOfWeek";
    }

    /**
     * Find the overlapping availability from database
     */
    protected function findOverlappingAvailability(
        int $studentId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        ?int $excludeId = null
    ): ?StudentAvailability {
        $query = StudentAvailability::where('student_id', $studentId)
            ->where('day_of_week', $dayOfWeek)
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }
}
