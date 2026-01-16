<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    use HasFactory;

    // Add teacher_id to fillable
    protected $fillable = [
        'course_id',
        'teacher_id',  // NEW
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
}
