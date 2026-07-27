<?php

namespace Tests\Feature;

use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductServiceCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->reception = User::factory()->create();
        $this->reception->assignRole('RECEPTION');
    }

    public function test_admin_can_view_product_services_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('product-services.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ProductServices/Index')
                ->has('items')
                ->has('categories')
            );
    }

    public function test_reception_cannot_view_product_services_index(): void
    {
        $this->actingAs($this->reception)
            ->get(route('product-services.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_product_service(): void
    {
        $category = ProductServiceCategory::create(['code' => 'MINIBAR', 'name' => 'Minibar', 'sort_order' => 1]);

        $this->actingAs($this->admin)
            ->post(route('product-services.store'), [
                'category_id' => $category->id,
                'code' => 'MB_COKE',
                'name' => 'Coca Cola',
                'type' => 'product',
                'unit' => 'lon',
                'price' => 20000,
                'free_quantity_default' => 1,
                'use_in_checkout_inspection' => true,
                'can_add_to_booking' => true,
            ])
            ->assertRedirect(route('product-services.index'));

        $this->assertDatabaseHas('product_services', [
            'code' => 'MB_COKE',
            'name' => 'Coca Cola',
            'price' => '20000.00',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_reception_cannot_create_product_service(): void
    {
        $this->actingAs($this->reception)
            ->post(route('product-services.store'), [
                'code' => 'X',
                'name' => 'X',
                'type' => 'product',
                'unit' => 'cái',
                'price' => 1000,
            ])
            ->assertForbidden();
    }

    public function test_updating_price_does_not_change_historical_snapshot(): void
    {
        // ADR: price changes on the catalog must never rewrite historical charge amounts,
        // which are frozen as snapshots on the consuming record (see CheckoutInspectionItem).
        $item = ProductService::create([
            'code' => 'MB_WATER',
            'name' => 'Nước suối',
            'type' => 'product',
            'unit' => 'chai',
            'price' => 15000,
        ]);

        $frozenPrice = (float) $item->price;

        $this->actingAs($this->admin)
            ->patch(route('product-services.update', $item), [
                'code' => 'MB_WATER',
                'name' => 'Nước suối',
                'type' => 'product',
                'unit' => 'chai',
                'price' => 25000,
            ])
            ->assertRedirect(route('product-services.index'));

        $this->assertSame(15000.0, $frozenPrice);
        $this->assertDatabaseHas('product_services', ['id' => $item->id, 'price' => '25000.00']);
    }

    public function test_toggle_active_flips_status(): void
    {
        $item = ProductService::create([
            'code' => 'MB_X',
            'name' => 'X',
            'type' => 'product',
            'unit' => 'cái',
            'price' => 1000,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('product-services.toggle', $item))
            ->assertRedirect();

        $this->assertDatabaseHas('product_services', ['id' => $item->id, 'is_active' => false]);
    }

    public function test_inactive_item_is_excluded_from_active_scope(): void
    {
        ProductService::create([
            'code' => 'MB_ACTIVE',
            'name' => 'Active',
            'type' => 'product',
            'unit' => 'cái',
            'price' => 1000,
            'is_active' => true,
            'use_in_checkout_inspection' => true,
        ]);

        ProductService::create([
            'code' => 'MB_INACTIVE',
            'name' => 'Inactive',
            'type' => 'product',
            'unit' => 'cái',
            'price' => 1000,
            'is_active' => false,
            'use_in_checkout_inspection' => true,
        ]);

        $active = ProductService::usableInCheckoutInspection()->pluck('code')->all();

        $this->assertContains('MB_ACTIVE', $active);
        $this->assertNotContains('MB_INACTIVE', $active);
    }

    public function test_category_with_products_cannot_be_deleted(): void
    {
        $category = ProductServiceCategory::create(['code' => 'MINIBAR', 'name' => 'Minibar', 'sort_order' => 1]);
        ProductService::create([
            'category_id' => $category->id,
            'code' => 'MB_Y',
            'name' => 'Y',
            'type' => 'product',
            'unit' => 'cái',
            'price' => 1000,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('product-service-categories.destroy', $category))
            ->assertForbidden();

        $this->assertDatabaseHas('product_service_categories', ['id' => $category->id]);
    }
}
