<?php

namespace Tests\Unit\Seeders;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4.2 Milestone 3 (ADR-89): HOUSEKEEPING permission revision.
 */
class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_housekeeping_lost_rooms_manage(): void
    {
        $this->assertFalse(Role::findByName('HOUSEKEEPING')->hasPermissionTo('rooms.manage'));
    }

    public function test_housekeeping_lost_room_assign(): void
    {
        $this->assertFalse(Role::findByName('HOUSEKEEPING')->hasPermissionTo('room.assign'));
    }

    public function test_housekeeping_lost_room_unassign(): void
    {
        $this->assertFalse(Role::findByName('HOUSEKEEPING')->hasPermissionTo('room.unassign'));
    }

    public function test_housekeeping_kept_special_request_fulfill(): void
    {
        $this->assertTrue(Role::findByName('HOUSEKEEPING')->hasPermissionTo('special_request.fulfill'));
    }

    public function test_housekeeping_gained_the_three_new_permissions(): void
    {
        $housekeeping = Role::findByName('HOUSEKEEPING');

        $this->assertTrue($housekeeping->hasPermissionTo('housekeeping.view'));
        $this->assertTrue($housekeeping->hasPermissionTo('housekeeping.assign'));
        $this->assertTrue($housekeeping->hasPermissionTo('room.status.update'));
    }

    public function test_housekeeping_does_not_have_inspect_or_maintenance(): void
    {
        $housekeeping = Role::findByName('HOUSEKEEPING');

        $this->assertFalse($housekeeping->hasPermissionTo('room.inspect'));
        $this->assertFalse($housekeeping->hasPermissionTo('room.maintenance'));
    }

    public function test_reception_gained_housekeeping_view_only(): void
    {
        $reception = Role::findByName('RECEPTION');

        $this->assertTrue($reception->hasPermissionTo('housekeeping.view'));
        $this->assertFalse($reception->hasPermissionTo('housekeeping.assign'));
        $this->assertFalse($reception->hasPermissionTo('room.status.update'));
        $this->assertFalse($reception->hasPermissionTo('room.inspect'));
        $this->assertFalse($reception->hasPermissionTo('room.maintenance'));
    }

    public function test_admin_has_all_five_new_permissions(): void
    {
        $admin = Role::findByName('ADMIN');

        $this->assertTrue($admin->hasPermissionTo('housekeeping.view'));
        $this->assertTrue($admin->hasPermissionTo('housekeeping.assign'));
        $this->assertTrue($admin->hasPermissionTo('room.status.update'));
        $this->assertTrue($admin->hasPermissionTo('room.inspect'));
        $this->assertTrue($admin->hasPermissionTo('room.maintenance'));
    }

    public function test_manager_has_all_five_new_permissions(): void
    {
        $manager = Role::findByName('MANAGER');

        $this->assertTrue($manager->hasPermissionTo('housekeeping.view'));
        $this->assertTrue($manager->hasPermissionTo('housekeeping.assign'));
        $this->assertTrue($manager->hasPermissionTo('room.status.update'));
        $this->assertTrue($manager->hasPermissionTo('room.inspect'));
        $this->assertTrue($manager->hasPermissionTo('room.maintenance'));
    }

    public function test_accountant_has_none_of_the_five_new_permissions(): void
    {
        $accountant = Role::findByName('ACCOUNTANT');

        $this->assertFalse($accountant->hasPermissionTo('housekeeping.view'));
        $this->assertFalse($accountant->hasPermissionTo('housekeeping.assign'));
        $this->assertFalse($accountant->hasPermissionTo('room.status.update'));
        $this->assertFalse($accountant->hasPermissionTo('room.inspect'));
        $this->assertFalse($accountant->hasPermissionTo('room.maintenance'));
    }

    public function test_sales_has_none_of_the_five_new_permissions(): void
    {
        $sales = Role::findByName('SALES');

        $this->assertFalse($sales->hasPermissionTo('housekeeping.view'));
        $this->assertFalse($sales->hasPermissionTo('housekeeping.assign'));
        $this->assertFalse($sales->hasPermissionTo('room.status.update'));
        $this->assertFalse($sales->hasPermissionTo('room.inspect'));
        $this->assertFalse($sales->hasPermissionTo('room.maintenance'));
    }

    /**
     * Room Operations Simplification: room.cleaning.update is a new, separate ability
     * from room.status.update — granted to ADMIN/MANAGER/RECEPTION/HOUSEKEEPING, not
     * SALES/ACCOUNTANT.
     */
    public function test_room_cleaning_update_granted_to_the_four_operational_roles(): void
    {
        $this->assertTrue(Role::findByName('ADMIN')->hasPermissionTo('room.cleaning.update'));
        $this->assertTrue(Role::findByName('MANAGER')->hasPermissionTo('room.cleaning.update'));
        $this->assertTrue(Role::findByName('RECEPTION')->hasPermissionTo('room.cleaning.update'));
        $this->assertTrue(Role::findByName('HOUSEKEEPING')->hasPermissionTo('room.cleaning.update'));
    }

    public function test_room_cleaning_update_not_granted_to_sales_or_accountant(): void
    {
        $this->assertFalse(Role::findByName('SALES')->hasPermissionTo('room.cleaning.update'));
        $this->assertFalse(Role::findByName('ACCOUNTANT')->hasPermissionTo('room.cleaning.update'));
    }

    public function test_reception_kept_pre_existing_phase3_permissions(): void
    {
        $reception = Role::findByName('RECEPTION');

        $this->assertTrue($reception->hasPermissionTo('room.assign'));
        $this->assertTrue($reception->hasPermissionTo('room.unassign'));
        $this->assertTrue($reception->hasPermissionTo('stay.checkin'));
        $this->assertTrue($reception->hasPermissionTo('stay.checkout'));
    }

    public function test_manager_kept_pre_existing_rooms_manage(): void
    {
        $this->assertTrue(Role::findByName('MANAGER')->hasPermissionTo('rooms.manage'));
    }

    /**
     * Idempotency: re-running the seeder must not throw and must converge
     * to the same permission set (no duplicate rows, no stale grants).
     */
    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $housekeeping = Role::findByName('HOUSEKEEPING');

        // Was 6 before Room Operations Simplification added room.cleaning.update to HOUSEKEEPING.
        $this->assertCount(7, $housekeeping->permissions);
        $this->assertFalse($housekeeping->hasPermissionTo('rooms.manage'));
        $this->assertTrue($housekeeping->hasPermissionTo('housekeeping.view'));
    }

    /**
     * M3-03 (ChatGPT M3 review, hardened in M4): running the seeder 3 times must not
     * create duplicate (role_id, permission_id) pivot rows in role_has_permissions.
     */
    public function test_seeder_does_not_create_duplicate_pivot_rows(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $countAfterFirstReseed = DB::table('role_has_permissions')->count();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $countAfterThreeReseeds = DB::table('role_has_permissions')->count();

        $this->assertSame($countAfterFirstReseed, $countAfterThreeReseeds);

        $distinctPairs = DB::table('role_has_permissions')
            ->select('role_id', 'permission_id')
            ->distinct()
            ->count();

        $this->assertSame($countAfterThreeReseeds, $distinctPairs, 'Found duplicate (role_id, permission_id) pivot rows.');
    }
}
