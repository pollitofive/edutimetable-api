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
        // Si hay NULLs, mejor fallar fuerte.
        if (DB::table('courses')->whereNull('business_id')->exists()) {
            throw new RuntimeException('courses tiene filas con business_id NULL. Corregí datos o reseteá la DB en dev.');
        }

        // Make business_id NOT NULL in a database-agnostic way
        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable(false)->change();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('level');
            $table->unsignedBigInteger('course_level_id')->after('name');

            $table->index(['business_id', 'course_level_id'], 'courses_business_course_level_index');

            // FK compuesta: evita cruces de tenant
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
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign('courses_business_course_level_foreign');
            $table->dropIndex('courses_business_course_level_index');
            $table->dropColumn('course_level_id');
            $table->string('level')->nullable()->after('name');
        });

        DB::statement('ALTER TABLE `courses` MODIFY `business_id` BIGINT(20) UNSIGNED NULL');
    }
};
