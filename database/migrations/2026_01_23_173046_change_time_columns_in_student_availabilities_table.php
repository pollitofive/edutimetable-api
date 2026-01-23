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
        $driver = DB::connection()->getDriverName();

        // Step 0: For SQLite, drop unique constraint first (required before dropping columns)
        if ($driver === 'sqlite') {
            Schema::table('student_availabilities', function (Blueprint $table) {
                $table->dropUnique('student_avail_business_unique');
            });
        }

        // Step 1: Add new temporary TIME columns
        Schema::table('student_availabilities', function (Blueprint $table) {
            $table->time('start_time_t')->nullable()->after('start_time');
            $table->time('end_time_t')->nullable()->after('end_time');
        });

        // Step 2: Backfill data from varchar to TIME columns
        if ($driver === 'mysql') {
            // MySQL-specific backfill with STR_TO_DATE
            DB::statement('
                UPDATE student_availabilities
                SET start_time_t = CASE
                    WHEN start_time REGEXP "^[0-9]{2}:[0-9]{2}:[0-9]{2}$" THEN STR_TO_DATE(start_time, "%H:%i:%s")
                    WHEN start_time REGEXP "^[0-9]{2}:[0-9]{2}$" THEN STR_TO_DATE(start_time, "%H:%i")
                    ELSE NULL
                END
            ');

            DB::statement('
                UPDATE student_availabilities
                SET end_time_t = CASE
                    WHEN end_time REGEXP "^[0-9]{2}:[0-9]{2}:[0-9]{2}$" THEN STR_TO_DATE(end_time, "%H:%i:%s")
                    WHEN end_time REGEXP "^[0-9]{2}:[0-9]{2}$" THEN STR_TO_DATE(end_time, "%H:%i")
                    ELSE NULL
                END
            ');
        } else {
            // SQLite and other databases: direct copy (time format is already compatible)
            DB::statement('UPDATE student_availabilities SET start_time_t = start_time WHERE start_time IS NOT NULL');
            DB::statement('UPDATE student_availabilities SET end_time_t = end_time WHERE end_time IS NOT NULL');
        }

        // Step 3: Drop old varchar columns
        Schema::table('student_availabilities', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });

        // Step 4: Rename new columns to final names
        Schema::table('student_availabilities', function (Blueprint $table) {
            $table->renameColumn('start_time_t', 'start_time');
            $table->renameColumn('end_time_t', 'end_time');
        });

        // Step 5: Recreate unique constraint for SQLite
        if ($driver === 'sqlite') {
            Schema::table('student_availabilities', function (Blueprint $table) {
                $table->unique(
                    ['business_id', 'student_id', 'day_of_week', 'start_time', 'end_time'],
                    'student_avail_business_unique'
                );
            });
        }

        // Step 6: Make columns NOT NULL after data migration
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE student_availabilities MODIFY start_time TIME NOT NULL');
            DB::statement('ALTER TABLE student_availabilities MODIFY end_time TIME NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Convert back to nullable varchar temporarily
        Schema::table('student_availabilities', function (Blueprint $table) {
            $table->string('start_time_varchar', 255)->nullable()->after('start_time');
            $table->string('end_time_varchar', 255)->nullable()->after('end_time');
        });

        // Step 2: Backfill from TIME to varchar
        DB::statement('
            UPDATE student_availabilities
            SET start_time_varchar = TIME_FORMAT(start_time, "%H:%i:%s")
        ');

        DB::statement('
            UPDATE student_availabilities
            SET end_time_varchar = TIME_FORMAT(end_time, "%H:%i:%s")
        ');

        // Step 3: Drop TIME columns
        Schema::table('student_availabilities', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });

        // Step 4: Rename varchar columns back
        Schema::table('student_availabilities', function (Blueprint $table) {
            $table->renameColumn('start_time_varchar', 'start_time');
            $table->renameColumn('end_time_varchar', 'end_time');
        });

        // Step 5: Make NOT NULL
        DB::statement('ALTER TABLE student_availabilities MODIFY start_time VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE student_availabilities MODIFY end_time VARCHAR(255) NOT NULL');
    }
};
