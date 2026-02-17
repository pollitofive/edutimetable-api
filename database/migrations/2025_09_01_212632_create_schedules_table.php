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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('group_id')->nullable();
            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->constrained('teachers')
                ->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('day_of_week'); // 0..6 (Sun..Sat)
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedInteger('capacity')->default(5);
            $table->timestamps();

            // Unique constraint: prevent duplicate schedules within business
            $table->unique(
                ['business_id', 'course_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at'],
                'schedules_business_course_teacher_time_unique'
            );

            // Indexes for efficient queries
            $table->index(['business_id', 'group_id'], 'schedules_business_group_idx');
            $table->index(
                ['business_id', 'course_id', 'day_of_week', 'starts_at', 'ends_at'],
                'schedules_business_course_time_idx'
            );
            $table->index(
                ['business_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at'],
                'schedules_business_teacher_time_idx'
            );

            // DB-level CHECK is nice if available (MySQL 8+), but we still validate in app:
            // $table->check('starts_at < ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
