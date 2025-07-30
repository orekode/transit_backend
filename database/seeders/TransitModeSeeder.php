<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\TransitMode;

class TransitModeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rides = [
            [
                "name" => "Train",
                "image" => "train.png",
                "reward_per_km" => "5",
            ],
            [
                "name" => "Bus",
                "image" => "bus.png",
                "reward_per_km" => "4",
            ],
            [
                "name" => "Electric Car",
                "image" => "electric_car.png",
                "reward_per_km" => "6",
            ],
            [
                "name" => "Electric Scooter",
                "image" => "electric_scooter.png",
                "reward_per_km" => "7",
            ],
            [
                "name" => "Bicycle",
                "image" => "bicycle.png",
                "reward_per_km" => "10",
            ],
            [
                "name" => "Electric Bike",
                "image" => "electric_bike.png",
                "reward_per_km" => "8",
            ],
        ];

        foreach ($rides as $ride) {
            TransitMode::create($ride);
        }
    }
}
