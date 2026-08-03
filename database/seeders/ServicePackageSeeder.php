<?php

namespace Database\Seeders;

use App\Enums\PackageCalculationStrategy;
use App\Enums\PackagePostingFrequency;
use App\Enums\PackageQuantityMode;
use App\Models\ServicePackage;
use Illuminate\Database\Seeder;

/**
 * Backfills the three packages that already exist as hard-coded constants in
 * PackageEnrollmentService::ALLOWED_PACKAGES. Codes are kept identical to the
 * existing booking_package_flags.package_key values for backward
 * compatibility — no price rows are created (no existing price data to
 * migrate safely; admin must enter real prices once the admin UI exists).
 */
class ServicePackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'code'                  => 'BREAKFAST_PER_NIGHT',
                'name'                  => 'Ăn sáng mỗi đêm',
                'charge_type'           => 'FOOD_BEVERAGE',
                'calculation_strategy'  => PackageCalculationStrategy::OncePerStayPerNight->value,
                'quantity_mode'         => PackageQuantityMode::None->value,
                'default_quantity'      => 1,
                'unit_label'            => 'đêm',
                'posting_frequency'     => PackagePostingFrequency::PerNight->value,
                'display_order'         => 10,
            ],
            [
                'code'                  => 'EXTRA_PERSON_PER_NIGHT',
                'name'                  => 'Người thêm / đêm',
                'charge_type'           => 'EXTRA_PERSON',
                'calculation_strategy'  => PackageCalculationStrategy::ManualQuantityPerNight->value,
                'quantity_mode'         => PackageQuantityMode::ManualInput->value,
                'default_quantity'      => 1,
                'unit_label'            => 'người',
                'posting_frequency'     => PackagePostingFrequency::PerNight->value,
                'display_order'         => 20,
            ],
            [
                'code'                  => 'EXTRA_BED_PER_NIGHT',
                'name'                  => 'Giường phụ / đêm',
                'charge_type'           => 'EXTRA_BED',
                'calculation_strategy'  => PackageCalculationStrategy::ManualQuantityPerNight->value,
                'quantity_mode'         => PackageQuantityMode::ManualInput->value,
                'default_quantity'      => 1,
                'unit_label'            => 'giường',
                'posting_frequency'     => PackagePostingFrequency::PerNight->value,
                'display_order'         => 30,
            ],
        ];

        foreach ($packages as $package) {
            $code = $package['code'];
            unset($package['code']);

            ServicePackage::firstOrCreate(['code' => $code], $package);
        }
    }
}
