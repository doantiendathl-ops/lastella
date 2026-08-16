<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 3/26) — category
 * catalog is database/UI-managed, never hard-coded. Smoke test over the
 * reused generic CrudIndex/CrudForm infrastructure.
 */
class ServiceCategoryAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
    }

    public function test_admin_can_create_update_and_delete_a_category(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/service-categories', ['code' => 'CAT_1', 'name' => 'Danh mục 1', 'sort_order' => 10, 'is_active' => true])
            ->assertRedirect('/admin/service-categories');

        $category = ServiceCategory::where('code', 'CAT_1')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/admin/service-categories/{$category->id}", ['code' => 'CAT_1', 'name' => 'Danh mục đã sửa', 'sort_order' => 20, 'is_active' => true])
            ->assertRedirect('/admin/service-categories');

        $this->assertSame('Danh mục đã sửa', $category->fresh()->name);

        $this->actingAs($this->admin)
            ->delete("/admin/service-categories/{$category->id}")
            ->assertRedirect('/admin/service-categories');

        $this->assertDatabaseMissing('service_categories', ['id' => $category->id]);
    }

    public function test_category_with_a_service_cannot_be_deleted(): void
    {
        $category = ServiceCategory::create(['code' => 'CAT_2', 'name' => 'Danh mục 2', 'is_active' => true]);
        \App\Models\Service::create([
            'category_id' => $category->id,
            'code' => 'SVC_CAT2',
            'name' => 'Test',
            'scope' => 'BOOKING',
            'billing_mode' => 'ONE_TIME',
            'unit_label' => 'lần',
        ]);

        $this->actingAs($this->admin)
            ->delete("/admin/service-categories/{$category->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('service_categories', ['id' => $category->id]);
    }
}
