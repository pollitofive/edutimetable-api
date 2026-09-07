<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Track extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'name',
    ];

    public function courseLevels(): HasMany
    {
        return $this->hasMany(CourseLevel::class);
    }

    /**
     * Find an existing track by name (case/whitespace-insensitive, scoped to the
     * current business via BelongsToBusiness' global scope), or create a new one
     * preserving the casing as typed.
     */
    public static function findOrCreateByName(string $name): self
    {
        $name = trim($name);

        $existing = static::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->first();

        return $existing ?? static::create(['name' => $name]);
    }
}