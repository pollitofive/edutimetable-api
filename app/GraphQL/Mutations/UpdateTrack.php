<?php

namespace App\GraphQL\Mutations;

use App\Models\Track;
use Illuminate\Validation\ValidationException;

class UpdateTrack
{
    public function __invoke($_, array $args): Track
    {
        $track = Track::find($args['id']);

        if (! $track) {
            throw ValidationException::withMessages([
                'id' => [__('track.not_found')],
            ]);
        }

        // When using @spread, the input fields are spread into $args directly
        $data = $args;
        unset($data['id']);

        if (isset($data['name'])) {
            $this->assertNameAvailable($data['name'], $track->id);
            $track->name = trim($data['name']);
            $track->save();
        }

        return $track->fresh();
    }

    private function assertNameAvailable(string $name, int|string $excludingId): void
    {
        $normalized = mb_strtolower(trim($name));

        $exists = Track::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->where('id', '!=', $excludingId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => [__('track.duplicate_name')],
            ]);
        }
    }
}