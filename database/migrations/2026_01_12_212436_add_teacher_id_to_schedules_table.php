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
            // Add teacher_id as nullable first (for safe migration)
            $table->foreignId('teacher_id')
                ->nullable()
                ->after('course_id')
                ->constrained('teachers')
                ->nullOnDelete(); // If teacher deleted, set NULL temporarily

            // Add index for performance
            $table->index(['teacher_id', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
            $table->dropIndex(['teacher_id', 'day_of_week']);
            $table->dropColumn('teacher_id');
        });
    }
};
