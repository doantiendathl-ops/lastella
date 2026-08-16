<?php

namespace Database\Seeders;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceScope;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — canonical Service
 * seed. Idempotent (firstOrCreate), so re-running `php artisan db:seed`
 * never duplicates rows.
 *
 * Slice 1 flagship: "Giường phụ" — replaces the production `GIUONGPHU`
 * mistake. Price (150.000đ, effective 2026-08-01) matches the
 * currently-active service_rates EXTRA_BED rate — the one real price ever
 * actually used to bill guests — carried forward as-is, not re-derived.
 *
 * Slice 2: "Ăn sáng" and "Người thêm" — both BOOKING-scoped, PER_NIGHT,
 * reusing the exact engine Slice 1 proved (including the "posts once per
 * night regardless of room count" fix). "Ăn sáng" price (120.000đ,
 * effective 2026-08-01) matches the real active service_rates
 * FOOD_BEVERAGE rate, same carry-forward principle as Giường phụ.
 * "Người thêm" is seeded WITHOUT a price deliberately — production's
 * service_rates has no EXTRA_PERSON row at all, so there is no real number
 * to carry forward; inventing one here would be exactly the kind of
 * unverified guess this whole effort exists to eliminate. The Service row
 * exists and is visible in the admin catalog, but enroll() correctly
 * refuses ("chưa có giá chuẩn") until an Admin sets a real price.
 */
class UnifiedServiceSeeder extends Seeder
{
    public function run(): void
    {
        $bedConfig = ServiceCategory::firstOrCreate(
            ['code' => 'BED_CONFIG'],
            ['name' => 'Cấu hình phòng', 'sort_order' => 10, 'is_active' => true],
        );

        $foodBeverage = ServiceCategory::firstOrCreate(
            ['code' => 'FOOD_BEVERAGE'],
            ['name' => 'Ăn uống', 'sort_order' => 20, 'is_active' => true],
        );

        $guestSurcharge = ServiceCategory::firstOrCreate(
            ['code' => 'GUEST_SURCHARGE'],
            ['name' => 'Phụ thu theo khách', 'sort_order' => 30, 'is_active' => true],
        );

        $extraBed = Service::firstOrCreate(
            ['code' => 'EXTRA_BED_PER_NIGHT'],
            [
                'category_id' => $bedConfig->id,
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

        if (! $extraBed->prices()->exists()) {
            $extraBed->prices()->create([
                'unit_price' => 150000,
                'effective_from' => '2026-08-01',
                'is_active' => true,
            ]);
        }

        $breakfast = Service::firstOrCreate(
            ['code' => 'BREAKFAST_PER_NIGHT'],
            [
                'category_id' => $foodBeverage->id,
                'name' => 'Ăn sáng',
                'description' => 'Ăn sáng tính theo từng đêm, cho toàn booking.',
                'is_chargeable' => true,
                'scope' => ServiceScope::Booking->value,
                'billing_mode' => ServiceBillingMode::PerNight->value,
                'quantity_enabled' => false,
                'default_quantity' => 1,
                'unit_label' => 'đêm',
                'fulfillment_required' => false,
                'is_active' => true,
                'is_bookable' => true,
                'sort_order' => 20,
            ],
        );

        if (! $breakfast->prices()->exists()) {
            $breakfast->prices()->create([
                'unit_price' => 120000,
                'effective_from' => '2026-08-01',
                'is_active' => true,
            ]);
        }

        Service::firstOrCreate(
            ['code' => 'EXTRA_PERSON_PER_NIGHT'],
            [
                'category_id' => $guestSurcharge->id,
                'name' => 'Người thêm',
                'description' => 'Người thêm tính theo từng đêm, cho toàn booking.',
                'is_chargeable' => true,
                'scope' => ServiceScope::Booking->value,
                'billing_mode' => ServiceBillingMode::PerNight->value,
                'quantity_enabled' => true,
                'default_quantity' => 1,
                'unit_label' => 'người',
                'fulfillment_required' => false,
                'is_active' => true,
                'is_bookable' => true,
                'sort_order' => 30,
            ],
            // No price row created here — see class docblock. Admin must add one via
            // /admin/services before this Service becomes enrollable.
        );
    }
}
