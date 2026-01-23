<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Drop old indexes/constraints
        Schema::table('schedules', function (Blueprint $table) {
            // Try to drop existing unique constraint (may have different names)
            $constraintNames = [
                'schedules_course_teacher_time_unique',
                'schedules_course_id_teacher_id_day_of_week_starts_at_ends_at_unique',
            ];

            foreach ($constraintNames as $constraintName) {
                try {
                    DB::statement("DROP INDEX IF EXISTS {$constraintName}");
                } catch (\Exception $e) {
                    // Silently continue - index may not exist
                }
            }

            // Drop old group_id index if exists
            try {
                DB::statement('DROP INDEX IF EXISTS schedules_group_id_index');
            } catch (\Exception $e) {
                // Silently continue
            }
        });

        // Step 2: Create new indexes/constraints (with IF NOT EXISTS logic)
        Schema::table('schedules', function (Blueprint $table) {
            // Check and create unique constraint
            if (! $this->indexExists('schedules_business_course_teacher_time_unique')) {
                $table->unique(
                    ['business_id', 'course_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at'],
                    'schedules_business_course_teacher_time_unique'
                );
            }

            // Check and create business+course+time index
            if (! $this->indexExists('schedules_business_course_time_idx')) {
                $table->index(
                    ['business_id', 'course_id', 'day_of_week', 'starts_at', 'ends_at'],
                    'schedules_business_course_time_idx'
                );
            }

            // Check and create business+teacher+time index
            if (! $this->indexExists('schedules_business_teacher_time_idx')) {
                $table->index(
                    ['business_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at'],
                    'schedules_business_teacher_time_idx'
                );
            }

            // Check and create business+group_id index
            if (! $this->indexExists('schedules_business_group_idx')) {
                $table->index(['business_id', 'group_id'], 'schedules_business_group_idx');
            }
        });
    }

    /**
     * Check if an index exists
     */
    private function indexExists(string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name=?", [$indexName]);

            return count($indexes) > 0;
        }

        if ($driver === 'mysql') {
            $indexes = DB::select(
                'SELECT COUNT(*) as count FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                 AND table_name = ?
                 AND index_name = ?',
                ['schedules', $indexName]
            );

            return $indexes[0]->count > 0;
        }

        return false;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            // Revert indexes
            $table->dropIndex('schedules_business_group_idx');
            $table->index('group_id');

            $table->dropIndex('schedules_business_teacher_time_idx');
            $table->dropIndex('schedules_business_course_time_idx');

            // Revert unique constraint
            $table->dropUnique('schedules_business_course_teacher_time_unique');

            $table->unique(
                ['course_id', 'teacher_id', 'day_of_week', 'starts_at', 'ends_at'],
                'schedules_course_teacher_time_unique'
            );
        });
    }
};
