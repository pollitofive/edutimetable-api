<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student can study more than one track, so their levels move to a pivot (one level per
 * track, enforced by the validation). There is no backfill: the product has not launched,
 * and staging and prod are rebuilt from the seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // SQLite (tests) drops a foreign key by its columns, MySQL by its name
            $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite'
                ? ['business_id', 'course_level_id']
                : 'students_business_course_level_foreign');
            $table->dropIndex('students_business_course_level_index');
            $table->dropColumn('course_level_id');

            // Target of the pivot's composite FK
            $table->unique(['business_id', 'id'], 'students_business_id_id_unique');
        });

        Schema::create('course_level_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_level_id');
            $table->timestamps();

            $table->unique(['student_id', 'course_level_id'], 'course_level_student_unique');
            $table->index(['business_id', 'course_level_id'], 'course_level_student_business_level_index');

            // Composite FKs: prevent cross-tenant references
            $table->foreign(['business_id', 'student_id'], 'course_level_student_business_student_foreign')
                ->references(['business_id', 'id'])->on('students')
                ->cascadeOnDelete();
            $table->foreign(['business_id', 'course_level_id'], 'course_level_student_business_level_foreign')
                ->references(['business_id', 'id'])->on('course_levels')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_level_student');

        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_business_id_id_unique');
            // Nullable: the rows that existed have no level to go back to
            $table->unsignedBigInteger('course_level_id')->nullable();
            $table->index(['business_id', 'course_level_id'], 'students_business_course_level_index');
            $table->foreign(['business_id', 'course_level_id'], 'students_business_course_level_foreign')
                ->references(['business_id', 'id'])->on('course_levels')
                ->onDelete('restrict')->onUpdate('restrict');
        });
    }
};
