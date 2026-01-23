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
        Schema::table('teachers', function (Blueprint $table) {
            // Drop the simple business_id index first
            $table->dropIndex(['business_id']);

            // Add composite index for efficient pagination by business
            $table->index(['business_id', 'id'], 'teachers_business_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            // Drop composite index
            $table->dropIndex('teachers_business_id_index');

            // Restore simple business_id index
            $table->index('business_id');
        });
    }
};
