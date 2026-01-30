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
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();

            // Foreign keys
            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->foreignId('schedule_id')
                ->constrained('schedules')
                ->cascadeOnDelete();

            // Enrollment data
            $table->timestamp('enrolled_at')->useCurrent();
            $table->enum('status', ['active', 'completed', 'dropped', 'pending'])
                ->default('pending');
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes for better query performance
            $table->index(['business_id', 'student_id', 'status']);
            $table->index(['business_id', 'schedule_id', 'status']);

            // Unique constraint: student can't enroll twice in same schedule within same business
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
        Schema::dropIfExists('student_enrollments');
    }
};
