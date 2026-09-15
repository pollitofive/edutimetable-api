<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use App\Services\CurrentBusiness;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
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
        // Order matters: courses/schedules must exist before students so each
        // student's course_level_id reflects the course they actually enroll in,
        // and availabilities are derived from the enrollments that follow.
        $this->command->info('Seeding data for Default Business...');

        app(CurrentBusiness::class)->setId($defaultBusiness->id);

        $this->call([
            TrackSeeder::class,
            CourseLevelSeeder::class,
            TeacherSeeder::class,
            CourseSeeder::class,
            StudentSeeder::class,
            StudentEnrollmentSeeder::class,
            StudentAvailabilitySeeder::class,
        ]);

        $this->command->info('');
        $this->command->info('Database seeding completed!');
        $this->command->info('  Admin:  admin@example.com / password');
        $this->command->info('  Demo:   administracion@ieliargentina.com.ar / 12345678');
        $this->command->info('  Staff:  staff@example.com / password');
    }
}