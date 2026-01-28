<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Schedule extends Model
{
    use BelongsToBusiness, HasFactory;

    // Add teacher_id to fillable
    // NOTE: business_id should NOT be in fillable - it's set automatically by BelongsToBusiness trait
    protected $fillable = [
        'course_id',
        'teacher_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'description',
        'group_id',
    ];

    /**
     * Schedule belongs to a course
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Schedule belongs to a teacher
     * NEW RELATIONSHIP
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * A schedule has many enrollments
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * Get all students enrolled in this schedule
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_enrollments')
            ->withPivot('enrolled_at', 'status', 'notes')
            ->withTimestamps();
    }

    /**
     * Filter schedules that start at or after a given time
     */
    public function scopeStartsAtFrom(Builder $query, string $time): Builder
    {
        return $query->where('starts_at', '>=', $time);
    }

    /**
     * Filter schedules that end at or before a given time
     */
    public function scopeStartsAtTo(Builder $query, string $time): Builder
    {
        return $query->where('ends_at', '<=', $time);
    }
}
