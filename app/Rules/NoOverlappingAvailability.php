<?php

namespace App\Rules;

use App\Models\StudentAvailability;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoOverlappingAvailability implements ValidationRule
{
    protected int $studentId;

    protected int $dayOfWeek;

    protected string $startTime;

    protected string $endTime;

    protected ?int $excludeId = null;

    public function __construct()
    {
        // Constructor intentionally empty - data will be set via setData()
    }

    /**
     * Set the validation data from the request
     */
    public function setData(array $data, ?int $excludeId = null): self
    {
        $this->studentId = (int) ($data['student_id'] ?? 0);
        $this->dayOfWeek = (int) ($data['day_of_week'] ?? 0);
        $this->startTime = $data['start_time'] ?? '';
        $this->endTime = $data['end_time'] ?? '';
        $this->excludeId = $excludeId;

        return $this;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // This rule is applied to the input object, but we need all fields
        // So we check if we have the required data
        if (empty($this->studentId) || empty($this->startTime) || empty($this->endTime)) {
            return; // Can't validate without full data
        }

        $overlapping = $this->findOverlappingAvailability();

        if ($overlapping) {
            $dayName = $this->getDayName($this->dayOfWeek);
            $fail(sprintf(
                'This availability overlaps with an existing slot on %s from %s to %s.',
                $dayName,
                substr($overlapping->start_time, 0, 5),
                substr($overlapping->end_time, 0, 5)
            ));
        }
    }

    protected function findOverlappingAvailability(): ?StudentAvailability
    {
        $query = StudentAvailability::where('student_id', $this->studentId)
            ->where('day_of_week', $this->dayOfWeek)
            ->where(function ($q) {
                // Overlap condition: new slot overlaps if it starts before existing ends
                // AND ends after existing starts
                $q->where('start_time', '<', $this->endTime)
                    ->where('end_time', '>', $this->startTime);
            });

        if ($this->excludeId) {
            $query->where('id', '!=', $this->excludeId);
        }

        return $query->first();
    }

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
}
