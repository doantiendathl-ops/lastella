<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentType;
use App\Enums\PriceSource;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingManagementUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('ADMIN');
    }

    public function test_admin_can_view_booking_list(): void
    {
        $this->actingAs($this->admin);
        $this->createBooking();

        $this->get('/admin/bookings')->assertOk();
    }

    public function test_admin_can_create_booking(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/bookings', $this->bookingPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('bookings', [
            'customer_name' => 'Jane Guest',
            'booking_type' => BookingType::Overnight->value,
            'customer_type' => CustomerType::Individual->value,
        ]);
    }

    public function test_admin_can_view_booking_detail(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}")->assertOk();
    }

    public function test_admin_can_add_requirement(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking(withRequirements: false);
        $roomType = RoomType::where('code', 'TWIN')->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/requirements", $this->requirementPayload($roomType))
            ->assertRedirect();

        $this->assertDatabaseHas('booking_requirements', [
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'quantity' => 1,
        ]);
    }

    public function test_admin_can_add_deposit(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/payments", [
            'payment_type' => PaymentType::Deposit->value,
            'amount' => 1200,
            'payment_method' => 'cash',
            'payment_at' => '2026-07-01 10:00:00',
            'note' => 'Deposit received',
        ])->assertRedirect();

        $this->assertDatabaseHas('booking_payments', [
            'booking_id' => $booking->id,
            'payment_type' => PaymentType::Deposit->value,
            'amount' => 1200,
            'payment_method' => 'cash',
        ]);
    }

    public function test_admin_can_assign_available_room(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_id' => $room->id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        $this->assertDatabaseHas('room_assignments', [
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => AssignmentStatus::Assigned->value,
        ]);

        $this->assertDatabaseHas('stays', [
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => StayStatus::Reserved->value,
        ]);
    }

    public function test_admin_cannot_assign_conflicting_room(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_id' => $room->id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        $this->from("/admin/bookings/{$conflictingBooking->id}?tab=assignments")
            ->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
                'room_id' => $room->id,
                'start_at' => '2026-07-02 10:00:00',
                'end_at' => '2026-07-02 18:00:00',
            ])
            ->assertRedirect("/admin/bookings/{$conflictingBooking->id}?tab=assignments")
            ->assertSessionHasErrors('room_id');
    }

    public function test_admin_can_release_assignment(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [
            'release_reason' => 'Guest changed room type',
        ])->assertRedirect();

        $this->assertSame(AssignmentStatus::Released, $assignment->refresh()->status);
        $this->assertSame('Guest changed room type', $assignment->release_reason);
    }

    public function test_admin_can_check_in_stay(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")
            ->assertRedirect();

        $this->assertSame(StayStatus::CheckedIn, $stay->refresh()->status);
        $this->assertNotNull($stay->actual_checkin_at);
    }

    public function test_admin_can_check_out_stay(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out")->assertRedirect();

        $this->assertSame(StayStatus::CheckedOut, $stay->refresh()->status);
        $this->assertNotNull($stay->actual_checkout_at);
    }

    public function test_user_without_permission_cannot_create_booking(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');

        $this->actingAs($accountant)
            ->post('/admin/bookings', $this->bookingPayload())
            ->assertForbidden();
    }

    private function createAssignment(): array
    {
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_id' => $room->id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        return [
            $booking,
            RoomAssignment::where('booking_id', $booking->id)->where('room_id', $room->id)->firstOrFail(),
        ];
    }

    private function createBooking(array $overrides = [], bool $withRequirements = true): Booking
    {
        $payload = $this->bookingPayload($overrides);

        if ($withRequirements) {
            $payload['requirements'] = [
                $this->requirementPayload(RoomType::where('code', 'TWIN')->firstOrFail()),
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }

    private function bookingPayload(array $overrides = []): array
    {
        return [
            'booking_color' => '#196251',
            'customer_name' => 'Jane Guest',
            'customer_phone' => '0800000000',
            'customer_email' => 'jane@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'note' => 'Guest note',
            'internal_note' => 'Internal note',
            ...$overrides,
        ];
    }

    private function requirementPayload(RoomType $roomType): array
    {
        return [
            'room_type_id' => $roomType->id,
            'quantity' => 1,
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'room_price' => 1800,
            'price_source' => PriceSource::Manual->value,
            'note' => 'Manual rate',
        ];
    }

    private function roomForType(string $roomTypeCode): Room
    {
        $roomType = RoomType::where('code', $roomTypeCode)->firstOrFail();

        return Room::where('room_type_id', $roomType->id)->firstOrFail();
    }
}
