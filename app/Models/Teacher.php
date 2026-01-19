<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email'];

    /**
     * Teacher has many schedules
     * NEW RELATIONSHIP
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * Get all courses taught by this teacher (through schedules)
     * This provides a many-to-many relationship
     * REPLACES direct courses() relationship
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(
            Course::class,
            'schedules',      // pivot table
            'teacher_id',     // foreign key on pivot
            'course_id'       // related key on pivot
        )
            ->distinct()         // avoid duplicate courses
            ->withTimestamps();  // include schedule timestamps
    }
}
