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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('code');
            $table->unsignedBigInteger('course_level_id');
            $table->timestamps();

            // Composite unique constraints: email and code unique per business
            $table->unique(['business_id', 'email'], 'students_business_email_unique');
            $table->unique(['business_id', 'code'], 'students_business_code_unique');

            // Composite index for efficient business_id + course_level_id lookups
            $table->index(['business_id', 'course_level_id'], 'students_business_course_level_index');

            // Composite FK: prevents cross-tenant references
            $table->foreign(['business_id', 'course_level_id'], 'students_business_course_level_foreign')
                ->references(['business_id', 'id'])->on('course_levels')
                ->onDelete('restrict')->onUpdate('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
