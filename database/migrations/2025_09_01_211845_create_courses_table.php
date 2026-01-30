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
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('course_level_id');
            $table->timestamps();

            // Composite index for efficient business_id + course_level_id lookups
            $table->index(['business_id', 'course_level_id'], 'courses_business_course_level_index');

            // Composite index for efficient business_id + name lookups
            $table->index(['business_id', 'name'], 'courses_business_name_index');

            // Unique constraint to prevent duplicate course names within same business
            $table->unique(['business_id', 'name'], 'courses_business_name_unique');

            // Composite FK: prevents cross-tenant references
            $table->foreign(['business_id', 'course_level_id'], 'courses_business_course_level_foreign')
                ->references(['business_id', 'id'])->on('course_levels')
                ->onDelete('restrict')->onUpdate('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
