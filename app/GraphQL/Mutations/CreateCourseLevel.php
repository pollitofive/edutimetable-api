<?php

namespace App\GraphQL\Mutations;

use App\Models\CourseLevel;
use App\Models\Track;
use Illuminate\Validation\ValidationException;

class CreateCourseLevel
{
    public function __invoke($_, array $args): CourseLevel
    {
        // When using @spread, the input fields are spread into $args directly
        $data = $args;

        $data['track_id'] = $this->resolveTrackId($data);
        unset($data['track_name']);

        return CourseLevel::create($data);
    }

    private function resolveTrackId(array $data): int
    {
        if (! empty($data['track_id'])) {
            return (int) $data['track_id'];
        }

        if (! empty($data['track_name'])) {
            return Track::findOrCreateByName($data['track_name'])->id;
        }

        throw ValidationException::withMessages([
            'track' => [__('course_level.track_required')],
        ]);
    }
}