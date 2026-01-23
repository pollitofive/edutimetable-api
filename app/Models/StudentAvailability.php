<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Services\CurrentBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAvailability extends Model
{
    /** @use HasFactory<\Database\Factories\StudentAvailabilityFactory> */
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'student_id',
        'day_of_week',
        'start_time',
        'end_time',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'start_time' => 'string',
            'end_time' => 'string',
        ];
    }

    /**
     * Validate that start_time is before end_time
     */
    public function validateTimeRange(): bool
    {
        return $this->start_time < $this->end_time;
    }

    /**
     * Check if this availability overlaps with another time range
     */
    public function overlaps(string $startTime, string $endTime, ?int $excludeId = null): bool
    {
        // Ensure we have business_id set
        $businessId = $this->business_id ?? app(CurrentBusiness::class)->id();

        $query = static::where('business_id', $businessId)
            ->where('student_id', $this->student_id)
            ->where('day_of_week', $this->day_of_week)
            ->where(function ($q) use ($startTime, $endTime) {
                // Check if new range overlaps: start_time < new_end AND end_time > new_start
                $q->where('start_time', '<', $endTime)
                    ->where('end_time', '>', $startTime);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
