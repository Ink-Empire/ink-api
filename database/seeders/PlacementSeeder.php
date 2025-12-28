<?php

namespace Database\Seeders;

use App\Models\Placement;
use Illuminate\Database\Seeder;

class PlacementSeeder extends Seeder
{
    public function run(): void
    {
        $placements = [
            // Arms
            ['name' => 'Arm', 'sort_order' => 10],
            ['name' => 'Forearm', 'sort_order' => 11],
            ['name' => 'Upper Arm', 'sort_order' => 12],
            ['name' => 'Wrist', 'sort_order' => 13],
            ['name' => 'Hand', 'sort_order' => 14],
            ['name' => 'Palm', 'sort_order' => 15],
            ['name' => 'Finger', 'sort_order' => 16],

            // Legs
            ['name' => 'Leg', 'sort_order' => 20],
            ['name' => 'Thigh', 'sort_order' => 21],
            ['name' => 'Calf', 'sort_order' => 22],
            ['name' => 'Ankle', 'sort_order' => 23],
            ['name' => 'Foot', 'sort_order' => 24],
            ['name' => 'Sole', 'sort_order' => 25],


            // Back
            ['name' => 'Back', 'sort_order' => 30],
            ['name' => 'Upper Back', 'sort_order' => 31],
            ['name' => 'Lower Back', 'sort_order' => 32],
            ['name' => 'Spine', 'sort_order' => 33],

            // Torso
            ['name' => 'Chest', 'sort_order' => 40],
            ['name' => 'Ribs', 'sort_order' => 41],
            ['name' => 'Stomach', 'sort_order' => 42],
            ['name' => 'Side', 'sort_order' => 43],

            // Head/Neck
            ['name' => 'Shoulder', 'sort_order' => 50],
            ['name' => 'Neck', 'sort_order' => 51],
            ['name' => 'Behind Ear', 'sort_order' => 52],
            ['name' => 'Head', 'sort_order' => 53],
            ['name' => 'Scalp', 'sort_order' => 54],
            ['name' => 'Face', 'sort_order' => 55],
            ['name' => 'Lip', 'sort_order' => 56],

            // Full coverage
            ['name' => 'Full Sleeve', 'sort_order' => 60],
            ['name' => 'Half Sleeve', 'sort_order' => 61],
            ['name' => 'Full Back', 'sort_order' => 62],
            ['name' => 'Full Body', 'sort_order' => 63],

            // Other
            ['name' => 'Other', 'sort_order' => 100],
        ];

        foreach ($placements as $placement) {
            Placement::firstOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($placement['name'])],
                $placement
            );
        }
    }
}
