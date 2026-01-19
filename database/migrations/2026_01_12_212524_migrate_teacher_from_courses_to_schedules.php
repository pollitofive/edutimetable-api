<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all schedules that need teacher_id
        $schedules = DB::table('schedules')
            ->whereNull('teacher_id')
            ->get();

        // Update each schedule with the teacher_id from its course
        foreach ($schedules as $schedule) {
            $course = DB::table('courses')->find($schedule->course_id);

            if ($course && $course->teacher_id) {
                DB::table('schedules')
                    ->where('id', $schedule->id)
                    ->update(['teacher_id' => $course->teacher_id]);
            }
        }

        // Verify all schedules have a teacher
        $orphanedSchedules = DB::table('schedules')
            ->whereNull('teacher_id')
            ->count();

        if ($orphanedSchedules > 0) {
            throw new \Exception(
                "Migration failed: {$orphanedSchedules} schedules still have NULL teacher_id. ".
                'This likely means some courses have been deleted or have no teacher. '.
                'Please fix data integrity before proceeding.'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Set all schedule teacher_ids back to NULL
        DB::table('schedules')->update(['teacher_id' => null]);
    }
};
