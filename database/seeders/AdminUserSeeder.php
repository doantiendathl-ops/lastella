<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@lastella.local'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $admin->syncRoles(['ADMIN', 'MANAGER']);

        $manager = User::updateOrCreate(
            ['email' => 'manager@lastella.local'],
            [
                'name' => 'Hotel Manager',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $manager->syncRoles(['MANAGER', 'SALES']);
    }
}
