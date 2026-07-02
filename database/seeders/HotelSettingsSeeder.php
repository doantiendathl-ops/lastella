<?php

namespace Database\Seeders;

use App\Services\HotelSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HotelSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (HotelSettingsService::$defaults as $key => $meta) {
            DB::table('hotel_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value'       => $meta['value'],
                    'value_type'  => $meta['type'],
                    'description' => $meta['description'],
                    'updated_by'  => null,
                    'updated_at'  => null,
                ],
            );
        }
    }
}
