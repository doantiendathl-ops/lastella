<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomRateSeeder::class,
            RoomSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
