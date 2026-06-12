<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_dynamic_setting(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');

        $this->actingAs($admin)
            ->post('/settings', [
                'key' => 'front_desk_extension',
                'value' => '100',
                'type' => 'string',
                'group' => 'company',
                'is_public' => true,
            ])
            ->assertRedirect('/settings');

        $this->assertDatabaseHas('settings', [
            'key' => 'front_desk_extension',
            'type' => 'string',
            'group' => 'company',
            'is_public' => true,
        ]);
    }
}
