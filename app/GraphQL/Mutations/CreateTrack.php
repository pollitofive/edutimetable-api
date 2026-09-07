<?php

namespace App\GraphQL\Mutations;

use App\Models\Track;
use Illuminate\Validation\ValidationException;

class CreateTrack
{
    public function __invoke($_, array $args): Track
    {
        // When using @spread, the input fields are spread into $args directly
        $data = $args;

        $this->assertNameAvailable($data['name']);

        return Track::create(['name' => trim($data['name'])]);
    }

    private function assertNameAvailable(string $name): void
    {
        $normalized = mb_strtolower(trim($name));

        $exists = Track::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => [__('track.duplicate_name')],
            ]);
        }
    }
}