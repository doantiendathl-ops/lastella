<?php

namespace Database\Seeders;

use App\Models\RoomType;
use Illuminate\Database\Seeder;

class RoomTypeSeeder extends Seeder
{
    public function run(): void
    {
        $roomTypes = [
            [
                'code' => 'TWIN',
                'name' => 'Twin',
                'description' => 'Two single beds, each 1.2m wide.',
                'standard_adults' => 2,
                'max_adults' => 2,
                'free_children' => 1,
            ],
            [
                'code' => 'DOUBLE',
                'name' => 'Double',
                'description' => 'One double bed, 1.8m wide.',
                'standard_adults' => 2,
                'max_adults' => 2,
                'free_children' => 1,
            ],
            [
                'code' => 'TRIP',
                'name' => 'Trip',
                'description' => 'Three single beds, each 1.2m wide.',
                'standard_adults' => 3,
                'max_adults' => 3,
                'free_children' => 1,
            ],
            [
                'code' => 'FAMILY',
                'name' => 'Family',
                'description' => 'Four single beds, each 1.2m wide.',
                'standard_adults' => 4,
                'max_adults' => 4,
                'free_children' => 2,
            ],
            [
                'code' => 'TRIP_FAMILY',
                'name' => 'Trip Family',
                'description' => 'One 1.2m bed and one 1.5m bed.',
                'standard_adults' => 3,
                'max_adults' => 3,
                'free_children' => 2,
            ],
        ];

        foreach ($roomTypes as $roomType) {
            RoomType::updateOrCreate(['code' => $roomType['code']], $roomType);
        }
    }
}
