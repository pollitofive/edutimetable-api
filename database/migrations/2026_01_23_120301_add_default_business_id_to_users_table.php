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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('default_business_id')
                ->nullable()
                ->after('remember_token')
                ->constrained('businesses')
                ->nullOnDelete();

            $table->index('default_business_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['default_business_id']);
            $table->dropIndex(['default_business_id']);
            $table->dropColumn('default_business_id');
        });
    }
};
