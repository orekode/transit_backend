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
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('wallet');
            $table->unsignedBigInteger('trip_duration');
            $table->json('location');
            $table->json('gyroscope');
            $table->json('accelerometer');
            $table->json('network');
            $table->json('summary');
            $table->unsignedBigInteger('photo_score');
            $table->json('start_pos');
            $table->json('final_pos');
            $table->json('last_pos');
            $table->string('mode');
            $table->string('type')->default("pending");
            $table->string('state')->default("pending");
            $table->string('origin');
            $table->string('destination');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
