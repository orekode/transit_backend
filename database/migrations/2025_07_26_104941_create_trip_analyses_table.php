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
        // Main trip analysis table
        Schema::create('trip_analyses', function (Blueprint $table) {
            $table->id();
            $table->string("wallet");
            $table->float("photo_check");
            $table->boolean('overall_realness')->default(false);
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->integer('total_windows')->default(0);
            $table->integer('real_windows')->default(0);
            $table->timestamp('analysis_timestamp');
            $table->string('analysis_version', 10)->default('2.0');
            $table->string('mode');
            $table->string('state')->default("active");
            $table->json('location_stream')->nullable(); // Store analyzed path and legitimacy
            $table->timestamps();

            $table->index(['wallet', 'created_at']);
            $table->index('analysis_timestamp');
            $table->index('overall_realness');
        });

        // Sensor summaries table
        Schema::create('trip_sensor_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_analysis_id')->constrained('trip_analyses')->onDelete('cascade');
            $table->string('sensor_type', 50);
            $table->integer('window_count')->default(0);
            
            // Statistical measures
            $table->decimal('avg_variance', 10, 6)->nullable();
            $table->decimal('avg_cv', 10, 6)->nullable();
            $table->decimal('avg_entropy', 10, 6)->nullable();
            $table->decimal('avg_autocorrelation', 10, 6)->nullable();
            $table->decimal('avg_frequency_power', 10, 6)->nullable();
            $table->decimal('avg_z_score_anomalies', 10, 6)->nullable();
            
            // Optional sensor-specific measures
            $table->decimal('avg_magnitude_variance', 10, 6)->nullable();
            $table->decimal('avg_acceleration_changes', 10, 6)->nullable();
            $table->decimal('avg_cross_correlation', 10, 6)->nullable();
            
            $table->timestamps();

            $table->index(['trip_analysis_id', 'sensor_type']);
        });

        // Suspicious windows table
        Schema::create('trip_suspicious_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_sensor_summary_id')->constrained('trip_sensor_summaries')->onDelete('cascade');
            $table->integer('window_index');
            $table->boolean('is_real')->default(false);
            $table->json('reasons')->nullable(); // Array of reasons why it's suspicious
            $table->timestamps();

            // $table->index(['trip_sensor_summary_id', 'window_index']);
            $table->index('is_real');
        });

        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_suspicious_windows');
        Schema::dropIfExists('trip_sensor_summaries');
        Schema::dropIfExists('trip_analyses');
    }
};