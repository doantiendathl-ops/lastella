<?php

namespace Tests\Unit\Policies;

use App\Models\Room;
use App\Models\User;
use App\Policies\RoomPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_with_rooms_manage_can_manage_rooms(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $housekeeping = User::factory()->create();
        $housekeeping->assignRole('HOUSEKEEPING');

        $policy = new RoomPolicy();

        $this->assertTrue($policy->viewAny($housekeeping));
        $this->assertTrue($policy->create($housekeeping));
    }

    public function test_sales_role_cannot_manage_rooms(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $sales = User::factory()->create();
        $sales->assignRole('SALES');

        $policy = new RoomPolicy();

        $this->assertFalse($policy->viewAny($sales));
        $this->assertFalse($policy->create($sales));
    }
}
