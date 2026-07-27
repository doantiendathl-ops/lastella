<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $defaults = [
            ['code' => 'MINIBAR', 'name' => 'Minibar', 'sort_order' => 10],
            ['code' => 'ROOM_AMENITY', 'name' => 'Đồ dùng phòng', 'sort_order' => 20],
            ['code' => 'FOOD_BEVERAGE', 'name' => 'Ăn uống', 'sort_order' => 30],
            ['code' => 'LAUNDRY', 'name' => 'Giặt là', 'sort_order' => 40],
            ['code' => 'TRANSPORT', 'name' => 'Đưa đón', 'sort_order' => 50],
            ['code' => 'SURCHARGE', 'name' => 'Phụ thu', 'sort_order' => 60],
            ['code' => 'DAMAGE', 'name' => 'Bồi thường', 'sort_order' => 70],
            ['code' => 'OTHER', 'name' => 'Dịch vụ khác', 'sort_order' => 80],
        ];

        foreach ($defaults as $category) {
            $exists = DB::table('product_service_categories')->where('code', $category['code'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('product_service_categories')->insert([
                'code' => $category['code'],
                'name' => $category['name'],
                'sort_order' => $category['sort_order'],
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
