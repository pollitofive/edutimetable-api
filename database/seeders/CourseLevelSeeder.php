<?php

namespace Database\Seeders;

use App\Models\CourseLevel;
use App\Models\Track;
use Illuminate\Database\Seeder;

class CourseLevelSeeder extends Seeder
{
    // Progression order matters: each level's previous_level_id points at the one before it.
    public const LEVELS = [
        ['name' => 'Beginner', 'slug' => 'beginner', 'sort_order' => 10],
        ['name' => 'Pre-Intermediate', 'slug' => 'pre-intermediate', 'sort_order' => 20],
        ['name' => 'Intermediate', 'slug' => 'intermediate', 'sort_order' => 30],
        ['name' => 'Upper-Intermediate', 'slug' => 'upper-intermediate', 'sort_order' => 40],
        ['name' => 'Advanced', 'slug' => 'advanced', 'sort_order' => 50],
    ];

    public function run(): void
    {
        $tracks = Track::all();
        $count = 0;

        foreach ($tracks as $track) {
            $previousLevelId = null;

            foreach (self::LEVELS as $level) {
                $courseLevel = CourseLevel::create([
                    'track_id' => $track->id,
                    'name' => $level['name'],
                    'slug' => $level['slug'],
                    'sort_order' => $level['sort_order'],
                    'previous_level_id' => $previousLevelId,
                ]);

                $previousLevelId = $courseLevel->id;
                $count++;
            }
        }

        $this->command->info("Created {$count} course levels with progression chains.");
    }
}