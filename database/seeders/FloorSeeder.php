<?php

namespace Database\Seeders;

use App\Models\Floor;
use Illuminate\Database\Seeder;

class FloorSeeder extends Seeder
{
    public function run(): void
    {
        $floors = [
            ['code' => 'G', 'name' => 'Ground Floor', 'sort_order' => 10],
            ['code' => 'B2', 'name' => 'Basement 2', 'sort_order' => 20],
            ['code' => 'B1', 'name' => 'Basement 1', 'sort_order' => 30],
            ['code' => '1', 'name' => 'Floor 1', 'sort_order' => 40],
            ['code' => '2', 'name' => 'Floor 2', 'sort_order' => 50],
            ['code' => '3', 'name' => 'Floor 3', 'sort_order' => 60],
            ['code' => '4', 'name' => 'Floor 4', 'sort_order' => 70],
            ['code' => '5', 'name' => 'Floor 5', 'sort_order' => 80],
            ['code' => '6', 'name' => 'Floor 6', 'sort_order' => 90],
            ['code' => '7', 'name' => 'Floor 7', 'sort_order' => 100],
            ['code' => '8', 'name' => 'Floor 8', 'sort_order' => 110],
        ];

        foreach ($floors as $floor) {
            Floor::updateOrCreate(['code' => $floor['code']], $floor);
        }
    }
}
