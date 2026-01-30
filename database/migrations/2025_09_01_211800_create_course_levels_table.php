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
        Schema::create('course_levels', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('business_id');
            $table->string('track');       // English, Portuguese...
            $table->string('name');        // Beginner...
            $table->string('slug');        // beginner...
            $table->unsignedInteger('sort_order'); // 10, 20, 30...

            $table->unsignedBigInteger('next_level_id')->nullable();

            $table->timestamps();

            $table->unique(['business_id', 'track', 'slug'], 'course_levels_business_track_slug_unique');
            $table->index(['business_id', 'track', 'sort_order'], 'course_levels_business_track_sort_index');

            // clave única para FK compuesta (MySQL 5.7 lo necesita)
            $table->unique(['business_id', 'id'], 'course_levels_business_id_id_unique');

            $table->foreign('business_id')
                ->references('id')->on('businesses')
                ->onDelete('cascade')->onUpdate('restrict');

            // Simple FK on next_level_id - cross-tenant check will be done at app level
            $table->foreign('next_level_id')
                ->references('id')->on('course_levels')
                ->onDelete('set null')->onUpdate('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_levels');
    }
};
