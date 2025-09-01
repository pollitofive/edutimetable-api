<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    use HasFactory;
    protected $fillable = [
        'course_id',
        'day_of_week',
        'starts_at',
        'ends_at',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
