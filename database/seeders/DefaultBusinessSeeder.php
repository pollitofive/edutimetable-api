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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultBusinessSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Starting multi-tenant bootstrap...');

        // Create default business
        $this->command->info('Creating default business...');
        $business = Business::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Business']
        );

        $this->command->info("Default business created with ID: {$business->id}");

        // Assign all users to default business as owners
        $this->command->info('Assigning users to default business...');
        $users = User::all();

        foreach ($users as $user) {
            // Attach user to business if not already attached
            if (! $user->businesses()->where('business_id', $business->id)->exists()) {
                $user->businesses()->attach($business->id, ['role' => 'owner']);
                $this->command->info("  - User {$user->email} attached as owner");
            }

            // Set default business if not set
            if (! $user->default_business_id) {
                $user->default_business_id = $business->id;
                $user->save();
                $this->command->info("  - Default business set for {$user->email}");
            }
        }

        // Backfill business_id in all domain tables
        $this->command->info('Backfilling business_id in domain tables...');

        $tables = [
            'teachers' => Teacher::class,
            'courses' => Course::class,
            'students' => Student::class,
            'student_availabilities' => StudentAvailability::class,
            'schedules' => Schedule::class,
            'student_enrollments' => StudentEnrollment::class,
        ];

        foreach ($tables as $table => $model) {
            // Update records where business_id is null
            $updated = DB::table($table)
                ->whereNull('business_id')
                ->update(['business_id' => $business->id]);

            $this->command->info("  - Updated {$updated} records in {$table}");
        }

        $this->command->info('Multi-tenant bootstrap completed successfully!');
    }
}
