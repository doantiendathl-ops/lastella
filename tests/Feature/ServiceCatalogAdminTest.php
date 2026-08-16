<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 2/3/26) —
 * permission gating and the locked-identity-fields-after-use guard,
 * mirroring ServicePackageAdminTest's coverage shape.
 */
class ServiceCatalogAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reception;
    private ServiceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
        $this->reception = tap(User::factory()->create())->assignRole('RECEPTION');
        $this->category = ServiceCategory::create(['code' => 'CAT_ADMIN_TEST', 'name' => 'Test', 'is_active' => true]);
    }

    private function baseServicePayload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'code' => 'ADMIN_TEST_SVC',
            'name' => 'Test Service',
            'is_chargeable' => true,
            'scope' => 'ROOM',
            'billing_mode' => 'PER_NIGHT',
            'quantity_enabled' => true,
            'default_quantity' => 1,
            'unit_label' => 'giường',
            'fulfillment_required' => true,
            'is_active' => true,
            'is_bookable' => true,
        ], $overrides);
    }

    public function test_admin_can_view_services_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.services.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Services/Index')
                ->has('services')
                ->has('categories')
                ->has('scopes')
                ->has('billingModes')
            );
    }

    public function test_reception_cannot_view_services_index(): void
    {
        $this->actingAs($this->reception)
            ->get(route('admin.services.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.services.index'))->assertRedirect('/login');
    }

    public function test_admin_can_create_a_service(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.services.store'), $this->baseServicePayload())
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', ['code' => 'ADMIN_TEST_SVC', 'scope' => 'ROOM']);
    }

    public function test_scope_is_locked_after_the_service_has_a_price(): void
    {
        $service = Service::create($this->baseServicePayload());
        $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $payload = $this->baseServicePayload(['scope' => 'BOOKING']);

        $this->actingAs($this->admin)
            ->patch(route('admin.services.update', $service), $payload)
            ->assertSessionHasErrors('scope');

        $this->assertSame('ROOM', $service->fresh()->scope->value);
    }

    public function test_scope_can_still_change_before_the_service_has_any_price(): void
    {
        $service = Service::create($this->baseServicePayload());

        $payload = $this->baseServicePayload(['scope' => 'BOOKING']);

        $this->actingAs($this->admin)
            ->patch(route('admin.services.update', $service), $payload)
            ->assertRedirect(route('admin.services.index'));

        $this->assertSame('BOOKING', $service->fresh()->scope->value);
    }

    public function test_name_can_still_change_after_the_service_has_a_price(): void
    {
        $service = Service::create($this->baseServicePayload());
        $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $payload = $this->baseServicePayload(['name' => 'Renamed Service']);

        $this->actingAs($this->admin)
            ->patch(route('admin.services.update', $service), $payload)
            ->assertRedirect(route('admin.services.index'));

        $this->assertSame('Renamed Service', $service->fresh()->name);
    }

    public function test_admin_can_add_a_new_price_and_old_price_row_is_kept(): void
    {
        $service = Service::create($this->baseServicePayload());
        $old = $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-01-01', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->post(route('admin.services.prices.store', $service), ['unit_price' => 180000, 'effective_from' => '2026-09-01'])
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('service_prices', ['id' => $old->id, 'unit_price' => 100000]);
        $this->assertDatabaseHas('service_prices', ['service_id' => $service->id, 'unit_price' => 180000]);
    }

    public function test_admin_can_toggle_is_active(): void
    {
        $service = Service::create($this->baseServicePayload());

        $this->actingAs($this->admin)
            ->patch(route('admin.services.toggle', $service), ['field' => 'is_active'])
            ->assertRedirect(route('admin.services.index'));

        $this->assertFalse($service->fresh()->is_active);
    }
}
