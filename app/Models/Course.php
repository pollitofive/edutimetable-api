<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = ['name', 'year'];

    protected $appends = ['level'];

    /**
     * Get the level attribute (backwards compatibility)
     */
    public function getLevelAttribute(): ?string
    {
        return $this->courseLevel?->name;
    }

    /**
     * Get the course level
     */
    public function courseLevel(): BelongsTo
    {
        return $this->belongsTo(CourseLevel::class, 'course_level_id');
    }

    /**
     * One course can have many schedules
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * Get all teachers teaching this course (through schedules)
     * This provides a many-to-many relationship
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(
            Teacher::class,
            'schedules',      // pivot table
            'course_id',      // foreign key on pivot
            'teacher_id'      // related key on pivot
        )
            ->distinct()         // avoid duplicate teachers
            ->withTimestamps();  // include schedule timestamps
    }
}
