<?php

namespace Database\Seeders;

use App\Models\Track;
use Illuminate\Database\Seeder;

class TrackSeeder extends Seeder
{
    public const TRACKS = ['English', 'Portuguese', 'Spanish', 'French'];

    public function run(): void
    {
        foreach (self::TRACKS as $name) {
            Track::create(['name' => $name]);
        }

        $this->command->info('Created '.count(self::TRACKS).' tracks.');
    }
}