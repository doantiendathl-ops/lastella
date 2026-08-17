<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\RoomOperationsBoardService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Product Owner UI/Inspection Corrections addendum.
 *
 * Root-cause test for the reported bug: can_inspect was gated on
 * AssignmentStatus::CheckedOut, but CheckoutInspectionController::index()
 * itself only ever lists CheckedIn stays (inspection is a PRE-checkout
 * workflow) — so "Kiểm đồ" was always ineligible on the board. Also proves
 * the checkout backend enforces the exact same guards regardless of what
 * the frontend sends (Mục VII/XI) — confirmed=true never bypasses balance
 * or stay-state gates, and inspection remains advisory-only at the backend,
 * matching Booking Detail's already-documented behavior (never a hard lock).
 */
class RoomOperationsInspectionGuardTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

    private User $admin;
    private RoomType $twinType;

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

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_can_inspect_is_true_for_checked_in_uninspected_stay(): void
    {
        [, , $stay] = $this->checkedInStay();

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $room = $this->findRoomInBoard($board, '104');

        $this->assertTrue($room['actions']['can_inspect'], 'can_inspect must be true for a checked-in, uninspected stay.');
        $this->assertSame('none', $room['occupant']['inspection_status']);
    }

    public function test_can_inspect_is_false_before_check_in(): void
    {
        $booking = $this->createBooking('2026-08-01 14:00:00', '2026-08-02 12:00:00', 'reserved@example.test');
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);
        app(StayService::class)->createStayFromAssignment($assignment);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $boardRoom = $this->findRoomInBoard($board, '104');

        $this->assertFalse($boardRoom['actions']['can_inspect']);
    }

    /**
     * Inspection Financial Correction follow-up: a Completed-but-not-yet-
     * checked-out inspection must remain reachable from the board — it is
     * still editable via CheckoutInspectionService::editCompleted(). Gating
     * "Kiểm đồ" purely on "already completed" reintroduced the exact class
     * of bug this file exists to guard against (button permanently disabled
     * even though the record is still correctable).
     */
    public function test_can_inspect_is_true_when_completed_but_not_yet_checked_out(): void
    {
        [, , $stay] = $this->checkedInStay();
        $inspection = app(\App\Services\CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        app(\App\Services\CheckoutInspectionService::class)->complete($inspection, [], null, $this->admin);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $room = $this->findRoomInBoard($board, '104');

        $this->assertTrue($room['actions']['can_inspect'], 'can_inspect must stay true so a completed-but-editable inspection can be reopened.');
        $this->assertSame('completed', $room['occupant']['inspection_status']);
    }

    public function test_can_inspect_is_false_after_checkout(): void
    {
        [$booking, , $stay] = $this->checkedInStay();
        $inspection = app(\App\Services\CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        app(\App\Services\CheckoutInspectionService::class)->complete($inspection, [], null, $this->admin);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => self::ROOM_PRICE,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay->fresh(), null, true);

        $board = app(RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);
        $room = $this->findRoomInBoard($board, '104');

        $this->assertFalse($room['actions']['can_inspect'], 'can_inspect must be false once the stay has actually checked out — history is locked.');
    }

    /**
     * Mục VII: inspection is advisory at the backend (same as Booking
     * Detail's RoomBoardPanel already documents) — a checkout for an
     * uninspected stay is NOT blocked by StayService::checkOut() itself.
     * This is expected, pre-existing behavior — not something this task
     * changes at the backend. The UI-side warning is what was missing.
     */
    public function test_checkout_backend_does_not_hard_block_on_missing_inspection(): void
    {
        [$booking, , $stay] = $this->checkedInStay();
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => self::ROOM_PRICE,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);

        $this->post(route('admin.room-operations.check-out'), ['stay_ids' => [$stay->id], 'confirmed' => true])
            ->assertRedirect();

        $this->assertNotNull($stay->fresh()->actual_checkout_at);
        $this->assertSame('none', $stay->fresh()->inspectionStatus());
    }

    /**
     * Mục VII/XI: forging confirmed=true directly (bypassing every UI step)
     * must NOT bypass the outstanding-balance guard — proves the board's
     * checkout endpoint enforces the exact same server-side rule as Booking
     * Detail, regardless of what the frontend sends.
     */
    // docs/Prompt_2.txt mục IV — outstanding balance is no longer a guard to
    // bypass: checkout with confirmed=true now legitimately succeeds despite
    // a positive balance (supersedes the old "forged confirmed=true still
    // blocked by OBE" proof this slot used to hold — that guard is gone by
    // design, not a regression).
    public function test_confirmed_true_succeeds_with_outstanding_balance(): void
    {
        [, , $stay] = $this->checkedInStay(); // no payment made

        $this->post(route('admin.room-operations.check-out'), ['stay_ids' => [$stay->id], 'confirmed' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($stay->fresh()->actual_checkout_at);
    }

    /**
     * Mục VII: forging confirmed=true for a stay that isn't even checked in
     * yet must not be accepted — stay-state guard is still enforced.
     */
    public function test_forged_confirmed_true_does_not_bypass_stay_state_guard(): void
    {
        $booking = $this->createBooking('2026-08-01 14:00:00', '2026-08-02 12:00:00', 'not-checked-in@example.test');
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        $this->post(route('admin.room-operations.check-out'), ['stay_ids' => [$stay->id], 'confirmed' => true])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertNull($stay->fresh()->actual_checkout_at);
    }

    /** Mục IX: skip-inspection reuses the existing per-stay endpoint, no second inspection record. */
    public function test_inspection_skip_endpoint_reused_from_board_records_single_source(): void
    {
        [$booking, , $stay] = $this->checkedInStay();

        $this->post(route('admin.bookings.stays.inspection-skip', ['booking' => $booking, 'stay' => $stay]), ['reason' => 'Khách vội'])
            ->assertRedirect();

        $this->assertSame('skipped', $stay->fresh()->inspectionStatus());
        $this->assertDatabaseCount('checkout_inspections', 0);
    }

    private function findRoomInBoard(array $board, string $roomNumber): array
    {
        foreach ($board['floors'] as $floor) {
            foreach ($floor['rooms'] as $room) {
                if ($room['room_number'] === $roomNumber) {
                    return $room;
                }
            }
        }

        $this->fail("Room {$roomNumber} not found on board.");
    }

    /**
     * @return array{0: \App\Models\Booking, 1: \App\Models\RoomAssignment, 2: Stay}
     */
    private function checkedInStay(): array
    {
        $booking = $this->createBooking('2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $room = Room::where('room_number', '104')->firstOrFail();
        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-08-01 14:00:00', 'end_at' => '2026-08-02 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return [$booking, $assignment, $stay->fresh()];
    }

    private function createBooking(string $checkinAt, string $checkoutAt, string $email = 'guest@example.test'): \App\Models\Booking
    {
        return app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Inspection Guest',
            'customer_phone' => '0900000060',
            'customer_email' => $email,
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => $checkinAt,
            'checkout_at' => $checkoutAt,
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
                    'room_price' => self::ROOM_PRICE,
                    'price_source' => 'MANUAL',
                ],
            ],
        ]);
    }
}
