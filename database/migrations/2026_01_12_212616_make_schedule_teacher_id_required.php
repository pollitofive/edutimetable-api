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
        Schema::table('schedules', function (Blueprint $table) {
            // Drop the nullable foreign key
            $table->dropForeign(['teacher_id']);

            // Modify column to NOT NULL
            $table->foreignId('teacher_id')
                ->nullable(false)
                ->change();

            // Re-add foreign key with CASCADE delete
            $table->foreign('teacher_id')
                ->references('id')
                ->on('teachers')
                ->cascadeOnDelete(); // If teacher deleted, delete schedules
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);

            $table->foreignId('teacher_id')
                ->nullable()
                ->change();

            $table->foreign('teacher_id')
                ->references('id')
                ->on('teachers')
                ->nullOnDelete();
        });
    }
};
