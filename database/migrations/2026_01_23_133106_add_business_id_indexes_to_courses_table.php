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
            // Add composite index for efficient business_id + name lookups
            $table->index(['business_id', 'name'], 'courses_business_name_index');

            // Add unique constraint to prevent duplicate course names within same business
            $table->unique(['business_id', 'name'], 'courses_business_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropUnique('courses_business_name_unique');
            $table->dropIndex('courses_business_name_index');
        });
    }
};
