<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // TEACHERS: Add business_id and update constraints
        Schema::table('teachers', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->unique(['business_id', 'email'], 'teachers_business_email_unique');
        });

        // COURSES: Add business_id
        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index('business_id');
        });

        // STUDENTS: Add business_id and update constraints
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropUnique(['code']);
            $table->unique(['business_id', 'email'], 'students_business_email_unique');
            $table->unique(['business_id', 'code'], 'students_business_code_unique');
        });

        // STUDENT_AVAILABILITIES: Add business_id and update constraints
        Schema::table('student_availabilities', function (Blueprint $table) {
            // Drop FK first
            $table->dropForeign(['student_id']);
        });

        Schema::table('student_availabilities', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::table('student_availabilities', function (Blueprint $table) {
            // Drop old unique
            $table->dropUnique('student_avail_unique');
            // Add new unique
            $table->unique(
                ['business_id', 'student_id', 'day_of_week', 'start_time', 'end_time'],
                'student_avail_business_unique'
            );
            // Recreate FK
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
        });

        // SCHEDULES: Add business_id and update constraints
        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropUnique('schedules_course_teacher_time_unique');
            $table->unique(
                ['business_id', 'course_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at'],
                'schedules_business_course_teacher_time_unique'
            );
        });

        // STUDENT_ENROLLMENTS: Add business_id and update constraints
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropUnique('unique_student_schedule');
            $table->unique(
                ['business_id', 'student_id', 'schedule_id'],
                'unique_business_student_schedule'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['teachers', 'courses', 'students', 'student_availabilities', 'schedules', 'student_enrollments'];

        // Restore original unique constraints first
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropUnique('teachers_business_email_unique');
            $table->unique('email');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_business_email_unique');
            $table->dropUnique('students_business_code_unique');
            $table->unique('email');
            $table->unique('code');
        });

        Schema::table('student_availabilities', function (Blueprint $table) {
            $table->dropUnique('student_avail_business_unique');
            $table->unique(
                ['student_id', 'day_of_week', 'start_time', 'end_time'],
                'student_avail_unique'
            );
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropUnique('schedules_business_course_teacher_time_unique');
            $table->unique(
                ['course_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at'],
                'schedules_course_teacher_time_unique'
            );
        });

        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropUnique('unique_business_student_schedule');
            $table->unique(['student_id', 'schedule_id'], 'unique_student_schedule');
        });

        // Then drop business_id columns
        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['business_id']);
                $table->dropIndex(['business_id']);
                $table->dropColumn('business_id');
            });
        }
    }
};
