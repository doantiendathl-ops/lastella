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
        'stay.extend',
        'stay.room_move',
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
        'service_packages.manage',
        'services.manage',
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
        'special_request.create',
        'special_request.fulfill',
        'special_request.cancel',
        'housekeeping.view',
        'housekeeping.assign',
        'room.status.update',
        'room.cleaning.update',
        'room.inspect',
        'room.maintenance',
        'rooms.bulk_update',
        'product_services.manage',
        'checkout_inspection.view',
        'checkout_inspection.perform',
        'checkout_inspection.override',
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
            'stay.extend',
        'stay.room_move',
            'payment.create',
            'payment.delete',
            'folio.view',
            'folio.close',
            'charge.create',
            'charge.void',
            'report.view',
            'settings.manage',
            'service_rates.manage',
            'service_packages.manage',
            'services.manage',
            'night_audit.run',
            'night_audit.view',
            'booking.package.manage',
            'revenue.view',
            'reconciliation.view',
            'rooms.manage',
            'room_types.manage',
            'rates.manage',
            'special_request.create',
            'special_request.fulfill',
            'special_request.cancel',
            'housekeeping.view',
            'housekeeping.assign',
            'room.status.update',
            'room.cleaning.update',
            'room.inspect',
            'room.maintenance',
            'rooms.bulk_update',
            'product_services.manage',
            'checkout_inspection.view',
            'checkout_inspection.perform',
            'checkout_inspection.override',
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
            'stay.extend',
        'stay.room_move',
            'payment.create',
            'folio.view',
            'charge.create',
            'report.view',
            'special_request.create',
            'housekeeping.view',
            'room.cleaning.update',
            'checkout_inspection.view',
            'checkout_inspection.perform',
        ]);

        Role::findByName('HOUSEKEEPING')->syncPermissions([
            'housekeeping.view',
            'housekeeping.assign',
            'room.status.update',
            'room.cleaning.update',
            'special_request.fulfill',
            'checkout_inspection.view',
            'checkout_inspection.perform',
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
