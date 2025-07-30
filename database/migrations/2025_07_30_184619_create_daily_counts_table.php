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
        Schema::create('daily_counts', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['wallet', 'city']);
            $table->string('value');
            $table->integer('count')->default(0);
            $table->date('date');
            $table->timestamps();
            
            $table->unique(['type', 'value', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_counts');
    }
};
