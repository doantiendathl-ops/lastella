<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CleaningPriority;
use App\Enums\CustomerType;
use App\Enums\HousekeepingAssignmentStatus;
use App\Enums\InspectionResult;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\HousekeepingAssignment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.2 Integration & Final Verification: full state-machine chains, exercised
 * through the real HTTP routes end to end, not isolated per-method unit setups.
 */
class HousekeepingWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $housekeeping;
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

        $this->travelTo('2026-07-01 14:00:00');

        $this->admin       = tap(User::factory()->create(['name' => 'Admin User']))->assignRole('ADMIN');
        $this->manager     = tap(User::factory()->create(['name' => 'Manager User']))->assignRole('MANAGER');
        $this->housekeeping = tap(User::factory()->create(['name' => 'HK User']))->assignRole('HOUSEKEEPING');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // 1. Full cleaning workflow — every branch, driven through the real HTTP routes
    // -------------------------------------------------------------------------

    public function test_full_workflow_pass_path_vacant_dirty_to_vacant_clean(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $room->update(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->manager)
            ->postJson("/admin/housekeeping/{$room->id}/assign", ['assigned_to' => $this->housekeeping->id])
            ->assertCreated();
        $assignment = HousekeepingAssignment::where('room_id', $room->id)->latest()->firstOrFail();
        $this->assertEquals(HousekeepingAssignmentStatus::Pending, $assignment->status);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/assignments/{$assignment->id}/start")
            ->assertOk();
        $this->assertEquals(RoomStatus::Cleaning, $room->refresh()->status);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/assignments/{$assignment->id}/complete", ['notes' => 'Đã dọn xong'])
            ->assertOk();
        $this->assertEquals(RoomStatus::Inspected, $room->refresh()->status);
        $this->assertEquals(HousekeepingAssignmentStatus::Done, $assignment->refresh()->status);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/pass-inspection", ['notes' => 'Đạt tiêu chuẩn'])
            ->assertOk()
            ->assertJsonPath('inspection_result', InspectionResult::Pass->value);
        $this->assertEquals(RoomStatus::VacantClean, $room->refresh()->status);
        $this->assertNotNull($room->last_cleaned_at);
    }

    public function test_full_workflow_fail_path_returns_to_vacant_dirty_with_reescalated_assignment(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $room->update(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->housekeeping)->postJson("/admin/housekeeping/{$room->id}/assign", [])->assertCreated();
        $assignment = HousekeepingAssignment::where('room_id', $room->id)->latest()->firstOrFail();

        $this->actingAs($this->housekeeping)->patchJson("/admin/housekeeping/assignments/{$assignment->id}/start")->assertOk();
        $this->actingAs($this->housekeeping)->patchJson("/admin/housekeeping/assignments/{$assignment->id}/complete")->assertOk();

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/fail-inspection", ['notes' => 'Chưa đạt'])
            ->assertOk()
            ->assertJsonPath('inspection_result', InspectionResult::Fail->value);

        $this->assertEquals(RoomStatus::VacantDirty, $room->refresh()->status);

        // M2-03 hardening: exactly one re-escalated assignment, not a duplicate.
        $this->assertEquals(1, HousekeepingAssignment::where('room_id', $room->id)
            ->where('status', HousekeepingAssignmentStatus::Pending)
            ->count());
        $this->assertDatabaseHas('housekeeping_assignments', [
            'room_id'  => $room->id,
            'status'   => HousekeepingAssignmentStatus::Pending->value,
            'priority' => CleaningPriority::High->value,
        ]);
    }

    public function test_full_workflow_skip_path_goes_to_vacant_clean_without_pass_or_fail(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $room->update(['status' => RoomStatus::VacantDirty]);

        $this->actingAs($this->housekeeping)->postJson("/admin/housekeeping/{$room->id}/assign", [])->assertCreated();
        $assignment = HousekeepingAssignment::where('room_id', $room->id)->latest()->firstOrFail();

        $this->actingAs($this->housekeeping)->patchJson("/admin/housekeeping/assignments/{$assignment->id}/start")->assertOk();
        $this->actingAs($this->housekeeping)->patchJson("/admin/housekeeping/assignments/{$assignment->id}/complete")->assertOk();

        // ADR-90: skip requires manager/admin (room.inspect), and non-empty notes.
        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/skip-inspection", [])
            ->assertUnprocessable();

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/skip-inspection", ['notes' => 'Khách VIP quay lại gấp, quản lý miễn kiểm tra'])
            ->assertOk()
            ->assertJsonPath('inspection_result', InspectionResult::Skip->value);

        $this->assertEquals(RoomStatus::VacantClean, $room->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // 2. Booking Integration: full loop from check-in through housekeeping to Vacant Clean
    // -------------------------------------------------------------------------

    public function test_full_booking_lifecycle_check_in_check_out_housekeeping_loop(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-01 14:00:00', 'end_at' => '2026-07-02 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);

        // Check-in → OCCUPIED (ADR-84)
        app(StayService::class)->checkIn($stay);
        $this->assertEquals(RoomStatus::Occupied, $room->refresh()->status);

        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount'         => 800000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'     => now()->toDateTimeString(),
        ]);

        // Check-out → VACANT_DIRTY + auto-created pending assignment (ADR-84)
        app(StayService::class)->checkOut($stay, null, true);
        $this->assertEquals(RoomStatus::VacantDirty, $room->refresh()->status);
        $assignment = HousekeepingAssignment::where('room_id', $room->id)->latest()->firstOrFail();
        $this->assertEquals(HousekeepingAssignmentStatus::Pending, $assignment->status);
        $this->assertNull($assignment->assigned_to);

        // Milestone 5.1 (ADR-90 auto-claim): a HOUSEKEEPING user can now start the
        // auto-created UNASSIGNED assignment directly — startCleaning() claims it for them.
        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/assignments/{$assignment->id}/start")
            ->assertOk();
        $this->assertEquals(RoomStatus::Cleaning, $room->refresh()->status);
        $this->assertEquals($this->housekeeping->id, $assignment->refresh()->assigned_to);

        $this->actingAs($this->housekeeping)
            ->patchJson("/admin/housekeeping/assignments/{$assignment->id}/complete")
            ->assertOk();
        $this->assertEquals(RoomStatus::Inspected, $room->refresh()->status);

        $this->actingAs($this->manager)
            ->patchJson("/admin/housekeeping/{$room->id}/pass-inspection")
            ->assertOk();

        // Ready — room is VACANT_CLEAN and bookable again.
        $this->assertEquals(RoomStatus::VacantClean, $room->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // 3. Booking Room Assignment board (RoomAssignmentService::getRoomBoard) — CLEANING integration
    // -------------------------------------------------------------------------

    public function test_cleaning_room_is_unavailable_on_booking_room_assignment_board(): void
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $room->update(['status' => RoomStatus::Cleaning]);

        $booking = $this->createBooking();

        $board = app(RoomAssignmentService::class)->getRoomBoard($booking);

        $roomEntry = collect($board['floors'])
            ->flatMap(fn ($floor) => $floor['rooms'])
            ->firstWhere('id', $room->id);

        $this->assertNotNull($roomEntry);
        $this->assertSame('unavailable', $roomEntry['availability_status']);
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color'    => '#196251',
            'customer_name'    => 'Integration Guest',
            'customer_phone'   => '0900000002',
            'customer_email'   => 'hk-integration@example.test',
            'customer_type'    => CustomerType::Individual->value,
            'booking_type'     => BookingType::Overnight->value,
            'checkin_at'       => '2026-07-01 14:00:00',
            'checkout_at'      => '2026-07-02 12:00:00',
            'adults'           => 2,
            'children_under_6' => 0,
            'children_over_6'  => 0,
            'sales_user_id'    => $this->admin->id,
        ], $overrides);

        if (! isset($payload['requirements'])) {
            $payload['requirements'] = [
                [
                    'room_type_id'     => $this->twinType->id,
                    'quantity'         => 1,
                    'adults'           => 2,
                    'children_under_6' => 0,
                    'children_over_6'  => 0,
                    'room_price'       => 800000,
                    'price_source'     => 'MANUAL',
                ],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
