<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\ChargeType;
use App\Exceptions\RequirementLockedAfterRoomChargeException;
use App\Exceptions\RequirementReferencedByAssignmentException;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Room Demand/Room Board Unification M1 — BookingService requirement
 * mutation locking (addRequirement/updateRequirement/deleteRequirement) and
 * the sequential-simulation "concurrency" scenarios in scope for M1.
 *
 * Following the project's existing convention for testing lock-guarded code
 * without true multi-connection parallelism (see
 * BookingEngineFoundationTest::test_concurrent_*): a "concurrent" write is
 * simulated by fully committing it BEFORE the operation under test runs, so
 * the assertion is that the locked read sees fresh state rather than a
 * stale snapshot — not a literal race between two threads.
 */
class BookingRequirementLockingTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->bookings = app(BookingService::class);
    }

    private function makeBookingWithRoomType(): array
    {
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->for($roomType)->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Draft]);

        return [$booking, $roomType, $room];
    }

    // ── 16: addRequirement still creates correct data ──────────────────────

    public function test_add_requirement_still_creates_correct_data_and_updates_booking_status(): void
    {
        [$booking, $roomType] = $this->makeBookingWithRoomType();

        $requirement = $this->bookings->addRequirement($booking, [
            'room_type_id' => $roomType->id,
            'quantity' => 2,
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'room_price' => 500000,
            'price_source' => 'RATE_TABLE',
            'note' => null,
        ]);

        $this->assertDatabaseHas('booking_requirements', [
            'id' => $requirement->id,
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'quantity' => 2,
        ]);
        $this->assertSame(BookingStatus::PendingAssignment, $booking->fresh()->status);
    }

    // ── 17: updateRequirement still enforces the Folio room-charge guard ───

    public function test_update_requirement_still_throws_when_room_charge_already_posted(): void
    {
        [$booking, $roomType] = $this->makeBookingWithRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        $folio = Folio::factory()->for($booking)->create();
        FolioEntry::factory()->for($folio)->create([
            'charge_type' => ChargeType::Room,
            'voided_at' => null,
        ]);

        $this->expectException(RequirementLockedAfterRoomChargeException::class);

        $this->bookings->updateRequirement($requirement, ['quantity' => 5]);
    }

    // ── 18/19/20: deleteRequirement reference guard ─────────────────────────

    public function test_delete_requirement_without_any_assignment_reference_still_works(): void
    {
        [$booking, $roomType] = $this->makeBookingWithRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);

        $this->bookings->deleteRequirement($requirement);

        $this->assertDatabaseMissing('booking_requirements', ['id' => $requirement->id]);
    }

    public function test_delete_requirement_blocked_when_referenced_by_an_assigned_assignment(): void
    {
        [$booking, $roomType, $room] = $this->makeBookingWithRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirement->id,
            'status' => AssignmentStatus::Assigned,
        ]);

        $this->expectException(RequirementReferencedByAssignmentException::class);

        $this->bookings->deleteRequirement($requirement);
    }

    public function test_delete_requirement_blocked_when_referenced_by_a_released_assignment(): void
    {
        [$booking, $roomType, $room] = $this->makeBookingWithRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirement->id,
            'status' => AssignmentStatus::Released,
        ]);

        $this->expectException(RequirementReferencedByAssignmentException::class);

        $this->bookings->deleteRequirement($requirement);

        $this->assertDatabaseHas('booking_requirements', ['id' => $requirement->id]);
    }

    // ── 21: the delete guard is a friendly domain exception, not raw SQL ───

    public function test_delete_requirement_reference_guard_is_a_friendly_exception_not_a_raw_sql_error(): void
    {
        [$booking, $roomType, $room] = $this->makeBookingWithRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);
        RoomAssignment::factory()->for($booking)->for($room)->create([
            'room_type_id' => $roomType->id,
            'booking_requirement_id' => $requirement->id,
            'status' => AssignmentStatus::Assigned,
        ]);

        try {
            $this->bookings->deleteRequirement($requirement);
            $this->fail('Expected RequirementReferencedByAssignmentException was not thrown.');
        } catch (RequirementReferencedByAssignmentException $exception) {
            $this->assertNotInstanceOf(QueryException::class, $exception);
            $this->assertStringContainsString('Không thể xóa yêu cầu phòng', $exception->getMessage());
        }
    }

    // ── 22: requirement not belonging to the route's booking is blocked ────
    // Enforced today at the controller (route ownership check before the
    // service is ever called) — verified here at the HTTP boundary where the
    // real guard lives, per Section XI (controller not modified in M1).

    public function test_deleting_a_requirement_via_a_mismatched_booking_route_returns_not_found(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');
        $this->actingAs($admin);

        [$bookingA, $roomTypeA] = $this->makeBookingWithRoomType();
        [$bookingB] = $this->makeBookingWithRoomType();

        $requirementOfA = BookingRequirement::factory()->create([
            'booking_id' => $bookingA->id,
            'room_type_id' => $roomTypeA->id,
        ]);

        $this->delete("/admin/bookings/{$bookingB->id}/requirements/{$requirementOfA->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('booking_requirements', ['id' => $requirementOfA->id]);
    }

    // ── 23: two sequential "concurrent" adds do not lose the aggregate status ─

    public function test_two_sequential_add_requirement_calls_do_not_lose_the_aggregate_booking_status(): void
    {
        [$booking, $roomType] = $this->makeBookingWithRoomType();
        $otherRoomType = RoomType::factory()->create();

        $this->bookings->addRequirement($booking, [
            'room_type_id' => $roomType->id,
            'quantity' => 2,
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'room_price' => 500000,
            'price_source' => 'RATE_TABLE',
            'note' => null,
        ]);

        // Simulated concurrent request: fully committed before the second
        // call runs, same as the project's existing "concurrent" test idiom.
        $this->bookings->addRequirement($booking, [
            'room_type_id' => $otherRoomType->id,
            'quantity' => 1,
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'room_price' => 700000,
            'price_source' => 'RATE_TABLE',
            'note' => null,
        ]);

        $this->assertSame(2, BookingRequirement::where('booking_id', $booking->id)->count());
        $this->assertSame(
            3,
            (int) BookingRequirement::where('booking_id', $booking->id)->sum('quantity'),
        );
        $this->assertSame(BookingStatus::PendingAssignment, $booking->fresh()->status);
    }

    // ── 24: sequential updates on the same requirement do not lose each other's change ─

    public function test_two_sequential_updates_on_the_same_requirement_both_persist(): void
    {
        [$booking, $roomType] = $this->makeBookingWithRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'quantity' => 1,
            'note' => null,
        ]);

        $this->bookings->updateRequirement($requirement, ['note' => 'ghi chú đầu tiên']);
        $this->bookings->updateRequirement($requirement->fresh(), ['quantity' => 4]);

        $fresh = $requirement->fresh();
        $this->assertSame('ghi chú đầu tiên', $fresh->note);
        $this->assertSame(4, $fresh->quantity);
    }

    // ── 25: delete then update on the SAME requirement is observed, not silently lost ─

    public function test_updating_a_requirement_already_deleted_by_a_concurrent_request_fails_loudly(): void
    {
        [$booking, $roomType] = $this->makeBookingWithRoomType();
        $requirement = BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);

        // Caller holds a stale handle, as a controller would after route-model
        // binding resolved it earlier in the request.
        $staleHandle = $requirement->fresh();

        // Simulated concurrent request: a delete of the SAME requirement,
        // fully committed before the update below runs.
        $this->bookings->deleteRequirement($requirement);

        $this->expectException(ModelNotFoundException::class);

        $this->bookings->updateRequirement($staleHandle, ['quantity' => 9]);
    }
}
