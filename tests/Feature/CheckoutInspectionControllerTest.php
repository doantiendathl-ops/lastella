<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Booking;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutInspectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $reception;
    private User $accountant;
    private RoomType $twinType;
    private ProductService $water;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->travelTo('2026-07-12 14:00:00');

        $this->reception = User::factory()->create();
        $this->reception->assignRole('RECEPTION');

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('ACCOUNTANT');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();

        $category = ProductServiceCategory::create(['code' => 'MINIBAR', 'name' => 'Minibar', 'sort_order' => 1]);
        $this->water = ProductService::create([
            'category_id' => $category->id,
            'code' => 'MB_WATER',
            'name' => 'Nước suối',
            'type' => 'product',
            'unit' => 'chai',
            'price' => 15000,
            'free_quantity_default' => 2,
            'use_in_checkout_inspection' => true,
            'is_active' => true,
        ]);
    }

    public function test_reception_can_view_checkout_inspection_board(): void
    {
        $stay = $this->checkedInStay();

        $this->actingAs($this->reception)
            ->get(route('admin.checkout-inspections.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/CheckoutInspections/Index')
                ->has('floors')
                ->has('products')
            );

        $this->assertNotNull($stay);
    }

    public function test_accountant_without_permission_is_forbidden(): void
    {
        $this->actingAs($this->accountant)
            ->get(route('admin.checkout-inspections.index'))
            ->assertForbidden();
    }

    public function test_reception_can_open_draft_and_complete_inspection_via_http(): void
    {
        $stay = $this->checkedInStay();

        $draftResponse = $this->actingAs($this->reception)
            ->post(route('admin.checkout-inspections.draft', $stay))
            ->assertOk();

        $inspectionId = $draftResponse->json('id');

        $this->actingAs($this->reception)
            ->post(route('admin.checkout-inspections.complete', $inspectionId), [
                'items' => [
                    ['product_service_id' => $this->water->id, 'actual_quantity' => 4],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('total_amount', 30000);

        $this->assertDatabaseHas('folio_entries', [
            'posting_source' => 'CHECKOUT_INSPECTION',
            'amount' => '30000.00',
        ]);
    }

    private function checkedInStay(): Stay
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'HTTP Test Guest',
            'customer_phone' => '0900000007',
            'customer_email' => 'http-test@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->reception->id,
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

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return $stay->fresh();
    }
}
