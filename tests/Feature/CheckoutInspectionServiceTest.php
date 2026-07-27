<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CheckoutInspectionStatus;
use App\Enums\CustomerType;
use App\Enums\StayEventType;
use App\Models\Booking;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use App\Services\CheckoutInspectionService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckoutInspectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
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

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

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
            'can_add_to_booking' => true,
            'is_active' => true,
        ]);
    }

    public function test_get_or_create_draft_is_idempotent_per_stay(): void
    {
        $stay = $this->checkedInStay();

        $first = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        $second = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\CheckoutInspection::where('stay_id', $stay->id)->count());
    }

    public function test_complete_computes_chargeable_quantity_and_posts_folio_entry(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $completed = app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'actual_quantity' => 5],
        ], null, $this->admin);

        $item = $completed->items->first();
        // actual 5 - free 2 = chargeable 3
        $this->assertSame(3, $item->chargeable_quantity);
        $this->assertSame('45000.00', (string) $item->line_total);
        $this->assertSame(CheckoutInspectionStatus::Completed, $completed->status);
        $this->assertNotNull($completed->posted_at);

        $folio = $stay->booking->folio;
        $entry = $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->firstOrFail();
        $this->assertSame('3.00', (string) $entry->quantity);
        $this->assertSame('45000.00', (string) $entry->amount);
        $this->assertSame($stay->id, $entry->stay_id);
    }

    public function test_completing_twice_does_not_duplicate_folio_charges(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $service = app(CheckoutInspectionService::class);
        $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'actual_quantity' => 5],
        ], null, $this->admin);

        // Simulate double-click / retry: call complete() again on the same (now-completed) inspection.
        $service->complete($inspection->fresh(), [
            ['product_service_id' => $this->water->id, 'actual_quantity' => 5],
        ], null, $this->admin);

        $folio = $stay->booking->folio;
        $this->assertSame(1, $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());
    }

    public function test_confirm_no_charge_completes_with_zero_total_and_no_folio_entry(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $completed = app(CheckoutInspectionService::class)->complete($inspection, [], 'Không phát sinh.', $this->admin);

        $this->assertSame('0.00', (string) $completed->total_amount);
        $folio = $stay->booking->folio;
        $this->assertSame(0, $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());
    }

    public function test_cannot_edit_after_completion(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        app(CheckoutInspectionService::class)->complete($inspection, [], null, $this->admin);

        $this->expectException(ValidationException::class);
        app(CheckoutInspectionService::class)->saveDraft($inspection->fresh(), [
            ['product_service_id' => $this->water->id, 'actual_quantity' => 1],
        ], 'edit attempt', $this->admin);
    }

    public function test_price_change_after_completion_does_not_alter_posted_snapshot(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        $completed = app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'actual_quantity' => 5],
        ], null, $this->admin);

        $this->water->update(['price' => 99000]);

        $item = $completed->items->first()->fresh();
        $this->assertSame('15000.00', (string) $item->unit_price_snapshot);
    }

    public function test_multi_room_booking_inspections_are_independent_and_scoped_per_room(): void
    {
        $room1 = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->first();
        $room2 = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->skip(1)->first();

        $booking = $this->createBooking([
            'requirements' => [[
                'room_type_id' => $this->twinType->id,
                'quantity' => 2,
                'adults' => 2,
                'children_under_6' => 0,
                'children_over_6' => 0,
                'room_price' => 800000,
                'price_source' => 'MANUAL',
            ]],
        ]);

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room1->id, 'room_type_id' => $room1->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
            ['room_id' => $room2->id, 'room_type_id' => $room2->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);
        app(StayService::class)->checkIn($stay1);
        app(StayService::class)->checkIn($stay2);

        $service = app(CheckoutInspectionService::class);
        $inspection1 = $service->getOrCreateDraft($stay1, $this->admin);
        $inspection2 = $service->getOrCreateDraft($stay2, $this->admin);

        $this->assertNotSame($inspection1->id, $inspection2->id);

        $service->complete($inspection1, [
            ['product_service_id' => $this->water->id, 'actual_quantity' => 5],
        ], null, $this->admin);

        // stay2 not inspected yet — partial checkout of room1 must not require/imply room2's inspection.
        $this->assertNull($inspection2->fresh()->completed_at);

        $folio = $booking->folio;
        $entry = $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->firstOrFail();
        $this->assertSame($stay1->id, $entry->stay_id);
    }

    public function test_stay_event_recorded_on_completion(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'actual_quantity' => 5],
        ], null, $this->admin);

        $event = $stay->stayEvents()->where('event_type', StayEventType::InspectionCompleted)->firstOrFail();
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertEquals(45000.0, $event->metadata['total_amount']);
    }

    private function checkedInStay(): Stay
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return $stay->fresh();
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Inspection Guest',
            'customer_phone' => '0900000006',
            'customer_email' => 'inspection@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
        ], $overrides);

        if (! isset($payload['requirements'])) {
            $payload['requirements'] = [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 1,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => 800000,
                    'price_source' => 'MANUAL',
                ],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
