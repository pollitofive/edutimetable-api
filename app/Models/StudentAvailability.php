<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAvailability extends Model
{
    /** @use HasFactory<\Database\Factories\StudentAvailabilityFactory> */
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'student_id',
        'day_of_week',
        'start_time',
        'end_time',
        'business_id',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
