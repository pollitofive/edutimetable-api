<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    /** @use HasFactory<\Database\Factories\StudentFactory> */
    use BelongsToBusiness, HasFactory;

    protected $fillable = ['name', 'email', 'code'];

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
