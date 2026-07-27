<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Booking;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingProductServiceChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reception;
    private RoomType $twinType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
        ]);

        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
        $this->reception = tap(User::factory()->create())->assignRole('RECEPTION');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_booking_detail_only_exposes_active_bookable_products(): void
    {
        $category = ProductServiceCategory::create(['code' => 'MINIBAR', 'name' => 'Minibar', 'sort_order' => 1]);

        $bookable = ProductService::create([
            'category_id' => $category->id, 'code' => 'BOOKABLE', 'name' => 'Bookable Item',
            'type' => 'product', 'unit' => 'cái', 'price' => 20000,
            'can_add_to_booking' => true, 'is_active' => true,
        ]);
        ProductService::create([
            'category_id' => $category->id, 'code' => 'NOT_BOOKABLE', 'name' => 'Inspection Only',
            'type' => 'product', 'unit' => 'cái', 'price' => 20000,
            'can_add_to_booking' => false, 'use_in_checkout_inspection' => true, 'is_active' => true,
        ]);
        ProductService::create([
            'category_id' => $category->id, 'code' => 'INACTIVE', 'name' => 'Inactive Item',
            'type' => 'product', 'unit' => 'cái', 'price' => 20000,
            'can_add_to_booking' => true, 'is_active' => false,
        ]);

        $booking = $this->createBooking();

        $response = $this->actingAs($this->reception)
            ->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Bookings/Show'));

        $names = collect($response->viewData('page')['props']['productServices'])->pluck('name')->all();

        $this->assertContains('Bookable Item', $names);
        $this->assertNotContains('Inspection Only', $names);
        $this->assertNotContains('Inactive Item', $names);
    }

    public function test_reception_without_pricing_permission_does_not_get_override_flag(): void
    {
        $booking = $this->createBooking();

        $response = $this->actingAs($this->reception)->get(route('admin.bookings.show', $booking))->assertOk();

        $this->assertFalse($response->viewData('page')['props']['can']['overrideProductPrice']);
    }

    public function test_admin_gets_override_price_flag(): void
    {
        $booking = $this->createBooking();

        $response = $this->actingAs($this->admin)->get(route('admin.bookings.show', $booking))->assertOk();

        $this->assertTrue($response->viewData('page')['props']['can']['overrideProductPrice']);
    }

    public function test_submitting_a_product_derived_charge_uses_existing_folio_pipeline(): void
    {
        $category = ProductServiceCategory::create(['code' => 'MINIBAR', 'name' => 'Minibar', 'sort_order' => 1]);
        $product = ProductService::create([
            'category_id' => $category->id, 'code' => 'MB_COKE', 'name' => 'Coca Cola',
            'type' => 'product', 'unit' => 'lon', 'price' => 20000,
            'can_add_to_booking' => true, 'is_active' => true,
        ]);

        $booking = $this->createBooking();

        // Frontend pre-fills these from the selected product; submission still goes through
        // the unchanged FolioEntryController::store -> FolioService::addCharge pipeline.
        $this->actingAs($this->reception)
            ->post(route('admin.bookings.folio.entries.store', $booking), [
                'charge_type' => $product->resolveChargeType()->value,
                'description' => $product->name,
                'quantity' => 2,
                'unit_price' => (float) $product->price,
                'entry_date' => now()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('folio_entries', [
            'description' => 'Coca Cola',
            'quantity' => '2.00',
            'unit_price' => '20000.00',
            'amount' => '40000.00',
        ]);
    }

    private function createBooking(): Booking
    {
        return app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Product Charge Guest',
            'customer_phone' => '0900000008',
            'customer_email' => 'product-charge@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => now()->toDateTimeString(),
            'checkout_at' => now()->addDay()->toDateTimeString(),
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'requirements' => [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 1,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => 800000,
                    'price_source' => 'MANUAL',
                ],
            ],
        ]);
    }
}
