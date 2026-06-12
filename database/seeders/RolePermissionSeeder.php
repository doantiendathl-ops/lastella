<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public const ROLES = [
        'ADMIN',
        'MANAGER',
        'SALES',
        'RECEPTION',
        'HOUSEKEEPING',
        'ACCOUNTANT',
    ];

    public const PERMISSIONS = [
        'booking.create',
        'booking.update',
        'booking.cancel',
        'room.assign',
        'room.unassign',
        'stay.checkin',
        'stay.checkout',
        'payment.create',
        'report.view',
        'settings.manage',
        'users.manage',
        'roles.manage',
        'rooms.manage',
        'room_types.manage',
        'rates.manage',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        Role::findByName('ADMIN')->syncPermissions(self::PERMISSIONS);

        Role::findByName('MANAGER')->syncPermissions([
            'booking.create',
            'booking.update',
            'booking.cancel',
            'room.assign',
            'room.unassign',
            'stay.checkin',
            'stay.checkout',
            'payment.create',
            'report.view',
            'settings.manage',
            'rooms.manage',
            'room_types.manage',
            'rates.manage',
        ]);

        Role::findByName('SALES')->syncPermissions([
            'booking.create',
            'booking.update',
            'booking.cancel',
            'report.view',
            'rates.manage',
        ]);

        Role::findByName('RECEPTION')->syncPermissions([
            'booking.create',
            'booking.update',
            'booking.cancel',
            'room.assign',
            'room.unassign',
            'stay.checkin',
            'stay.checkout',
            'payment.create',
            'report.view',
        ]);

        Role::findByName('HOUSEKEEPING')->syncPermissions([
            'room.assign',
            'room.unassign',
            'rooms.manage',
        ]);

        Role::findByName('ACCOUNTANT')->syncPermissions([
            'payment.create',
            'report.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
