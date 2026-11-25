<?php

namespace App\Rules;

use App\Models\CourseLevel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A student studies each track at one level: the list of level ids can't have two levels
 * of the same track. Ids that don't exist are left to exists_in_business.
 */
class OneLevelPerTrack implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || count($value) < 2) {
            return;
        }

        $tracks = CourseLevel::whereIn('id', array_map('intval', $value))->pluck('track_id');

        if ($tracks->count() !== $tracks->unique()->count()) {
            $fail(__('person.errors.one_level_per_track'));
        }
    }
}
