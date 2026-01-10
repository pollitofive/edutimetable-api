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
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('day_of_week'); // 0=Monday, 1=Tuesday, ..., 6=Sunday
            $table->string('start_time'); // "HH:mm" format
            $table->string('end_time'); // "HH:mm" format
            $table->timestamps();

            // Prevent duplicate availability slots
            $table->unique(['student_id', 'day_of_week', 'start_time', 'end_time'], 'student_avail_unique');
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
