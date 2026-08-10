<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick Note Lifecycle addendum — Booking-level quick_note (default/initial
 * value) + copy-on-assignment onto RoomAssignment (Mục A–D).
 */
class QuickNoteLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

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

        $this->travelTo('2026-08-01 10:00:00');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);
    }

    /** 1. Booking create accepts quick_note <=100. */
    public function test_booking_create_accepts_quick_note(): void
    {
        $response = $this->post(route('admin.bookings.store'), $this->bookingPayload(['quick_note' => 'Khách cần phòng yên tĩnh']));

        $response->assertRedirect();
        $booking = Booking::latest('id')->firstOrFail();
        $this->assertSame('Khách cần phòng yên tĩnh', $booking->quick_note);
    }

    /** 2. Booking create rejects >100. */
    public function test_booking_create_rejects_quick_note_over_100_chars(): void
    {
        $response = $this->post(route('admin.bookings.store'), $this->bookingPayload(['quick_note' => str_repeat('a', 101)]));

        $response->assertSessionHasErrors('quick_note');
        $this->assertSame(0, Booking::count());
    }

    /** 3. Booking edit quick_note. */
    public function test_booking_update_edits_quick_note(): void
    {
        $booking = app(BookingService::class)->createBooking($this->bookingData(['quick_note' => 'Ban đầu']));

        $this->put(route('admin.bookings.update', $booking), $this->bookingPayload(['quick_note' => 'Đã cập nhật']))
            ->assertRedirect();

        $this->assertSame('Đã cập nhật', $booking->fresh()->quick_note);
    }

    /** 4 & 5. Assignment(s) created from Booking copy Booking quick_note. */
    public function test_new_assignments_copy_booking_quick_note(): void
    {
        $booking = app(BookingService::class)->createBooking($this->bookingData(['quick_note' => 'Khách cần phòng yên tĩnh']));
        $rooms = Room::whereIn('room_number', ['104', '105', '106'])->get();

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, $rooms->map(fn (Room $r) => [
            'room_id' => $r->id,
            'room_type_id' => $r->room_type_id,
            'start_at' => '2026-08-01 14:00:00',
            'end_at' => '2026-08-02 12:00:00',
        ])->all());

        foreach ($assignments as $assignment) {
            $this->assertSame('Khách cần phòng yên tĩnh', $assignment->quick_note);
        }
    }

    /** 6. Room-level quick note can be edited independently. */
    public function test_room_level_quick_note_can_be_edited_independently(): void
    {
        $booking = app(BookingService::class)->createBooking($this->bookingData(['quick_note' => 'Mặc định']));
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);

        $this->patch(route('admin.room-operations.assignments.quick-note', $assignment), ['quick_note' => 'Mặc định — thêm 1 gối'])
            ->assertRedirect();

        $this->assertSame('Mặc định — thêm 1 gối', $assignment->fresh()->quick_note);
        $this->assertSame('Mặc định', $booking->fresh()->quick_note);
    }

    /** 7. Editing Booking quick_note afterward does NOT overwrite existing assignment quick_note (no live-sync). */
    public function test_editing_booking_quick_note_does_not_overwrite_existing_assignments(): void
    {
        $booking = app(BookingService::class)->createBooking($this->bookingData(['quick_note' => 'Khách cần phòng yên tĩnh']));
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room105 = Room::where('room_number', '105')->firstOrFail();
        $room106 = Room::where('room_number', '106')->firstOrFail();

        [$a104, $a105, $a106] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room104->id, 'room_type_id' => $room104->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
            ['room_id' => $room105->id, 'room_type_id' => $room105->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
            ['room_id' => $room106->id, 'room_type_id' => $room106->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);

        // Room-specific edits, matching the spec's own example.
        $a104->update(['quick_note' => 'Khách cần phòng yên tĩnh — thêm 1 gối']);
        $a106->update(['quick_note' => 'Khách cần phòng yên tĩnh — khách đến muộn']);
        // 105 keeps the original copied value untouched.

        app(BookingService::class)->updateBooking($booking, ['quick_note' => 'NEW BOOKING DEFAULT']);

        $this->assertSame('Khách cần phòng yên tĩnh — thêm 1 gối', $a104->fresh()->quick_note);
        $this->assertSame('Khách cần phòng yên tĩnh', $a105->fresh()->quick_note);
        $this->assertSame('Khách cần phòng yên tĩnh — khách đến muộn', $a106->fresh()->quick_note);
    }

    /** 8. New room assigned afterward gets the LATEST Booking quick_note. */
    public function test_new_assignment_after_booking_note_edit_gets_latest_note(): void
    {
        $booking = app(BookingService::class)->createBooking($this->bookingData(['quick_note' => 'Khách cần phòng yên tĩnh']));
        $room104 = Room::where('room_number', '104')->firstOrFail();
        app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room104->id, 'room_type_id' => $room104->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);

        app(BookingService::class)->updateBooking($booking, ['quick_note' => 'Khách VIP — cần hỗ trợ hành lý']);

        $room107 = Room::where('room_number', '107')->firstOrFail();
        [$newAssignment] = app(RoomAssignmentService::class)->assignRooms($booking->fresh(), [
            ['room_id' => $room107->id, 'room_type_id' => $room107->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);

        $this->assertSame('Khách VIP — cần hỗ trợ hành lý', $newAssignment->quick_note);
    }

    /** 9. Move room (checked-in) preserves the assignment's own quick_note — same row, no copy needed. */
    public function test_move_room_preserves_assignment_quick_note(): void
    {
        $booking = app(BookingService::class)->createBooking($this->bookingData(['quick_note' => 'Ghi chú gốc']));
        $room104 = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room104->id, 'room_type_id' => $room104->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);
        $assignment->update(['quick_note' => 'Ghi chú riêng của phòng']);
        $stay = app(\App\Services\StayService::class)->createStayFromAssignment($assignment);
        app(\App\Services\StayService::class)->checkIn($stay);

        $targetRoom = Room::where('room_number', '105')->firstOrFail();
        app(\App\Services\StayService::class)->moveRoom($stay, $targetRoom, $this->admin);

        $this->assertSame('Ghi chú riêng của phòng', $assignment->fresh()->quick_note);
    }

    /** 14. Empty/null quick note works throughout. */
    public function test_null_quick_note_works(): void
    {
        $booking = app(BookingService::class)->createBooking($this->bookingData([]));
        $this->assertNull($booking->quick_note);

        $room104 = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room104->id, 'room_type_id' => $room104->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);

        $this->assertNull($assignment->quick_note);
    }

    /** 15. Quick note does not affect Folio/payment/customer ownership. */
    public function test_quick_note_does_not_affect_financial_or_customer_ownership(): void
    {
        $booking = app(BookingService::class)->createBooking($this->bookingData(['quick_note' => 'Ghi chú']));
        $room104 = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room104->id, 'room_type_id' => $room104->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);

        $before = app(\App\Services\PaymentProjectionService::class)->project($booking->fresh());

        $this->patch(route('admin.room-operations.assignments.quick-note', $assignment), ['quick_note' => 'Ghi chú mới'])->assertRedirect();
        app(BookingService::class)->updateBooking($booking->fresh(), ['quick_note' => 'Ghi chú booking mới']);

        $after = app(\App\Services\PaymentProjectionService::class)->project($booking->fresh());

        $this->assertSame($before['expected_total'], $after['expected_total']);
        $this->assertSame('Room Ops Guest', $booking->fresh()->customer_name);
    }

    private function bookingData(array $overrides): array
    {
        return array_merge($this->bookingPayload($overrides), [
            'created_by' => $this->admin->id,
        ]);
    }

    private function bookingPayload(array $overrides): array
    {
        return array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Room Ops Guest',
            'customer_phone' => '0900000050',
            'customer_email' => 'quick-note@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-08-01 14:00:00',
            'checkout_at' => '2026-08-02 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
        ], $overrides);
    }
}
