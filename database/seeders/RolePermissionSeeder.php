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
        'room_availability.view',
        'stay.checkin',
        'stay.checkout',
        'payment.create',
        'payment.delete',
        'folio.view',
        'folio.close',
        'charge.create',
        'charge.void',
        'report.view',
        'settings.manage',
        'hotel_settings.manage',
        'service_rates.manage',
        'night_audit.run',
        'night_audit.view',
        'booking.package.manage',
        'revenue.view',
        'reconciliation.view',
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
            'room_availability.view',
            'stay.checkin',
            'stay.checkout',
            'payment.create',
            'payment.delete',
            'folio.view',
            'folio.close',
            'charge.create',
            'charge.void',
            'report.view',
            'settings.manage',
            'service_rates.manage',
            'night_audit.run',
            'night_audit.view',
            'booking.package.manage',
            'revenue.view',
            'reconciliation.view',
            'rooms.manage',
            'room_types.manage',
            'rates.manage',
        ]);

        Role::findByName('SALES')->syncPermissions([
            'booking.create',
            'booking.update',
            'booking.cancel',
            'room_availability.view',
            'report.view',
            'rates.manage',
        ]);

        Role::findByName('RECEPTION')->syncPermissions([
            'booking.create',
            'booking.update',
            'booking.cancel',
            'room.assign',
            'room.unassign',
            'room_availability.view',
            'stay.checkin',
            'stay.checkout',
            'payment.create',
            'folio.view',
            'charge.create',
            'report.view',
        ]);

        Role::findByName('HOUSEKEEPING')->syncPermissions([
            'room.assign',
            'room.unassign',
            'rooms.manage',
        ]);

        Role::findByName('ACCOUNTANT')->syncPermissions([
            'room_availability.view',
            'payment.create',
            'folio.view',
            'report.view',
            'revenue.view',
            'reconciliation.view',
            'night_audit.view',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
