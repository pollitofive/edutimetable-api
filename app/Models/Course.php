<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Course extends Model
{
    use HasFactory;

    // Remove teacher_id from fillable
    protected $fillable = ['name', 'level', 'year'];

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
