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
        Schema::table('courses', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['teacher_id']);

            // Drop the column
            $table->dropColumn('teacher_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Re-add teacher_id column (nullable for safety)
            $table->foreignId('teacher_id')
                ->nullable()
                ->after('year')
                ->constrained('teachers')
                ->cascadeOnDelete();
        });
    }
};
