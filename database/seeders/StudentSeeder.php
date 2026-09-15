<?php

namespace Database\Seeders;

use App\Models\CourseLevel;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StudentSeeder extends Seeder
{
    private const TOTAL_STUDENTS = 70;

    // Funnel shape: more students in the entry levels, fewer as they advance.
    private const LEVEL_WEIGHTS = [
        'beginner' => 0.30,
        'pre-intermediate' => 0.24,
        'intermediate' => 0.20,
        'upper-intermediate' => 0.15,
        'advanced' => 0.11,
    ];

    // English is the most in-demand track, Portuguese the least.
    private const TRACK_WEIGHTS = [
        'English' => 0.40,
        'Spanish' => 0.22,
        'French' => 0.20,
        'Portuguese' => 0.18,
    ];

    private const FIRST_NAMES = [
        'Valentina', 'Tomás', 'Camila', 'Mateo', 'Lucía', 'Santiago', 'Martina', 'Nicolás',
        'Juliana', 'Agustín', 'Florencia', 'Facundo', 'Carolina', 'Ignacio', 'Antonella',
        'Joaquín', 'Daniela', 'Felipe', 'Micaela', 'Rodrigo', 'Brenda', 'Leandro', 'Vanina',
        'Emiliano', 'Julieta', 'Manuel', 'Xiomara', 'Bruno', 'Sofía', 'Lucas',
    ];

    private const LAST_NAMES = [
        'García', 'López', 'Martínez', 'Fernández', 'González', 'Rodríguez', 'Pérez', 'Sánchez',
        'Romero', 'Torres', 'Díaz', 'Ruiz', 'Morales', 'Herrera', 'Medina', 'Castro', 'Vargas',
        'Guzmán', 'Álvarez', 'Navarro', 'Cabrera', 'Ortiz', 'Molina', 'Ramos', 'Suárez', 'Acosta',
        'Benítez', 'Giménez', 'Mendoza', 'Aguilar',
    ];

    public function run(): void
    {
        $allocations = $this->allocateStudentsPerCourseLevel();
        $names = $this->generateUniqueNames(self::TOTAL_STUDENTS);
        $index = 0;

        foreach ($allocations as $courseLevelId => $studentCount) {
            for ($i = 0; $i < $studentCount; $i++) {
                $name = $names[$index];

                Student::create([
                    'name' => $name,
                    'email' => Str::slug($name, '.').$index.'@mail.com',
                    'phone' => rand(0, 1) ? '+54 9 '.rand(11, 299).' '.rand(1000000, 9999999) : null,
                    'course_level_id' => $courseLevelId,
                ]);

                $index++;
            }
        }

        $this->command->info("Created {$index} students.");
    }

    /**
     * Distributes TOTAL_STUDENTS across every (track, level) course level using
     * track_weight * level_weight, via the largest-remainder method so the total
     * matches exactly.
     */
    private function allocateStudentsPerCourseLevel(): array
    {
        $courseLevels = CourseLevel::with('track')->get();

        $raw = [];
        foreach ($courseLevels as $courseLevel) {
            $trackWeight = self::TRACK_WEIGHTS[$courseLevel->track->name] ?? 0;
            $levelWeight = self::LEVEL_WEIGHTS[$courseLevel->slug] ?? 0;
            $raw[$courseLevel->id] = self::TOTAL_STUDENTS * $trackWeight * $levelWeight;
        }

        $floored = array_map('intval', $raw);
        $remainder = self::TOTAL_STUDENTS - array_sum($floored);

        $remainders = [];
        foreach ($raw as $id => $value) {
            $remainders[$id] = $value - (int) $value;
        }
        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, $remainder) as $id) {
            $floored[$id]++;
        }

        return $floored;
    }

    private function generateUniqueNames(int $count): array
    {
        $combinations = [];
        foreach (self::FIRST_NAMES as $first) {
            foreach (self::LAST_NAMES as $last) {
                $combinations[] = "{$first} {$last}";
            }
        }

        shuffle($combinations);

        return array_slice($combinations, 0, $count);
    }
}