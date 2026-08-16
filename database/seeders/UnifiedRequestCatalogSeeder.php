<?php

namespace Database\Seeders;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceScope;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — Slice 3: migrates the
 * 24 hard-coded "Yêu cầu đặc biệt" request types (previously split across
 * StoreBookingSpecialRequestRequest::ALLOWED_REQUEST_TYPES on the backend
 * and SpecialRequestPanel.vue::REQUEST_TYPE_CATALOG on the frontend, kept
 * in sync by hand, with no admin CRUD) onto the unified, database-managed
 * Service catalog. All 24 are `is_chargeable = false` — this slice never
 * touches billing.
 *
 * One deliberate exclusion, kept OFF this list on purpose:
 *
 *  - `extra_bed` ("Thêm giường phụ", under bed_config) is NOT migrated
 *    here. A chargeable, room-scoped "Giường phụ" Service already exists
 *    (Slice 1, code EXTRA_BED_PER_NIGHT) — that IS the canonical extra-bed
 *    request now. Seeding a second, free "yêu cầu" entry with the same
 *    real-world meaning would recreate the exact confusion this whole
 *    effort exists to eliminate (staff picking the free "request" instead
 *    of the billable service, and the guest never actually gets billed —
 *    precisely what happened on production before this session started).
 *
 * `twin_to_double` ("Ghép thành giường đôi") IS included — RoomOperations
 * BoardService has been updated (Slice 3) to read it from BOTH this new
 * table and the legacy booking_special_requests table, so the Sơ đồ thao
 * tác "Ghép giường" badge keeps working for staff using either screen.
 * Because the board badge is room-specific, this one Service is seeded
 * with scope=ROOM (not BOTH like its 22 siblings) — a "toàn booking" bed
 * join has no physical meaning, and ROOM scope removes any ambiguous
 * "unresolved / no room chosen" case entirely for the new source.
 */
class UnifiedRequestCatalogSeeder extends Seeder
{
    /**
     * [code, name, category_code, category_name, scope].
     * All rows share: is_chargeable=false, billing_mode=ONE_TIME,
     * quantity_enabled=true, fulfillment_required=true, unit_label='lần' —
     * faithful port of the legacy shape (every BookingSpecialRequest has a
     * required 1-99 quantity and a Pending/Acknowledged/Fulfilled/Cancelled
     * lifecycle regardless of category).
     */
    private const REQUESTS = [
        // bed_config (extra_bed intentionally excluded — see class docblock)
        ['TWIN_KEEP', 'Giữ 2 giường đôi', 'BED_CONFIG', 'Cấu hình giường', ServiceScope::Both],
        ['TWIN_TO_DOUBLE', 'Ghép thành giường đôi', 'BED_CONFIG', 'Cấu hình giường', ServiceScope::Room],
        ['SEPARATE_BEDS', 'Tách giường đơn', 'BED_CONFIG', 'Cấu hình giường', ServiceScope::Both],
        // extra_item
        ['BABY_COT', 'Nôi em bé', 'EXTRA_ITEM', 'Thêm đồ dùng', ServiceScope::Both],
        ['EXTRA_PILLOW', 'Thêm gối', 'EXTRA_ITEM', 'Thêm đồ dùng', ServiceScope::Both],
        ['NON_FEATHER_PILLOW', 'Gối không lông vũ', 'EXTRA_ITEM', 'Thêm đồ dùng', ServiceScope::Both],
        ['EXTRA_BLANKET', 'Thêm chăn', 'EXTRA_ITEM', 'Thêm đồ dùng', ServiceScope::Both],
        ['EXTRA_TOWEL', 'Thêm khăn tắm', 'EXTRA_ITEM', 'Thêm đồ dùng', ServiceScope::Both],
        ['WELCOME_FRUIT', 'Hoa quả chào mừng', 'EXTRA_ITEM', 'Thêm đồ dùng', ServiceScope::Both],
        ['WELCOME_AMENITY', 'Quà chào mừng', 'EXTRA_ITEM', 'Thêm đồ dùng', ServiceScope::Both],
        // decoration
        ['ANNIVERSARY', 'Kỷ niệm ngày cưới', 'DECORATION', 'Trang trí', ServiceScope::Both],
        ['HONEYMOON', 'Tuần trăng mật', 'DECORATION', 'Trang trí', ServiceScope::Both],
        ['BIRTHDAY', 'Sinh nhật', 'DECORATION', 'Trang trí', ServiceScope::Both],
        ['VIP_SETUP', 'Đón khách VIP', 'DECORATION', 'Trang trí', ServiceScope::Both],
        ['FLOWER_ARRANGEMENT', 'Cắm hoa', 'DECORATION', 'Trang trí', ServiceScope::Both],
        // accessibility
        ['WHEELCHAIR', 'Xe lăn', 'ACCESSIBILITY', 'Hỗ trợ đặc biệt', ServiceScope::Both],
        ['NON_SMOKING_PREP', 'Phòng không khói thuốc', 'ACCESSIBILITY', 'Hỗ trợ đặc biệt', ServiceScope::Both],
        ['GROUND_FLOOR', 'Tầng trệt', 'ACCESSIBILITY', 'Hỗ trợ đặc biệt', ServiceScope::Both],
        ['NEAR_ELEVATOR', 'Gần thang máy', 'ACCESSIBILITY', 'Hỗ trợ đặc biệt', ServiceScope::Both],
        // general
        ['LATE_ARRIVAL', 'Đến muộn', 'GENERAL_REQUEST', 'Yêu cầu khác', ServiceScope::Both],
        ['AIRPORT_PICKUP', 'Đón sân bay', 'GENERAL_REQUEST', 'Yêu cầu khác', ServiceScope::Both],
        ['CONNECTING_ROOM', 'Phòng thông nhau', 'GENERAL_REQUEST', 'Yêu cầu khác', ServiceScope::Both],
        ['OTHER_REQUEST', 'Yêu cầu khác', 'GENERAL_REQUEST', 'Yêu cầu khác', ServiceScope::Both],
    ];

    public function run(): void
    {
        $categories = [];

        foreach (self::REQUESTS as $i => [$code, $name, $categoryCode, $categoryName, $scope]) {
            if (! isset($categories[$categoryCode])) {
                $categories[$categoryCode] = ServiceCategory::firstOrCreate(
                    ['code' => $categoryCode],
                    ['name' => $categoryName, 'sort_order' => count($categories) * 10 + 100, 'is_active' => true],
                );
            }

            Service::firstOrCreate(
                ['code' => $code],
                [
                    'category_id' => $categories[$categoryCode]->id,
                    'name' => $name,
                    'description' => null,
                    'is_chargeable' => false,
                    'scope' => $scope->value,
                    'billing_mode' => ServiceBillingMode::OneTime->value,
                    'quantity_enabled' => true,
                    'default_quantity' => 1,
                    'unit_label' => 'lần',
                    'fulfillment_required' => true,
                    'is_active' => true,
                    'is_bookable' => true,
                    'sort_order' => 200 + $i,
                ],
            );
        }
    }
}
