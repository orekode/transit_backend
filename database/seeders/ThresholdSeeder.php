<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Threshold;

class ThresholdSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
                // Clear existing records to avoid duplicates
        Threshold::truncate();

        // Seed thresholds for user, city, and system
        Threshold::create([
            'type' => 'user',
            'threshold' => 100, // Example: 100 trips per user per day
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Threshold::create([
            'type' => 'city',
            'threshold' => 50, // Example: 50 trips per city per day
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Threshold::create([
            'type' => 'system',
            'threshold' => 1000, // Example: 1000 trips system-wide per day
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
