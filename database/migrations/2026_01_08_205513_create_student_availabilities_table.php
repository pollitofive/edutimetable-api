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
        Schema::create('student_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('day_of_week'); // 0=Monday, 1=Tuesday, ..., 6=Sunday
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            // Prevent duplicate availability slots per business
            $table->unique(
                ['business_id', 'student_id', 'day_of_week', 'start_time', 'end_time'],
                'student_avail_business_unique'
            );

            // Composite indexes for efficient queries
            $table->index(['business_id', 'student_id', 'day_of_week'], 'student_avail_lookup_index');
            $table->index(['business_id', 'day_of_week'], 'student_avail_day_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_availabilities');
    }
};
