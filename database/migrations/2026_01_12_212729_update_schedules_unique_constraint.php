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
        Schema::table('schedules', function (Blueprint $table) {
            // Drop old unique constraint
            $table->dropUnique(['course_id', 'day_of_week', 'starts_at', 'ends_at']);

            // Add new unique constraint including teacher_id
            $table->unique(
                ['course_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at'],
                'schedules_course_teacher_time_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropUnique('schedules_course_teacher_time_unique');

            $table->unique(
                ['course_id', 'day_of_week', 'starts_at', 'ends_at']
            );
        });
    }
};
