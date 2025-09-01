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
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0..6 (Sun..Sat)
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->index(['course_id', 'day_of_week']);
            // Optional uniqueness to avoid exact duplicates:
            $table->unique(['course_id', 'day_of_week', 'starts_at', 'ends_at']);
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
