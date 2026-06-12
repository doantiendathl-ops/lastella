<?php

namespace Database\Seeders;

use App\Enums\RateStatus;
use App\Models\RoomRate;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

class RoomRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            'TWIN' => [1600, 450, 500, 250, 500, 500],
            'DOUBLE' => [1800, 500, 500, 250, 500, 500],
            'TRIP' => [2300, 650, 500, 250, 600, 600],
            'FAMILY' => [3000, 800, 500, 250, 700, 700],
            'TRIP_FAMILY' => [2600, 700, 500, 250, 650, 650],
        ];

        foreach ($rates as $code => $rate) {
            $roomType = RoomType::where('code', $code)->firstOrFail();

            RoomRate::updateOrCreate(
                [
                    'room_type_id' => $roomType->id,
                    'valid_from' => now()->startOfYear()->toDateString(),
                    'valid_to' => null,
                ],
                [
                    'overnight_price' => $rate[0],
                    'hourly_price' => $rate[1],
                    'extra_adult_price' => $rate[2],
                    'extra_child_price' => $rate[3],
                    'early_checkin_price' => $rate[4],
                    'late_checkout_price' => $rate[5],
                    'status' => RateStatus::Active,
                ],
            );
        }
    }
}
