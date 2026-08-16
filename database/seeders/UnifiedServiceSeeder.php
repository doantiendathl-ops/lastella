<?php

namespace Database\Seeders;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceScope;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — Slice 1 flagship
 * migration: the ONE canonical "Giường phụ" Service, replacing the
 * production `GIUONGPHU` mistake. Idempotent (firstOrCreate), so re-running
 * `php artisan db:seed` never duplicates rows.
 *
 * Price (150.000đ, effective 2026-08-01) matches the currently-active
 * service_rates EXTRA_BED rate this session confirmed is the one real
 * price ever actually used to bill guests — carried forward as-is, not
 * re-derived, so Admin only needs to VERIFY it post-migration, not guess it.
 */
class UnifiedServiceSeeder extends Seeder
{
    public function run(): void
    {
        $category = ServiceCategory::firstOrCreate(
            ['code' => 'BED_CONFIG'],
            ['name' => 'Cấu hình phòng', 'sort_order' => 10, 'is_active' => true],
        );

        $service = Service::firstOrCreate(
            ['code' => 'EXTRA_BED_PER_NIGHT'],
            [
                'category_id' => $category->id,
                'name' => 'Giường phụ',
                'description' => 'Giường phụ tính theo từng đêm, theo từng phòng.',
                'is_chargeable' => true,
                'scope' => ServiceScope::Room->value,
                'billing_mode' => ServiceBillingMode::PerNight->value,
                'quantity_enabled' => true,
                'default_quantity' => 1,
                'unit_label' => 'giường',
                'fulfillment_required' => true,
                'is_active' => true,
                'is_bookable' => true,
                'sort_order' => 10,
            ],
        );

        if (! $service->prices()->exists()) {
            $service->prices()->create([
                'unit_price' => 150000,
                'effective_from' => '2026-08-01',
                'is_active' => true,
            ]);
        }
    }
}
