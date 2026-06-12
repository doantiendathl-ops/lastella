<?php

namespace Tests\Unit\Seeders;

use App\Models\Floor;
use App\Models\Resource;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CoreSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_seeders_create_requested_reference_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(11, Floor::count());
        $this->assertSame(5, RoomType::count());
        $this->assertSame(59, Room::count());
        $this->assertSame(59, Resource::where('type', 'room')->count());
        $this->assertSame(7, Setting::count());

        foreach (RolePermissionSeeder::ROLES as $role) {
            $this->assertTrue(Role::where('name', $role)->exists(), "Missing role {$role}");
        }

        foreach (RolePermissionSeeder::PERMISSIONS as $permission) {
            $this->assertTrue(Permission::where('name', $permission)->exists(), "Missing permission {$permission}");
        }

        $this->assertTrue(Role::findByName('ADMIN')->hasPermissionTo('settings.manage'));
    }
}
