<?php

namespace Tests\Unit\Seeders;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4.3A Milestone 2: `stay.extend` permission grants.
 */
class StayExtendPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_has_stay_extend(): void
    {
        $this->assertTrue(Role::findByName('ADMIN')->hasPermissionTo('stay.extend'));
    }

    public function test_manager_has_stay_extend(): void
    {
        $this->assertTrue(Role::findByName('MANAGER')->hasPermissionTo('stay.extend'));
    }

    public function test_reception_has_stay_extend(): void
    {
        $this->assertTrue(Role::findByName('RECEPTION')->hasPermissionTo('stay.extend'));
    }

    public function test_sales_does_not_have_stay_extend(): void
    {
        $this->assertFalse(Role::findByName('SALES')->hasPermissionTo('stay.extend'));
    }

    public function test_housekeeping_does_not_have_stay_extend(): void
    {
        $this->assertFalse(Role::findByName('HOUSEKEEPING')->hasPermissionTo('stay.extend'));
    }

    public function test_accountant_does_not_have_stay_extend(): void
    {
        $this->assertFalse(Role::findByName('ACCOUNTANT')->hasPermissionTo('stay.extend'));
    }

    public function test_seeder_is_idempotent_for_stay_extend(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Role::findByName('RECEPTION')->hasPermissionTo('stay.extend'));

        $permission = DB::table('permissions')->where('name', 'stay.extend')->first();
        $pivotCount = DB::table('role_has_permissions')->where('permission_id', $permission->id)->count();

        // Exactly 3 roles (ADMIN, MANAGER, RECEPTION) should hold this permission, no duplicates.
        $this->assertSame(3, $pivotCount);
    }
}
