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

class RoomOperationsControllerTest extends TestCase
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

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('admin.room-operations.index'))->assertRedirect(route('login'));
    }

    public function test_index_renders_board_for_admin(): void
    {
        [, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');

        $response = $this->actingAs($this->admin)->get(route('admin.room-operations.index', ['date' => '2026-08-01']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/RoomOperations/Index')
            ->where('board.date', '2026-08-01')
            ->has('board.floors')
            ->has('dailySummary'));
    }

    /** Fast Inspection Popup: same canonical product catalog CheckoutInspectionController::index() already sends. */
    public function test_index_includes_inspection_products_when_user_can_inspect(): void
    {
        $category = \App\Models\ProductServiceCategory::create(['code' => 'MINIBAR', 'name' => 'Minibar', 'sort_order' => 1]);
        \App\Models\ProductService::create([
            'category_id' => $category->id,
            'code' => 'MINIBAR_WATER',
            'name' => 'Nước suối',
            'unit' => 'chai',
            'price' => 20000,
            'free_quantity_default' => 1,
            'is_active' => true,
            'use_in_checkout_inspection' => true,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.room-operations.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('inspectionProducts', 1)
            ->where('inspectionProducts.0.code', 'MINIBAR_WATER'));
    }

    public function test_index_excludes_inspection_products_when_user_cannot_inspect(): void
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ROLE_NO_INSPECT', 'guard_name' => 'web']);
        $role->syncPermissions(['room.assign']);
        $staff = User::factory()->create();
        $staff->assignRole($role);

        $response = $this->actingAs($staff)->get(route('admin.room-operations.index'));

        $response->assertInertia(fn ($page) => $page->where('inspectionProducts', []));
    }

    public function test_index_rejects_user_without_any_operational_permission(): void
    {
        $staff = User::factory()->create();
        // No role assigned ⇒ no permissions at all.

        $this->actingAs($staff)->get(route('admin.room-operations.index'))->assertForbidden();
    }

    public function test_quick_note_update_persists_and_enforces_max_length(): void
    {
        [, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');

        $this->actingAs($this->admin)
            ->patch(route('admin.room-operations.assignments.quick-note', $assignment), ['quick_note' => 'Khách cần phòng yên tĩnh'])
            ->assertRedirect();

        $this->assertSame('Khách cần phòng yên tĩnh', $assignment->fresh()->quick_note);
    }

    public function test_quick_note_update_max_length_validation(): void
    {
        [, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');

        $tooLong = str_repeat('a', 101);
        $this->actingAs($this->admin)
            ->patch(route('admin.room-operations.assignments.quick-note', $assignment), ['quick_note' => $tooLong])
            ->assertSessionHasErrors('quick_note');
    }

    public function test_bulk_check_in_checks_in_eligible_stays(): void
    {
        [, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('admin.room-operations.check-in'), ['stay_ids' => [$stay->id]])
            ->assertRedirect();

        $this->assertNotNull($stay->fresh()->actual_checkin_at);
    }

    public function test_bulk_check_in_rejects_already_checked_in_stay_without_aborting_others(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail();
        $room201 = Room::where('room_number', '201')->firstOrFail();
        [, $assignmentA] = $this->reservedAssignment($room104, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'a@example.test');
        [, $assignmentB] = $this->reservedAssignment($room201, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'b@example.test');
        $stayA = Stay::where('room_assignment_id', $assignmentA->id)->firstOrFail();
        $stayB = Stay::where('room_assignment_id', $assignmentB->id)->firstOrFail();
        app(StayService::class)->checkIn($stayA); // already checked in

        $this->actingAs($this->admin)
            ->post(route('admin.room-operations.check-in'), ['stay_ids' => [$stayA->id, $stayB->id]])
            ->assertRedirect();

        // B (eligible) still gets checked in even though A (already in) fails.
        $this->assertNotNull($stayB->fresh()->actual_checkin_at);
    }

    public function test_bulk_check_out_checks_out_eligible_stays(): void
    {
        [$booking, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        app(StayService::class)->checkIn($stay);
        // Outstanding-balance guard (ADR-40) is reused unchanged — pay in full first.
        app(\App\Services\BookingPaymentService::class)->addDeposit($booking, [
            'amount' => self::ROOM_PRICE,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);

        // Sole stay of the booking ⇒ final checkout confirmation gate applies
        // (ADR-55, reused unchanged) — must pass confirmed=true, same as the
        // existing Booking Detail flow.
        $this->actingAs($this->admin)
            ->post(route('admin.room-operations.check-out'), ['stay_ids' => [$stay->id], 'confirmed' => true])
            ->assertRedirect();

        $this->assertNotNull($stay->fresh()->actual_checkout_at);
    }

    public function test_bulk_check_out_reports_final_checkout_confirmation_required(): void
    {
        [, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        app(StayService::class)->checkIn($stay);

        $this->actingAs($this->admin)
            ->post(route('admin.room-operations.check-out'), ['stay_ids' => [$stay->id]])
            ->assertRedirect()
            ->assertSessionHas('final_checkout_confirmation_required', [$stay->id]);

        $this->assertNull($stay->fresh()->actual_checkout_at);
    }

    public function test_quick_note_update_requires_room_assign_permission(): void
    {
        $receptionRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'RECEPTION_NO_ASSIGN', 'guard_name' => 'web']);
        $staff = User::factory()->create();
        $staff->assignRole($receptionRole);

        [, $assignment] = $this->reservedAssignment(Room::where('room_number', '104')->firstOrFail(), '2026-08-01 14:00:00', '2026-08-02 12:00:00');

        $this->actingAs($staff)
            ->patch(route('admin.room-operations.assignments.quick-note', $assignment), ['quick_note' => 'x'])
            ->assertForbidden();
    }

    /**
     * @return array{0: Booking, 1: RoomAssignment}
     */
    private function reservedAssignment(Room $room, string $startAt, string $endAt, string $email = 'guest@example.test'): array
    {
        $booking = app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Room Ops Guest',
            'customer_phone' => '0900000030',
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

    /**
     * Fast Inspection Popup & Compact Room Card UX Mục X-XII: the board's
     * primary-status classification (used for the full-card tint) covers
     * exactly the 5 documented states — same classification as before, only
     * the rendering changed from a hex border color to a semantic key.
     */
    public function test_status_theme_reflects_each_primary_state(): void
    {
        $room104 = Room::where('room_number', '104')->firstOrFail(); // vacant, clean by seed default
        $room105 = Room::where('room_number', '105')->firstOrFail();
        $room106 = Room::where('room_number', '106')->firstOrFail();
        $room101 = Room::where('room_number', '101')->firstOrFail(); // seeded OutOfOrder

        $room105->update(['status' => \App\Enums\RoomStatus::VacantDirty, 'cleaning_status' => 'DIRTY']);

        [, $assignedAssignment] = $this->reservedAssignment($room106, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'assigned@example.test');

        $room107 = Room::where('room_number', '107')->firstOrFail();
        [, $checkedInAssignment] = $this->reservedAssignment($room107, '2026-08-01 14:00:00', '2026-08-02 12:00:00', 'checkedin@example.test');
        $stay = Stay::where('room_assignment_id', $checkedInAssignment->id)->firstOrFail();
        app(StayService::class)->checkIn($stay);

        $board = app(\App\Services\RoomOperationsBoardService::class)->boardForDate('2026-08-01', $this->admin);

        $this->assertSame('vacant_clean', $this->findRoom($board, '104')['status_theme']);
        $this->assertSame('vacant_dirty', $this->findRoom($board, '105')['status_theme']);
        $this->assertSame('unavailable', $this->findRoom($board, '101')['status_theme']);
        $this->assertSame('assigned', $this->findRoom($board, '106')['status_theme']);
        $this->assertSame('checked_in', $this->findRoom($board, '107')['status_theme']);
    }

    private function findRoom(array $board, string $roomNumber): array
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
}
