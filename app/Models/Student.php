<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Student extends Model
{
    /** @use HasFactory<\Database\Factories\StudentFactory> */
    use BelongsToBusiness, HasFactory;

    protected $fillable = ['name', 'email', 'phone'];

    /**
     * The student's level in the given track, if they study it (one level per track).
     * Uses the courseLevels relation, so eager load it.
     */
    public function levelInTrack(int $trackId): ?CourseLevel
    {
        return $this->courseLevels->firstWhere('track_id', $trackId);
    }

    /**
     * Replaces the student's levels. The pivot carries business_id for its composite FKs.
     *
     * @param  array<int|string>  $courseLevelIds
     */
    public function syncCourseLevels(array $courseLevelIds): void
    {
        $this->courseLevels()->syncWithPivotValues(
            array_map('intval', $courseLevelIds),
            ['business_id' => $this->business_id]
        );
    }

    /**
     * Scope to search students by name, email or phone
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    /**
     * Scope to filter students who study the track (any of their levels)
     */
    public function scopeByTrack(Builder $query, int $trackId): Builder
    {
        return $query->whereHas('courseLevels', function (Builder $q) use ($trackId) {
            $q->where('track_id', $trackId);
        });
    }

    /**
     * Scope to filter students who have the level
     */
    public function scopeByCourseLevel(Builder $query, int $courseLevelId): Builder
    {
        return $query->whereHas('courseLevels', fn (Builder $q) => $q->where('course_levels.id', $courseLevelId));
    }

    /**
     * The student's levels, one per track they study
     */
    public function courseLevels(): BelongsToMany
    {
        return $this->belongsToMany(CourseLevel::class)
            ->withTimestamps()
            ->orderBy('course_levels.track_id');
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(StudentAvailability::class);
    }

    /**
     * Whether one of the student's availability ranges fully covers the given slot.
     * Times are compared as HH:MM:SS strings, as stored in the database.
     */
    public function isAvailableFor(int $dayOfWeek, string $startsAt, string $endsAt): bool
    {
        return $this->availabilities->contains(function ($avail) use ($dayOfWeek, $startsAt, $endsAt) {
            return (int) $avail->day_of_week === $dayOfWeek
                && $avail->start_time <= $startsAt
                && $avail->end_time >= $endsAt;
        });
    }

    /**
     * Schedules the student actively attends that overlap the given slot, leaving out
     * $excludeScheduleIds (usually the group being enrolled in or moved).
     * Uses the activeEnrollments.schedule relation, so eager load it (with course).
     *
     * @param  array<int>  $excludeScheduleIds
     * @return Collection<int, Schedule>
     */
    public function conflictingSchedules(int $dayOfWeek, string $startsAt, string $endsAt, array $excludeScheduleIds = []): Collection
    {
        return $this->activeEnrollments
            ->pluck('schedule')
            ->filter(fn ($s) => $s
                && ! in_array($s->id, $excludeScheduleIds)
                && (int) $s->day_of_week === $dayOfWeek
                && Schedule::timesOverlap($startsAt, $endsAt, $s->starts_at, $s->ends_at))
            ->values();
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
