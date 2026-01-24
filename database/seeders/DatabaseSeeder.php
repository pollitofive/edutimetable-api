<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Course;
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
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('Starting database seeding...');

        // ========================================
        // 1. Create Businesses
        // ========================================
        $this->command->info('Creating businesses...');

        $defaultBusiness = Business::create([
            'name' => 'Default Educational Institution',
            'slug' => 'default',
        ]);

        $demoBusiness = Business::create([
            'name' => 'Demo Academy',
            'slug' => 'demo-academy',
        ]);

        $this->command->info("Created businesses: {$defaultBusiness->name}, {$demoBusiness->name}");

        // ========================================
        // 2. Create Users and Associate with Businesses
        // ========================================
        $this->command->info('Creating users...');

        // Admin user for default business
        $adminUser = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'default_business_id' => $defaultBusiness->id,
        ]);

        // Attach admin to default business as owner
        $adminUser->businesses()->attach($defaultBusiness->id, ['role' => 'owner']);
        $this->command->info("User {$adminUser->email} created and attached as owner to {$defaultBusiness->name}");

        // Demo user for demo business
        $demoUser = User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'password' => Hash::make('password'),
            'default_business_id' => $demoBusiness->id,
        ]);

        // Attach demo user to demo business as owner
        $demoUser->businesses()->attach($demoBusiness->id, ['role' => 'owner']);
        $this->command->info("User {$demoUser->email} created and attached as owner to {$demoBusiness->name}");

        // Staff user for default business
        $staffUser = User::factory()->create([
            'name' => 'Staff User',
            'email' => 'staff@example.com',
            'password' => Hash::make('password'),
            'default_business_id' => $defaultBusiness->id,
        ]);

        // Attach staff user to default business as staff
        $staffUser->businesses()->attach($defaultBusiness->id, ['role' => 'staff']);
        $this->command->info("User {$staffUser->email} created and attached as staff to {$defaultBusiness->name}");

        // ========================================
        // 3. Seed Default Business Data
        // ========================================
        $this->command->info('Seeding data for Default Business...');

        // Set the current business context
        $currentBusiness = app(CurrentBusiness::class);
        $currentBusiness->setId($defaultBusiness->id);

        // Create teachers FIRST
        $teachers = Teacher::factory(10)->create();
        $this->command->info("Created {$teachers->count()} teachers for {$defaultBusiness->name}");

        // Create students
        $students = Student::factory(20)->create();
        $this->command->info("Created {$students->count()} students for {$defaultBusiness->name}");

        // Create courses
        $courses = Course::factory(15)->create();
        $this->command->info("Created {$courses->count()} courses for {$defaultBusiness->name}");

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
        $this->command->info("Created {$schedules->count()} schedules for {$defaultBusiness->name}");

        // Create student availabilities
        foreach ($students->random(min(15, $students->count())) as $student) {
            StudentAvailability::factory()->create([
                'student_id' => $student->id,
            ]);
        }
        $this->command->info("Created 15 student availabilities for {$defaultBusiness->name}");

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
        $this->command->info("Created {$enrollmentCount} student enrollments for {$defaultBusiness->name}");

        // ========================================
        // 4. Seed Demo Business Data
        // ========================================
        $this->command->info('Seeding data for Demo Business...');

        // Switch to demo business context
        $currentBusiness->setId($demoBusiness->id);

        // Create teachers for demo business
        $demoTeachers = Teacher::factory(5)->create();
        $this->command->info("Created {$demoTeachers->count()} teachers for {$demoBusiness->name}");

        // Create students for demo business
        $demoStudents = Student::factory(10)->create();
        $this->command->info("Created {$demoStudents->count()} students for {$demoBusiness->name}");

        // Create courses for demo business
        $demoCourses = Course::factory(8)->create();
        $this->command->info("Created {$demoCourses->count()} courses for {$demoBusiness->name}");

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
        $this->command->info("Created {$demoSchedules->count()} schedules for {$demoBusiness->name}");

        // Create student availabilities for demo business
        foreach ($demoStudents->random(min(8, $demoStudents->count())) as $student) {
            StudentAvailability::factory()->create([
                'student_id' => $student->id,
            ]);
        }
        $this->command->info("Created 8 student availabilities for {$demoBusiness->name}");

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
        $this->command->info("Created {$demoEnrollmentCount} student enrollments for {$demoBusiness->name}");

        // Clear business context
        $currentBusiness->clear();

        $this->command->info('Database seeding completed successfully!');
        $this->command->info('');
        $this->command->info('Login credentials:');
        $this->command->info('  Admin: admin@example.com / password');
        $this->command->info('  Demo:  demo@example.com / password');
        $this->command->info('  Staff: staff@example.com / password');
    }
}
