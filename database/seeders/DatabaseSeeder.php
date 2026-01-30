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
use App\Models\User;
use App\Services\CurrentBusiness;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Create course levels for a business.
     */
    private function createCourseLevels(): array
    {
        $tracks = ['English', 'Portuguese', 'Spanish', 'French'];
        $levels = [
            ['name' => 'Beginner', 'slug' => 'beginner', 'sort_order' => 10],
            ['name' => 'Pre-Intermediate', 'slug' => 'pre-intermediate', 'sort_order' => 20],
            ['name' => 'Intermediate', 'slug' => 'intermediate', 'sort_order' => 30],
            ['name' => 'Upper-Intermediate', 'slug' => 'upper-intermediate', 'sort_order' => 40],
            ['name' => 'Advanced', 'slug' => 'advanced', 'sort_order' => 50],
        ];

        $courseLevels = [];

        foreach ($tracks as $track) {
            foreach ($levels as $level) {
                $courseLevels[] = CourseLevel::create([
                    'track' => $track,
                    'name' => $level['name'],
                    'slug' => $level['slug'],
                    'sort_order' => $level['sort_order'],
                    'next_level_id' => null,
                ]);
            }
        }

        return $courseLevels;
    }

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('Starting database seeding...');

        // ========================================
        // 1. Create Businesses
        // ========================================
        $this->command->info('Creating businesses...');

        $defaultBusiness = Business::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Educational Institution']
        );

        $demoBusiness = Business::firstOrCreate(
            ['slug' => 'ielit'],
            ['name' => 'IELI Instituto de Enseñanza de Lengua Inglesa']
        );

        $this->command->info("Businesses ready: {$defaultBusiness->name}, {$demoBusiness->name}");

        // ========================================
        // 2. Create Users and Associate with Businesses
        // ========================================
        $this->command->info('Creating users...');

        // Admin user for default business
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'default_business_id' => $defaultBusiness->id,
            ]
        );

        // Attach admin to default business as owner (sync to avoid duplicates)
        $adminUser->businesses()->syncWithoutDetaching([$defaultBusiness->id => ['role' => 'owner']]);
        $this->command->info("User {$adminUser->email} ready as owner for {$defaultBusiness->name}");

        // Demo user for demo business
        $demoUser = User::firstOrCreate(
            ['email' => 'administracion@ieliargentina.com.ar'],
            [
                'name' => 'Graciela Pita',
                'password' => Hash::make('12345678'),
                'default_business_id' => $demoBusiness->id,
            ]
        );

        // Attach demo user to demo business as owner
        $demoUser->businesses()->syncWithoutDetaching([$demoBusiness->id => ['role' => 'owner']]);
        $this->command->info("User {$demoUser->email} ready as owner for {$demoBusiness->name}");

        // Staff user for default business
        $staffUser = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            [
                'name' => 'Staff User',
                'password' => Hash::make('password'),
                'default_business_id' => $defaultBusiness->id,
            ]
        );

        // Attach staff user to default business as staff
        $staffUser->businesses()->syncWithoutDetaching([$defaultBusiness->id => ['role' => 'staff']]);
        $this->command->info("User {$staffUser->email} ready as staff for {$defaultBusiness->name}");

        // ========================================
        // 3. Seed Default Business Data
        // ========================================
        $this->command->info('Seeding data for Default Business...');

        // Set the current business context
        $currentBusiness = app(CurrentBusiness::class);
        $currentBusiness->setId($defaultBusiness->id);

        // Create course levels FIRST (before students and courses)
        $courseLevels = $this->createCourseLevels();
        $this->command->info('Created '.count($courseLevels).' course levels for '.$defaultBusiness->name);

        // Create teachers
        $teachers = Teacher::factory(10)->create();
        $this->command->info('Created '.$teachers->count().' teachers for '.$defaultBusiness->name);

        // Create students with explicit course_level_id
        $students = collect();
        for ($i = 0; $i < 20; $i++) {
            $students->push(Student::factory()->create([
                'course_level_id' => $courseLevels[array_rand($courseLevels)]->id,
            ]));
        }
        $this->command->info('Created '.$students->count().' students for '.$defaultBusiness->name);

        // Create courses with explicit course_level_id
        $courses = collect();
        for ($i = 0; $i < 15; $i++) {
            $courses->push(Course::factory()->create([
                'course_level_id' => $courseLevels[array_rand($courseLevels)]->id,
            ]));
        }
        $this->command->info('Created '.$courses->count().' courses for '.$defaultBusiness->name);

        // Create schedules with explicit course AND teacher
        $schedules = collect();
        foreach ($courses as $course) {
            $numSchedules = rand(1, 3);

            for ($i = 0; $i < $numSchedules; $i++) {
                // Randomly assign a teacher to each schedule
                $randomTeacher = $teachers->random();

                $schedule = Schedule::factory()->create([
                    'course_id' => $course->id,
                    'teacher_id' => $randomTeacher->id,
                ]);

                $schedules->push($schedule);
            }
        }
        $this->command->info('Created '.$schedules->count().' schedules for '.$defaultBusiness->name);

        // Create student availabilities
        foreach ($students->random(min(15, $students->count())) as $student) {
            StudentAvailability::factory()->create([
                'student_id' => $student->id,
            ]);
        }
        $this->command->info('Created 15 student availabilities for '.$defaultBusiness->name);

        // Create student enrollments
        $enrollmentCount = 0;
        foreach ($students as $student) {
            // Each student enrolls in 1-3 random schedules
            $numEnrollments = rand(1, 3);
            $randomSchedules = $schedules->random(min($numEnrollments, $schedules->count()));

            foreach ($randomSchedules as $schedule) {
                StudentEnrollment::factory()->create([
                    'student_id' => $student->id,
                    'schedule_id' => $schedule->id,
                    'status' => 'active',
                ]);
                $enrollmentCount++;
            }
        }
        $this->command->info('Created '.$enrollmentCount.' student enrollments for '.$defaultBusiness->name);

        // ========================================
        // 4. Seed Demo Business Data
        // ========================================
        $this->command->info('Seeding data for Demo Business...');

        // Switch to demo business context
        /*
        $currentBusiness->setId($demoBusiness->id);

        // Create course levels for demo business
        $demoCourseLevels = $this->createCourseLevels();
        $this->command->info('Created '.count($demoCourseLevels).' course levels for '.$demoBusiness->name);

        // Create teachers for demo business
        $demoTeachers = Teacher::factory(5)->create();
        $this->command->info('Created '.$demoTeachers->count().' teachers for '.$demoBusiness->name);

        // Create students for demo business with explicit course_level_id
        $demoStudents = collect();
        for ($i = 0; $i < 10; $i++) {
            $demoStudents->push(Student::factory()->create([
                'course_level_id' => $demoCourseLevels[array_rand($demoCourseLevels)]->id,
            ]));
        }
        $this->command->info('Created '.$demoStudents->count().' students for '.$demoBusiness->name);

        // Create courses for demo business with explicit course_level_id
        $demoCourses = collect();
        for ($i = 0; $i < 8; $i++) {
            $demoCourses->push(Course::factory()->create([
                'course_level_id' => $demoCourseLevels[array_rand($demoCourseLevels)]->id,
            ]));
        }
        $this->command->info('Created '.$demoCourses->count().' courses for '.$demoBusiness->name);

        // Create schedules for demo business
        $demoSchedules = collect();
        foreach ($demoCourses as $course) {
            $numSchedules = rand(1, 2);

            for ($i = 0; $i < $numSchedules; $i++) {
                $randomTeacher = $demoTeachers->random();

                $schedule = Schedule::factory()->create([
                    'course_id' => $course->id,
                    'teacher_id' => $randomTeacher->id,
                ]);

                $demoSchedules->push($schedule);
            }
        }
        $this->command->info('Created '.$demoSchedules->count().' schedules for '.$demoBusiness->name);

        // Create student availabilities for demo business
        foreach ($demoStudents->random(min(8, $demoStudents->count())) as $student) {
            StudentAvailability::factory()->create([
                'student_id' => $student->id,
            ]);
        }
        $this->command->info('Created 8 student availabilities for '.$demoBusiness->name);

        // Create student enrollments for demo business
        $demoEnrollmentCount = 0;
        foreach ($demoStudents as $student) {
            $numEnrollments = rand(1, 2);
            $randomSchedules = $demoSchedules->random(min($numEnrollments, $demoSchedules->count()));

            foreach ($randomSchedules as $schedule) {
                StudentEnrollment::factory()->create([
                    'student_id' => $student->id,
                    'schedule_id' => $schedule->id,
                    'status' => 'active',
                ]);
                $demoEnrollmentCount++;
            }
        }
        $this->command->info('Created '.$demoEnrollmentCount.' student enrollments for '.$demoBusiness->name);

        // Clear business context
        $currentBusiness->clear();

        $this->command->info('Database seeding completed successfully!');
        $this->command->info('');
        $this->command->info('Login credentials:');
        $this->command->info('  Admin: admin@example.com / password');
        $this->command->info('  Demo:  demo@example.com / password');
        $this->command->info('  Staff: staff@example.com / password');
        */
    }
}
