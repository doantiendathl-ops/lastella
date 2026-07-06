<?php

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\HousekeepingPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HousekeepingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private HousekeepingPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->policy = new HousekeepingPolicy();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user;
    }

    // -------------------------------------------------------------------------
    // ADMIN matrix — all true
    // -------------------------------------------------------------------------

    public function test_admin_can_view(): void
    {
        $this->assertTrue($this->policy->view($this->userWithRole('ADMIN')));
    }

    public function test_admin_can_assign(): void
    {
        $this->assertTrue($this->policy->assign($this->userWithRole('ADMIN')));
    }

    public function test_admin_can_update_status(): void
    {
        $this->assertTrue($this->policy->updateStatus($this->userWithRole('ADMIN')));
    }

    public function test_admin_can_inspect(): void
    {
        $this->assertTrue($this->policy->inspect($this->userWithRole('ADMIN')));
    }

    public function test_admin_can_maintenance(): void
    {
        $this->assertTrue($this->policy->maintenance($this->userWithRole('ADMIN')));
    }

    // -------------------------------------------------------------------------
    // MANAGER matrix — all true
    // -------------------------------------------------------------------------

    public function test_manager_can_view(): void
    {
        $this->assertTrue($this->policy->view($this->userWithRole('MANAGER')));
    }

    public function test_manager_can_assign(): void
    {
        $this->assertTrue($this->policy->assign($this->userWithRole('MANAGER')));
    }

    public function test_manager_can_update_status(): void
    {
        $this->assertTrue($this->policy->updateStatus($this->userWithRole('MANAGER')));
    }

    public function test_manager_can_inspect(): void
    {
        $this->assertTrue($this->policy->inspect($this->userWithRole('MANAGER')));
    }

    public function test_manager_can_maintenance(): void
    {
        $this->assertTrue($this->policy->maintenance($this->userWithRole('MANAGER')));
    }

    // -------------------------------------------------------------------------
    // HOUSEKEEPING matrix — view/assign/updateStatus true; inspect/maintenance false
    // -------------------------------------------------------------------------

    public function test_housekeeping_can_view(): void
    {
        $this->assertTrue($this->policy->view($this->userWithRole('HOUSEKEEPING')));
    }

    public function test_housekeeping_can_assign(): void
    {
        $this->assertTrue($this->policy->assign($this->userWithRole('HOUSEKEEPING')));
    }

    public function test_housekeeping_can_update_status(): void
    {
        $this->assertTrue($this->policy->updateStatus($this->userWithRole('HOUSEKEEPING')));
    }

    public function test_housekeeping_cannot_inspect(): void
    {
        $this->assertFalse($this->policy->inspect($this->userWithRole('HOUSEKEEPING')));
    }

    public function test_housekeeping_cannot_maintenance(): void
    {
        $this->assertFalse($this->policy->maintenance($this->userWithRole('HOUSEKEEPING')));
    }

    // -------------------------------------------------------------------------
    // RECEPTION matrix — only view true
    // -------------------------------------------------------------------------

    public function test_reception_can_view(): void
    {
        $this->assertTrue($this->policy->view($this->userWithRole('RECEPTION')));
    }

    public function test_reception_cannot_assign(): void
    {
        $this->assertFalse($this->policy->assign($this->userWithRole('RECEPTION')));
    }

    public function test_reception_cannot_update_status(): void
    {
        $this->assertFalse($this->policy->updateStatus($this->userWithRole('RECEPTION')));
    }

    public function test_reception_cannot_inspect(): void
    {
        $this->assertFalse($this->policy->inspect($this->userWithRole('RECEPTION')));
    }

    public function test_reception_cannot_maintenance(): void
    {
        $this->assertFalse($this->policy->maintenance($this->userWithRole('RECEPTION')));
    }

    // -------------------------------------------------------------------------
    // ACCOUNTANT matrix — all false
    // -------------------------------------------------------------------------

    public function test_accountant_cannot_view(): void
    {
        $this->assertFalse($this->policy->view($this->userWithRole('ACCOUNTANT')));
    }

    public function test_accountant_cannot_assign(): void
    {
        $this->assertFalse($this->policy->assign($this->userWithRole('ACCOUNTANT')));
    }

    public function test_accountant_cannot_update_status(): void
    {
        $this->assertFalse($this->policy->updateStatus($this->userWithRole('ACCOUNTANT')));
    }

    public function test_accountant_cannot_inspect(): void
    {
        $this->assertFalse($this->policy->inspect($this->userWithRole('ACCOUNTANT')));
    }

    public function test_accountant_cannot_maintenance(): void
    {
        $this->assertFalse($this->policy->maintenance($this->userWithRole('ACCOUNTANT')));
    }

    // -------------------------------------------------------------------------
    // SALES matrix — all false
    // -------------------------------------------------------------------------

    public function test_sales_cannot_view(): void
    {
        $this->assertFalse($this->policy->view($this->userWithRole('SALES')));
    }

    public function test_sales_cannot_assign(): void
    {
        $this->assertFalse($this->policy->assign($this->userWithRole('SALES')));
    }

    public function test_sales_cannot_update_status(): void
    {
        $this->assertFalse($this->policy->updateStatus($this->userWithRole('SALES')));
    }

    public function test_sales_cannot_inspect(): void
    {
        $this->assertFalse($this->policy->inspect($this->userWithRole('SALES')));
    }

    public function test_sales_cannot_maintenance(): void
    {
        $this->assertFalse($this->policy->maintenance($this->userWithRole('SALES')));
    }
}
