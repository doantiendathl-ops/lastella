<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductServiceSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $categoryIds = DB::table('product_service_categories')->pluck('id', 'code');

        $defaults = [
            ['code' => 'MB_WATER_500', 'name' => 'Nước suối 500ml', 'category' => 'MINIBAR', 'unit' => 'chai', 'price' => '15000.00', 'free_qty' => 2, 'inspection' => true, 'booking' => true, 'sort_order' => 10],
            ['code' => 'MB_BEER_CAN', 'name' => 'Bia lon', 'category' => 'MINIBAR', 'unit' => 'lon', 'price' => '30000.00', 'free_qty' => 0, 'inspection' => true, 'booking' => true, 'sort_order' => 20],
            ['code' => 'MB_SNACK', 'name' => 'Snack', 'category' => 'MINIBAR', 'unit' => 'gói', 'price' => '25000.00', 'free_qty' => 0, 'inspection' => true, 'booking' => true, 'sort_order' => 30],
            ['code' => 'AM_TOWEL', 'name' => 'Khăn tắm', 'category' => 'ROOM_AMENITY', 'unit' => 'cái', 'price' => '100000.00', 'free_qty' => 2, 'inspection' => true, 'booking' => false, 'sort_order' => 40],
            ['code' => 'AM_BATHROBE', 'name' => 'Áo choàng tắm', 'category' => 'ROOM_AMENITY', 'unit' => 'cái', 'price' => '250000.00', 'free_qty' => 1, 'inspection' => true, 'booking' => false, 'sort_order' => 50],
            ['code' => 'FB_BREAKFAST', 'name' => 'Bữa sáng thêm', 'category' => 'FOOD_BEVERAGE', 'unit' => 'suất', 'price' => '120000.00', 'free_qty' => 0, 'inspection' => false, 'booking' => true, 'sort_order' => 60],
            ['code' => 'LD_SHIRT', 'name' => 'Giặt ủi áo', 'category' => 'LAUNDRY', 'unit' => 'cái', 'price' => '30000.00', 'free_qty' => 0, 'inspection' => false, 'booking' => true, 'sort_order' => 70],
            ['code' => 'TR_AIRPORT', 'name' => 'Đưa đón sân bay', 'category' => 'TRANSPORT', 'unit' => 'lượt', 'price' => '300000.00', 'free_qty' => 0, 'inspection' => false, 'booking' => true, 'sort_order' => 80],
            ['code' => 'DM_REMOTE_LOST', 'name' => 'Mất điều khiển TV', 'category' => 'DAMAGE', 'unit' => 'cái', 'price' => '200000.00', 'free_qty' => 0, 'inspection' => true, 'booking' => false, 'sort_order' => 90],
        ];

        foreach ($defaults as $item) {
            $exists = DB::table('product_services')->where('code', $item['code'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('product_services')->insert([
                'category_id' => $categoryIds[$item['category']] ?? null,
                'code' => $item['code'],
                'name' => $item['name'],
                'type' => 'product',
                'unit' => $item['unit'],
                'price' => $item['price'],
                'free_quantity_default' => $item['free_qty'],
                'use_in_checkout_inspection' => $item['inspection'],
                'can_add_to_booking' => $item['booking'],
                'is_active' => 1,
                'sort_order' => $item['sort_order'],
                'description' => null,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
