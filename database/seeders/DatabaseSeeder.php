<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentAvailability;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use App\Models\Track;
use App\Models\User;
use App\Services\CurrentBusiness;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    private function createCourseLevels(): array
    {
        $tracks = ['English', 'Portuguese', 'Spanish', 'French'];
        $levels = [
            ['name' => 'Beginner',          'slug' => 'beginner',          'sort_order' => 10],
            ['name' => 'Pre-Intermediate',   'slug' => 'pre-intermediate',  'sort_order' => 20],
            ['name' => 'Intermediate',       'slug' => 'intermediate',      'sort_order' => 30],
            ['name' => 'Upper-Intermediate', 'slug' => 'upper-intermediate','sort_order' => 40],
            ['name' => 'Advanced',           'slug' => 'advanced',          'sort_order' => 50],
        ];

        $trackModels = collect($tracks)->mapWithKeys(fn ($name) => [$name => Track::create(['name' => $name])]);

        $courseLevels = [];

        foreach ($tracks as $track) {
            foreach ($levels as $level) {
                $courseLevels[$track][$level['slug']] = CourseLevel::create([
                    'track_id'      => $trackModels[$track]->id,
                    'name'          => $level['name'],
                    'slug'          => $level['slug'],
                    'sort_order'    => $level['sort_order'],
                    'next_level_id' => null,
                ]);
            }
        }

        return $courseLevels;
    }

    public function run(): void
    {
        $this->command->info('Starting database seeding...');

        // ── Businesses ────────────────────────────────────────────────────────
        $defaultBusiness = Business::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Educational Institution']
        );

        $demoBusiness = Business::firstOrCreate(
            ['slug' => 'ielit'],
            ['name' => 'IELI Instituto de Enseñanza de Lengua Inglesa']
        );

        // ── Users ─────────────────────────────────────────────────────────────
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin User', 'password' => Hash::make('password'), 'default_business_id' => $defaultBusiness->id]
        );
        $adminUser->businesses()->syncWithoutDetaching([$defaultBusiness->id => ['role' => 'owner']]);

        $demoUser = User::firstOrCreate(
            ['email' => 'administracion@ieliargentina.com.ar'],
            ['name' => 'Graciela Pita', 'password' => Hash::make('12345678'), 'default_business_id' => $demoBusiness->id]
        );
        $demoUser->businesses()->syncWithoutDetaching([$demoBusiness->id => ['role' => 'owner']]);

        $staffUser = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Staff User', 'password' => Hash::make('password'), 'default_business_id' => $defaultBusiness->id]
        );
        $staffUser->businesses()->syncWithoutDetaching([$defaultBusiness->id => ['role' => 'staff']]);

        // ── Default Business Data ─────────────────────────────────────────────
        $this->command->info('Seeding data for Default Business...');

        $currentBusiness = app(CurrentBusiness::class);
        $currentBusiness->setId($defaultBusiness->id);

        // Course levels indexed by track → slug
        $levels = $this->createCourseLevels();
        $this->command->info('Created course levels.');

        // Teachers with realistic Argentine names
        $teacherNames = [
            'Valeria Romero', 'Marcelo Gutiérrez', 'Sofía Pereyra', 'Diego Navarro',
            'Luciana Torres', 'Andrés Cabrera', 'Florencia Medina', 'Pablo Herrera',
            'Natalia Álvarez', 'Sebastián Ruiz',
        ];
        $teachers = collect();
        foreach ($teacherNames as $name) {
            $teachers->push(Teacher::create([
                'name'  => $name,
                'email' => Str::slug($name, '.') . '@instituto.edu.ar',
                'phone' => null,
            ]));
        }
        $this->command->info("Created {$teachers->count()} teachers.");

        // ── Courses ───────────────────────────────────────────────────────────
        // Each entry: [track, level-slug, course name]
        $courseDefinitions = [
            ['English',    'beginner',          'English Beginner A'],
            ['English',    'beginner',          'English Beginner B'],
            ['English',    'pre-intermediate',  'English Pre-Intermediate A'],
            ['English',    'intermediate',      'English Intermediate A'],
            ['English',    'intermediate',      'English Intermediate B'],
            ['English',    'upper-intermediate','English Upper-Intermediate A'],
            ['English',    'advanced',          'English Advanced A'],
            ['Spanish',    'beginner',          'Spanish for Foreigners - Beginner'],
            ['Spanish',    'intermediate',      'Spanish for Foreigners - Intermediate'],
            ['French',     'beginner',          'French Beginner A'],
            ['French',     'intermediate',      'French Intermediate A'],
            ['Portuguese', 'beginner',          'Portuguese Beginner A'],
            ['Portuguese', 'pre-intermediate',  'Portuguese Pre-Intermediate A'],
        ];

        $courses = collect();
        foreach ($courseDefinitions as [$track, $levelSlug, $name]) {
            $courses->push(Course::create([
                'name'            => $name,
                'course_level_id' => $levels[$track][$levelSlug]->id,
            ]));
        }
        $this->command->info("Created {$courses->count()} courses.");

        // ── Schedules ─────────────────────────────────────────────────────────
        // Patterns: [days[], label]
        $patterns = [
            [[1, 3, 5], 'Mon/Wed/Fri'],
            [[1, 3, 5], 'Mon/Wed/Fri'],
            [[2, 4],    'Tue/Thu'],
            [[2, 4],    'Tue/Thu'],
            [[1, 3],    'Mon/Wed'],
            [[2, 4, 6], 'Tue/Thu/Sat'],
            [[6],       'Saturday'],
            [[6],       'Saturday'],
            [[1],       'Monday'],
            [[5],       'Friday'],
        ];

        // Time slots: [starts_at, ends_at] — 90-min blocks
        $timeSlots = [
            ['08:00:00', '09:30:00'],
            ['09:30:00', '11:00:00'],
            ['10:00:00', '12:00:00'],
            ['11:00:00', '12:30:00'],
            ['14:00:00', '15:30:00'],
            ['15:30:00', '17:00:00'],
            ['17:00:00', '18:30:00'],
            ['18:30:00', '20:00:00'],
            ['20:00:00', '21:30:00'],
        ];

        $schedules = collect();

        foreach ($courses as $course) {
            [$patternDays] = $patterns[array_rand($patterns)];
            [$startsAt, $endsAt]          = $timeSlots[array_rand($timeSlots)];
            $teacher                      = $teachers->random();
            $groupId                      = (string) Str::uuid();
            $capacity                     = rand(6, 15);

            foreach ($patternDays as $day) {
                $schedule = Schedule::create([
                    'course_id'  => $course->id,
                    'teacher_id' => $teacher->id,
                    'group_id'   => $groupId,
                    'day_of_week'=> $day,
                    'starts_at'  => $startsAt,
                    'ends_at'    => $endsAt,
                    'capacity'   => $capacity,
                    'description'=> null,
                ]);
                $schedules->push($schedule);
            }
        }
        $this->command->info("Created {$schedules->count()} schedules.");

        // ── Students ──────────────────────────────────────────────────────────
        $studentNames = [
            'Valentina García',  'Tomás López',      'Camila Martínez',  'Mateo Fernández',
            'Lucía González',    'Santiago Rodríguez','Martina Pérez',    'Nicolás Sánchez',
            'Juliana Romero',    'Agustín Torres',    'Florencia Díaz',   'Facundo Ruiz',
            'Carolina Morales',  'Ignacio Herrera',   'Antonella Medina', 'Joaquín Castro',
            'Daniela Vargas',    'Felipe Guzmán',     'Micaela Álvarez',  'Rodrigo Navarro',
            'Brenda Cabrera',    'Leandro Ortiz',     'Vanina Molina',    'Emiliano Ramos',
            'Julieta Suárez',    'Manuel Acosta',     'Xiomara Benítez',  'Bruno Giménez',
            'Sofía Mendoza',     'Lucas Aguilar',
        ];

        $allLevels = collect($levels)->flatMap(fn ($ls) => collect($ls))->values();
        $students  = collect();

        foreach ($studentNames as $name) {
            $students->push(Student::create([
                'name'            => $name,
                'email'           => Str::slug($name, '.') . rand(10, 99) . '@mail.com',
                'phone'           => rand(0, 1) ? '+54 9 ' . rand(11, 299) . ' ' . rand(1000000, 9999999) : null,
                'course_level_id' => $allLevels->random()->id,
            ]));
        }
        $this->command->info("Created {$students->count()} students.");

        // ── Student Availabilities ─────────────────────────────────────────────
        // Realistic blocks: morning, afternoon or evening on 2-4 days
        $availabilityBlocks = [
            ['start_time' => '08:00', 'end_time' => '12:00'],
            ['start_time' => '09:00', 'end_time' => '13:00'],
            ['start_time' => '14:00', 'end_time' => '18:00'],
            ['start_time' => '15:00', 'end_time' => '19:00'],
            ['start_time' => '17:00', 'end_time' => '21:00'],
            ['start_time' => '18:00', 'end_time' => '21:30'],
            ['start_time' => '08:00', 'end_time' => '21:30'], // all day
        ];

        $availabilityCount = 0;
        foreach ($students as $student) {
            // Each student picks one time block and 2-4 days
            $block    = $availabilityBlocks[array_rand($availabilityBlocks)];
            $numDays  = rand(2, 4);
            $days     = array_rand(array_flip([1, 2, 3, 4, 5, 6]), $numDays);
            $days     = is_array($days) ? $days : [$days];

            foreach ($days as $day) {
                StudentAvailability::create([
                    'student_id' => $student->id,
                    'day_of_week'=> $day,
                    'start_time' => $block['start_time'],
                    'end_time'   => $block['end_time'],
                ]);
                $availabilityCount++;
            }
        }
        $this->command->info("Created {$availabilityCount} student availabilities.");

        // ── Enrollments ───────────────────────────────────────────────────────
        $enrollmentCount  = 0;
        $enrolledPerGroup = []; // group_id => number of students enrolled

        foreach ($students as $student) {
            $studentTrackId = $student->courseLevel->track_id ?? null;

            $matchingSchedules = $studentTrackId
                ? $schedules->filter(fn ($s) => $s->course->courseLevel->track_id === $studentTrackId)
                : $schedules;

            $pool = $matchingSchedules->count() >= 3 ? $matchingSchedules : $schedules;

            // Only consider groups that still have room
            $availableGroups = $pool
                ->pluck('group_id')
                ->unique()
                ->filter(function ($groupId) use ($schedules, &$enrolledPerGroup) {
                    $capacity = $schedules->firstWhere('group_id', $groupId)->capacity;
                    return ($enrolledPerGroup[$groupId] ?? 0) < $capacity;
                })
                ->shuffle()
                ->take(rand(1, 2));

            foreach ($availableGroups as $groupId) {
                $groupSchedules = $schedules->where('group_id', $groupId);
                foreach ($groupSchedules as $schedule) {
                    StudentEnrollment::create([
                        'student_id'  => $student->id,
                        'schedule_id' => $schedule->id,
                        'status'      => 'active',
                        'enrolled_at' => now()->subDays(rand(0, 60)),
                    ]);
                    $enrollmentCount++;
                }
                $enrolledPerGroup[$groupId] = ($enrolledPerGroup[$groupId] ?? 0) + 1;
            }
        }
        $this->command->info("Created {$enrollmentCount} student enrollments.");

        $this->command->info('');
        $this->command->info('Database seeding completed!');
        $this->command->info('  Admin:  admin@example.com / password');
        $this->command->info('  Demo:   administracion@ieliargentina.com.ar / 12345678');
        $this->command->info('  Staff:  staff@example.com / password');
    }
}