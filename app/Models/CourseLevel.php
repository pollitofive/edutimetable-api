<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseLevel extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'track',
        'name',
        'slug',
        'sort_order',
        'next_level_id',
        'texts',
    ];

    public function nextLevel(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_level_id');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'course_level_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'course_level_id');
    }
}
