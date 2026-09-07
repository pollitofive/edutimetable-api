<?php

namespace App\GraphQL\Mutations;

use App\Models\Track;
use Illuminate\Validation\ValidationException;

class DeleteTrack
{
    public function __invoke($_, array $args): Track
    {
        $track = Track::find($args['id']);

        if (! $track) {
            throw ValidationException::withMessages([
                'id' => [__('track.not_found')],
            ]);
        }

        $inUseCount = $track->courseLevels()->count();

        if ($inUseCount > 0) {
            throw ValidationException::withMessages([
                'id' => [__('track.in_use', ['count' => $inUseCount])],
            ]);
        }

        $track->delete();

        return $track;
    }
}