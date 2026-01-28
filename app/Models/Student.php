<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    /** @use HasFactory<\Database\Factories\StudentFactory> */
    use BelongsToBusiness, HasFactory;

    protected $fillable = ['name', 'email', 'code'];

    protected $appends = ['level'];

    /**
     * Get the level attribute (backwards compatibility)
     */
    public function getLevelAttribute(): ?string
    {
        return $this->courseLevel?->name;
    }

    /**
     * Scope to search students by name OR email
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }

    /**
     * Scope to filter students by the track of their courseLevel
     */
    public function scopeByTrack(Builder $query, string $track): Builder
    {
        return $query->whereHas('courseLevel', function (Builder $q) use ($track) {
            $q->where('track', $track);
        });
    }

    /**
     * Get the course level
     */
    public function courseLevel(): BelongsTo
    {
        return $this->belongsTo(CourseLevel::class, 'course_level_id');
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(StudentAvailability::class);
    }

    /**
     * A student has many enrollments
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * Get only active enrollments
     */
    public function activeEnrollments(): HasMany
    {
        return $this->enrollments()->where('status', 'active');
    }

    /**
     * Get all schedules through enrollments
     */
    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(Schedule::class, 'student_enrollments')
            ->withPivot('enrolled_at', 'status', 'notes')
            ->withTimestamps();
    }
}
