<?php

namespace App\GraphQL\Mutations;

use App\Models\CourseLevel;
use App\Models\Track;
use Illuminate\Validation\ValidationException;

class UpdateCourseLevel
{
    public function __invoke($_, array $args): CourseLevel
    {
        $courseLevel = CourseLevel::find($args['id']);

        if (! $courseLevel) {
            throw ValidationException::withMessages([
                'id' => [__('course_level.not_found')],
            ]);
        }

        // When using @spread, the input fields are spread into $args directly
        $data = $args;
        unset($data['id']);

        $trackId = $this->resolveTrackId($data);
        unset($data['track_name']);
        if ($trackId !== null) {
            $data['track_id'] = $trackId;
        }

        $courseLevel->update(array_filter($data, fn ($value) => $value !== null));

        return $courseLevel->fresh();
    }

    private function resolveTrackId(array $data): ?int
    {
        if (! empty($data['track_id'])) {
            return (int) $data['track_id'];
        }

        if (! empty($data['track_name'])) {
            return Track::findOrCreateByName($data['track_name'])->id;
        }

        return null;
    }
}