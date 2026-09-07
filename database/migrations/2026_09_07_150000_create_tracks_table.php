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
        Schema::create('tracks', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('business_id');
            $table->string('name');

            $table->timestamps();

            $table->unique(['business_id', 'name'], 'tracks_business_name_unique');

            // clave única para FK compuesta (MySQL 5.7 lo necesita)
            $table->unique(['business_id', 'id'], 'tracks_business_id_id_unique');

            $table->foreign('business_id')
                ->references('id')->on('businesses')
                ->onDelete('cascade')->onUpdate('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};