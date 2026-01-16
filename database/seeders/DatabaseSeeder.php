<?php

namespace Database\Seeders;

use App\Models\StudentAvailability;
use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\Course;
use App\Models\Schedule;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create test user
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password')
        ]);

        // Create teachers FIRST
        $teachers = Teacher::factory(10)->create();

        // Create students
        Student::factory(20)->create();

        // Create courses WITHOUT teachers
        $courses = Course::factory(15)->create();

        // Create schedules with explicit course AND teacher
        foreach ($courses as $course) {
            $numSchedules = rand(1, 3);

            for ($i = 0; $i < $numSchedules; $i++) {
                // Randomly assign a teacher to each schedule
                $randomTeacher = $teachers->random();

                Schedule::factory()->create([
                    'course_id' => $course->id,
                    'teacher_id' => $randomTeacher->id,  // NEW
                ]);
            }
        }

        StudentAvailability::factory(10)->create();
    }
}
