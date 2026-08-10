<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
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

/**
 * Pre-Commit Critical Safety Closure (Blocker #3, Mục XIII-XVI): a frontend
 * `can_*` flag is display-only. Every one of the 5 Room Operations actions
 * must be independently rejected by the backend for a user lacking the
 * matching Spatie permission, regardless of what the client sends — the
 * ACCOUNTANT role (report/finance viewing only, none of the 5 operational
 * permissions below) is the forged-request actor throughout this file.
 *
 * Audit result: every one of the 5 actions was ALREADY correctly guarded —
 * SwapPreviewRequest/SwapExecuteRequest::authorize() → room.assign,
 * BulkCheckInRequest::authorize() → stay.checkin (+ StayPolicy::checkIn()
 * per-stay in the controller), BulkCheckOutRequest::authorize() →
 * stay.checkout (+ StayPolicy::checkOut() per-stay), CheckoutInspectionPolicy
 * → checkout_inspection.perform, MarkCleanRequest/MarkDirtyRequest::authorize()
 * → HousekeepingPolicy::markCleaning() → room.cleaning.update. This file
 * exists to make that guarantee explicit and regression-proof, not to fix a
 * bypass.
 */
class RoomOperationsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

    private User $admin;
    private User $accountant;
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

        // ACCOUNTANT has none of room.assign / stay.checkin / stay.checkout /
        // checkout_inspection.perform / room.cleaning.update per
        // RolePermissionSeeder — the correct role to prove every action
        // rejects a forged request from someone without it.
        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('ACCOUNTANT');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    /** 1. Unauthorized swap → 403, regardless of can_swap on the frontend. */
    public function test_unauthorized_swap_is_rejected(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room105 = Room::where('room_number', '105')->firstOrFail();
        [, $assignment] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00');

        $this->actingAs($this->accountant)
            ->post(route('admin.room-operations.swap.preview'), [
                'pairs' => [['source_assignment_id' => $assignment->id, 'target_room_id' => $room105->id]],
            ])
            ->assertForbidden();

        $this->actingAs($this->accountant)
            ->post(route('admin.room-operations.swap.execute'), [
                'pairs' => [['source_assignment_id' => $assignment->id, 'target_room_id' => $room105->id]],
            ])
            ->assertForbidden();

        $this->assertSame($room104->id, $assignment->fresh()->room_id, 'A forged swap must not move the assignment.');
    }

    /** 2. Unauthorized check-in → 403. */
    public function test_unauthorized_check_in_is_rejected(): void
    {
        [, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->actingAs($this->accountant)
            ->post(route('admin.room-operations.check-in'), ['stay_ids' => [$stay->id]])
            ->assertForbidden();

        $this->assertNull($stay->fresh()->actual_checkin_at);
    }

    /** 3. Unauthorized checkout → 403. */
    public function test_unauthorized_check_out_is_rejected(): void
    {
        [, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        app(StayService::class)->checkIn($stay);

        $this->actingAs($this->accountant)
            ->post(route('admin.room-operations.check-out'), ['stay_ids' => [$stay->id], 'confirmed' => true])
            ->assertForbidden();

        $this->assertNull($stay->fresh()->actual_checkout_at);
    }

    /** 4. Unauthorized checkout inspection → 403 (draft creation, the entry point every inspection action needs). */
    public function test_unauthorized_inspection_is_rejected(): void
    {
        [, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        app(StayService::class)->checkIn($stay);

        $this->actingAs($this->accountant)
            ->post(route('admin.checkout-inspections.draft', $stay))
            ->assertForbidden();

        $this->assertDatabaseCount('checkout_inspections', 0);
    }

    /** 5. Unauthorized housekeeping (clean) → 403. */
    public function test_unauthorized_cleaning_is_rejected(): void
    {
        $room = Room::where('room_number', '104')->firstOrFail();

        $this->actingAs($this->accountant)
            ->patch(route('admin.housekeeping.mark-clean', $room))
            ->assertForbidden();

        $this->actingAs($this->accountant)
            ->patch(route('admin.housekeeping.mark-dirty', $room))
            ->assertForbidden();
    }

    /**
     * Role matrix (Mục XVI, read/audit — no permission changes made): confirms
     * the 5 action flags for ADMIN/MANAGER/RECEPTION/HOUSEKEEPING against the
     * actual seeded RolePermissionSeeder grants. HOUSEKEEPING having
     * can_inspect=true is EXISTING, intentional behavior (same
     * checkout_inspection.perform grant the standalone Checkout Inspection
     * screen already uses) — not something this closure changes.
     */
    public function test_role_matrix_matches_seeded_permissions(): void
    {
        $matrix = [
            'ADMIN' => ['swap' => true, 'checkIn' => true, 'checkOut' => true, 'inspect' => true, 'clean' => true],
            'MANAGER' => ['swap' => true, 'checkIn' => true, 'checkOut' => true, 'inspect' => true, 'clean' => true],
            'RECEPTION' => ['swap' => true, 'checkIn' => true, 'checkOut' => true, 'inspect' => true, 'clean' => true],
            'HOUSEKEEPING' => ['swap' => false, 'checkIn' => false, 'checkOut' => false, 'inspect' => true, 'clean' => true],
            'ACCOUNTANT' => ['swap' => false, 'checkIn' => false, 'checkOut' => false, 'inspect' => false, 'clean' => false],
        ];

        foreach ($matrix as $roleName => $expected) {
            $user = User::factory()->create();
            $user->assignRole($roleName);

            $this->assertSame($expected['swap'], $user->can('room.assign'), "{$roleName}: room.assign (can_swap)");
            $this->assertSame($expected['checkIn'], $user->can('stay.checkin'), "{$roleName}: stay.checkin (can_check_in)");
            $this->assertSame($expected['checkOut'], $user->can('stay.checkout'), "{$roleName}: stay.checkout (can_check_out)");
            $this->assertSame($expected['inspect'], $user->can('checkout_inspection.perform'), "{$roleName}: checkout_inspection.perform (can_inspect)");
            $this->assertSame($expected['clean'], $user->can('room.cleaning.update'), "{$roleName}: room.cleaning.update (can_clean)");
        }
    }

    /**
     * @return array{0: Booking, 1: RoomAssignment}
     */
    private function reservedAssignment(Room $room, string $startAt, string $endAt, string $email = 'guest@example.test'): array
    {
        $booking = app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Auth Test Guest',
            'customer_phone' => '0900000031',
            'customer_email' => $email,
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => $startAt,
            'checkout_at' => $endAt,
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

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => $startAt, 'end_at' => $endAt],
        ]);
        app(StayService::class)->createStayFromAssignment($assignment);

        return [$booking, $assignment];
    }
}
