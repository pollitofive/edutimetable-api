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
        if (DB::table('students')->whereNull('business_id')->exists()) {
            throw new RuntimeException('students tiene filas con business_id NULL. Corregí datos o reseteá la DB en dev.');
        }

        // Make business_id NOT NULL in a database-agnostic way
        Schema::table('students', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable(false)->change();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedBigInteger('course_level_id')->after('code');

            $table->index(['business_id', 'course_level_id'], 'students_business_course_level_index');

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
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign('students_business_course_level_foreign');
            $table->dropIndex('students_business_course_level_index');
            $table->dropColumn('course_level_id');
        });

        DB::statement('ALTER TABLE `students` MODIFY `business_id` BIGINT(20) UNSIGNED NULL');
    }
};
