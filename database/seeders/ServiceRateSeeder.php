<?php

namespace Database\Seeders;

use App\Enums\ChargeType;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServiceRateSeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today()->toDateString();
        $now   = now();

        $defaults = [
            [
                'name'          => 'Thuế du lịch',
                'charge_type'   => ChargeType::CityTax->value,
                'unit_price'    => '50000.00',
                'unit_label'    => 'đêm',
                'display_order' => 90,
            ],
            [
                'name'          => 'Người thêm / đêm',
                'charge_type'   => ChargeType::ExtraPerson->value,
                'unit_price'    => '200000.00',
                'unit_label'    => 'người',
                'display_order' => 91,
            ],
            [
                'name'          => 'Giường phụ / đêm',
                'charge_type'   => ChargeType::ExtraBed->value,
                'unit_price'    => '150000.00',
                'unit_label'    => 'giường',
                'display_order' => 92,
            ],
        ];

        foreach ($defaults as $rate) {
            $alreadyExists = DB::table('service_rates')
                ->where('charge_type', $rate['charge_type'])
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            DB::table('service_rates')->insert([
                'name'            => $rate['name'],
                'charge_type'     => $rate['charge_type'],
                'unit_price'      => $rate['unit_price'],
                'effective_from'  => $today,
                'unit_label'      => $rate['unit_label'],
                'tax_rate'        => '0.0000',
                'gl_account_code' => null,
                'is_active'       => 1,
                'display_order'   => $rate['display_order'],
                'created_by'      => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }
}
