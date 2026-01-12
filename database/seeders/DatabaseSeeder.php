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
            'email' => 'test@example.com',
        ]);

        // Create teachers
        Teacher::factory(10)->create();

        // Create students
        Student::factory(20)->create();

        // Create courses
        Course::factory(15)->create();

        // Create schedules for courses (1-3 schedules per course)
        $courses = Course::all();
        foreach ($courses as $course) {
            Schedule::factory(rand(1, 3))->create([
                'course_id' => $course->id
            ]);
        }

        StudentAvailability::factory(10)->create();
    }
}
