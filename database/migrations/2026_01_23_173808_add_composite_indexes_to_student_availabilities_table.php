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
        Schema::table('student_availabilities', function (Blueprint $table) {
            // Add composite index for common queries (student lookups by day)
            $table->index(['business_id', 'student_id', 'day_of_week'], 'student_avail_lookup_index');

            // Optional: index for business-wide queries by day
            $table->index(['business_id', 'day_of_week'], 'student_avail_day_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_availabilities', function (Blueprint $table) {
            $table->dropIndex('student_avail_day_index');
            $table->dropIndex('student_avail_lookup_index');
        });
    }
};
