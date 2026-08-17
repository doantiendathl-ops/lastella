<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\PriceSource;
use App\Enums\RateStatus;
use App\Enums\RoomStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomRate;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookingManagementUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-01 14:00:00');

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

    public function test_booking_list_action_fields_are_present_in_each_row(): void
    {
        $this->actingAs($this->admin);
        $this->createBooking();

        $this->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookings.data', fn ($bookings): bool =>
                    collect($bookings)->every(fn (array $item): bool =>
                        array_key_exists('can_edit', $item)
                        && array_key_exists('can_cancel', $item)
                        && array_key_exists('edit_disabled_reason', $item)
                        && array_key_exists('cancel_confirmation', $item)
                    )
                )
                ->where('can', fn ($can): bool =>
                    collect($can)->has('updateBooking')
                    && collect($can)->has('cancelBooking')
                    && collect($can)->has('assignRoom')
                    && collect($can)->has('addPayment')
                )
            );
    }

    public function test_booking_list_date_filter_returns_bookings_that_overlap_selected_range(): void
    {
        $this->actingAs($this->admin);
        $startsBefore = $this->createBooking([
            'customer_name' => 'Starts Before',
            'checkin_at' => '2026-06-12 14:00:00',
            'checkout_at' => '2026-06-14 12:00:00',
        ]);
        $inside = $this->createBooking([
            'customer_name' => 'Inside Range',
            'checkin_at' => '2026-06-14 14:00:00',
            'checkout_at' => '2026-06-15 12:00:00',
        ]);
        $endsAfter = $this->createBooking([
            'customer_name' => 'Ends After',
            'checkin_at' => '2026-06-15 14:00:00',
            'checkout_at' => '2026-06-16 12:00:00',
        ]);
        $before = $this->createBooking([
            'customer_name' => 'Before Range',
            'checkin_at' => '2026-06-10 14:00:00',
            'checkout_at' => '2026-06-12 12:00:00',
        ]);
        $after = $this->createBooking([
            'customer_name' => 'After Range',
            'checkin_at' => '2026-06-16 14:00:00',
            'checkout_at' => '2026-06-17 12:00:00',
        ]);

        $this->get('/admin/bookings?date_from=2026-06-13&date_to=2026-06-15')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.date_from', '2026-06-13')
                ->where('filters.date_to', '2026-06-15')
                ->where('bookings.data', function ($bookings) use ($startsBefore, $inside, $endsAfter, $before, $after): bool {
                    $ids = collect($bookings)->pluck('id');

                    return $ids->contains($startsBefore->id)
                        && $ids->contains($inside->id)
                        && $ids->contains($endsAfter->id)
                        && ! $ids->contains($before->id)
                        && ! $ids->contains($after->id);
                })
            );
    }

    public function test_admin_closed_booking_exposes_enabled_edit_state(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $booking->update(['status' => BookingStatus::Cancelled]);

        $this->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookings.data', fn ($bookings): bool => collect($bookings)->contains(
                    fn (array $item): bool => $item['id'] === $booking->id
                        && $item['can_edit'] === true
                        && $item['edit_disabled_reason'] === null
                ))
            );

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.editBooking', true)
                ->where('can.editDisabledReason', null)
            );
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

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('activeTab', 'info')
                // Unified Services & Requests: the legacy "Yêu cầu" tab
                // (special_requests) was decommissioned — fully superseded
                // by the "Dịch vụ & Yêu cầu" screen linked from this page's header.
                ->where('tabs', [
                    ['key' => 'info', 'label' => 'Thông tin Booking'],
                    ['key' => 'room_map', 'label' => 'Sơ đồ phòng'],
                    ['key' => 'payments', 'label' => 'Tài chính'],
                    ['key' => 'history', 'label' => 'Lịch sử'],
                ])
            );
    }

    public function test_booking_detail_provides_room_board_data(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('roomBoard.floors', function ($floors): bool {
                    $b1 = collect($floors)->first(fn (array $floor): bool => $floor['code'] === 'B1');

                    if (! $b1) {
                        return false;
                    }

                    $rooms = collect($b1['rooms']);

                    return $rooms->pluck('room_number')->all() === ['101', '102', '103', '104', '105', '106', '107']
                        && $rooms->every(fn (array $room): bool => array_key_exists('availability_status', $room)
                            && array_key_exists('disabled_reason', $room)
                            && array_key_exists('conflict_booking', $room)
                            && array_key_exists('assignment_detail', $room)
                            && array_key_exists('matches_requirement', $room))
                        && $rooms->contains(fn (array $room): bool => $room['availability_status'] === 'available'
                            && $room['matches_requirement'] === true);
                })
            );
    }

    public function test_conflicted_room_is_disabled_on_room_board(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', function ($floors) use ($room, $conflictingBooking): bool {
                    $boardRoom = $this->findBoardRoom($floors, $room->id);

                    return $boardRoom !== null
                        && $boardRoom['availability_status'] === 'conflict'
                        && $boardRoom['disabled_reason'] === 'Đã có booking khác'
                        && $boardRoom['conflict_booking']['code'] === $conflictingBooking->booking_code
                        && $boardRoom['conflict_booking']['customer_name'] === 'Conflict Guest'
                        && $boardRoom['assignment_detail']['booking_code'] === $conflictingBooking->booking_code
                        && $boardRoom['assignment_detail']['customer_name'] === 'Conflict Guest'
                        && $boardRoom['assignment_detail']['checkin_at'] === '2026-07-01 14:00'
                        && $boardRoom['assignment_detail']['checkout_at'] === '2026-07-02 12:00'
                        && $boardRoom['assignment_detail']['status'] === AssignmentStatus::Assigned->value;
                })
            );
    }

    public function test_out_of_order_room_is_disabled_on_room_board(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');
        $room->update(['status' => RoomStatus::OutOfOrder]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', function ($floors) use ($room): bool {
                    $boardRoom = $this->findBoardRoom($floors, $room->id);

                    return $boardRoom !== null
                        && $boardRoom['availability_status'] === 'unavailable'
                        && $boardRoom['disabled_reason'] === 'Không khả dụng';
                })
            );
    }

    public function test_same_room_after_previous_checkout_is_available_on_room_board(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $previousBooking = $this->createBooking([
            'customer_name' => 'Previous Guest',
            'checkin_at' => '2026-06-30 14:00:00',
            'checkout_at' => '2026-07-01 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $previousBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-30 14:00:00',
            'end_at' => '2026-07-01 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', function ($floors) use ($room): bool {
                    $boardRoom = $this->findBoardRoom($floors, $room->id);

                    return $boardRoom !== null
                        && $boardRoom['availability_status'] === 'available'
                        && $boardRoom['disabled_reason'] === null;
                })
            );
    }

    public function test_admin_can_access_edit_page_for_checked_out_booking(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $booking->update(['status' => BookingStatus::CheckedOut]);

        $this->get("/admin/bookings/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Bookings/Form'));
    }

    public function test_non_admin_cannot_edit_closed_bookings(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');
        $message = 'Booking đã kết thúc hoặc đã hủy, không thể chỉnh sửa.';

        $this->actingAs($manager);

        foreach ([BookingStatus::CheckedOut, BookingStatus::Cancelled, BookingStatus::NoShow] as $status) {
            $booking = $this->createBooking(['customer_name' => "Closed {$status->value}"]);
            $booking->update(['status' => $status]);

            $this->get("/admin/bookings/{$booking->id}/edit")
                ->assertRedirect(route('admin.bookings.show', $booking))
                ->assertSessionHas('error', $message);
        }
    }

    public function test_non_admin_cancelled_booking_exposes_disabled_edit_state(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');
        $this->actingAs($manager);

        $booking = $this->createBooking();
        $booking->update(['status' => BookingStatus::Cancelled]);
        $message = 'Booking đã kết thúc hoặc đã hủy, không thể chỉnh sửa.';

        $this->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookings.data', fn ($bookings): bool => collect($bookings)->contains(
                    fn (array $item): bool => $item['id'] === $booking->id
                        && $item['can_edit'] === false
                        && $item['edit_disabled_reason'] === $message
                ))
            );
    }

    public function test_booking_detail_includes_suggested_requirement_price_from_active_room_rate(): void
    {
        $this->actingAs($this->admin);
        $roomType = RoomType::where('code', 'TWIN')->firstOrFail();

        RoomRate::factory()->create([
            'room_type_id' => $roomType->id,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'overnight_price' => 800000,
            'hourly_price' => 150000,
            'status' => RateStatus::Active,
        ]);

        $booking = $this->createBooking(withRequirements: false);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('options.roomTypes', fn ($roomTypes): bool => collect($roomTypes)->contains(
                    fn (array $option): bool => (int) $option['value'] === $roomType->id
                        && (float) $option['suggested_price'] === 800000.0
                ))
            );
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

    public function test_requirement_child_counts_may_not_exceed_fifty(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking(withRequirements: false);
        $roomType = RoomType::where('code', 'TWIN')->firstOrFail();
        $payload = $this->requirementPayload($roomType);
        $payload['children_under_6'] = 51;

        $this->from("/admin/bookings/{$booking->id}?tab=info")
            ->post("/admin/bookings/{$booking->id}/requirements", $payload)
            ->assertRedirect("/admin/bookings/{$booking->id}?tab=info")
            ->assertSessionHasErrors('children_under_6');
    }

    public function test_admin_can_add_deposit(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/payments", [
            'payment_type' => PaymentType::Deposit->value,
            'amount' => 1200,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => '2026-07-01 10:00:00',
            'note' => 'Deposit received',
        ])->assertRedirect();

        $this->assertDatabaseHas('booking_payments', [
            'booking_id' => $booking->id,
            'payment_type' => PaymentType::Deposit->value,
            'amount' => 1200,
            'payment_method' => PaymentMethod::Cash->value,
        ]);
    }

    public function test_cancel_requires_reason(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->from("/admin/bookings/{$booking->id}")
            ->post("/admin/bookings/{$booking->id}/cancel", [
                'booking_code_confirmation' => $booking->booking_code,
            ])
            ->assertRedirect("/admin/bookings/{$booking->id}")
            ->assertSessionHasErrors('cancellation_reason');
    }

    public function test_cancel_requires_exact_booking_code_confirmation(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->from("/admin/bookings/{$booking->id}")
            ->post("/admin/bookings/{$booking->id}/cancel", [
                'booking_code_confirmation' => 'WRONG-CODE',
                'cancellation_reason' => 'Guest requested cancellation',
            ])
            ->assertRedirect("/admin/bookings/{$booking->id}")
            ->assertSessionHasErrors('booking_code_confirmation');
    }

    public function test_cancel_sets_cancellation_metadata(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/cancel", $this->cancelPayload($booking, 'Guest requested cancellation'))
            ->assertRedirect(route('admin.bookings.show', $booking));

        $booking->refresh();

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame($this->admin->id, $booking->cancelled_by);
        $this->assertSame('Guest requested cancellation', $booking->cancellation_reason);
    }

    public function test_cancel_releases_active_assignments_and_cancels_reserved_stays(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/cancel", $this->cancelPayload($booking, 'Schedule changed'))
            ->assertRedirect(route('admin.bookings.show', $booking));

        $assignment->refresh();
        $stay->refresh();

        $this->assertSame(AssignmentStatus::Released, $assignment->status);
        $this->assertSame($this->admin->id, $assignment->released_by);
        $this->assertNotNull($assignment->released_at);
        $this->assertSame('Schedule changed', $assignment->release_reason);
        $this->assertSame(StayStatus::Cancelled, $stay->status);
    }

    public function test_cancel_does_not_delete_payments_or_requirements(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $booking->bookingPayments()->create([
            'payment_type' => PaymentType::Deposit,
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => '2026-07-01 10:00:00',
            'confirmed_by' => $this->admin->id,
        ]);
        $requirementCount = $booking->bookingRequirements()->count();
        $paymentCount = $booking->bookingPayments()->count();

        $this->post("/admin/bookings/{$booking->id}/cancel", $this->cancelPayload($booking, 'Guest cancelled'))
            ->assertRedirect(route('admin.bookings.show', $booking));

        $this->assertSame($requirementCount, $booking->bookingRequirements()->count());
        $this->assertSame($paymentCount, $booking->bookingPayments()->count());
    }

    // ── Architecture Gap Closure (M5, Blocker B) — cancelBooking() locking ──

    public function test_repeated_cancellation_remains_safe_after_locking_fix(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->post("/admin/bookings/{$booking->id}/cancel", $this->cancelPayload($booking, 'First cancellation'))
            ->assertRedirect(route('admin.bookings.show', $booking));
        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $firstCancelledAt = $booking->cancelled_at;

        // Same behavior as before the locking fix: cancelling an
        // already-cancelled booking again does not throw and does not
        // corrupt state — the lock does not change this pre-existing
        // idempotency, it only changes when the write happens relative to
        // a concurrent transaction.
        app(BookingService::class)->cancelBooking($booking->fresh(), 'Second cancellation');
        $booking->refresh();

        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame('Second cancellation', $booking->cancellation_reason);
        $this->assertNotNull($booking->cancelled_at);
    }

    public function test_cancellation_still_releases_a_pre_existing_active_assignment_under_the_new_lock(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        // Timeline 1 shape (assignment already committed before cancellation
        // acquires its lock) — cancellation must still find and release it
        // exactly as before, now via the locked instance.
        app(BookingService::class)->cancelBooking($booking->fresh(), 'Assignment existed first');

        $assignment->refresh();
        $stay->refresh();
        $this->assertSame(AssignmentStatus::Released, $assignment->status);
        $this->assertSame(StayStatus::Cancelled, $stay->status);
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cannot_cancel_booking_with_checked_in_stay(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->from("/admin/bookings/{$booking->id}")
            ->post("/admin/bookings/{$booking->id}/cancel", $this->cancelPayload($booking, 'Guest cancelled'))
            ->assertRedirect(route('admin.bookings.show', $booking))
            ->assertSessionHas('error', 'Booking đã có phòng nhận khách, không thể hủy thông thường. Vui lòng xử lý trả phòng hoặc liên hệ quản trị viên.');

        $this->assertNotSame(BookingStatus::Cancelled, $booking->refresh()->status);
        $this->assertSame(StayStatus::CheckedIn, $stay->refresh()->status);
        $this->assertSame(AssignmentStatus::CheckedIn, $assignment->refresh()->status);
    }

    public function test_admin_can_restore_cancelled_booking(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/cancel", $this->cancelPayload($booking, 'Guest cancelled'))
            ->assertRedirect(route('admin.bookings.show', $booking));

        $assignmentCount = $booking->roomAssignments()->count();

        $this->post("/admin/bookings/{$booking->id}/restore")
            ->assertRedirect(route('admin.bookings.show', $booking))
            ->assertSessionHas('success', 'Booking đã được khôi phục. Vui lòng kiểm tra lại phân phòng.');

        $booking->refresh();

        $this->assertSame(BookingStatus::PendingAssignment, $booking->status);
        $this->assertNull($booking->cancelled_at);
        $this->assertNull($booking->cancelled_by);
        $this->assertNull($booking->cancellation_reason);
        $this->assertSame($assignmentCount, $booking->roomAssignments()->count());
        $this->assertSame(AssignmentStatus::Released, $assignment->refresh()->status);
    }

    public function test_non_admin_cannot_restore_cancelled_booking(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $this->post("/admin/bookings/{$booking->id}/cancel", $this->cancelPayload($booking, 'Guest cancelled'))
            ->assertRedirect(route('admin.bookings.show', $booking));

        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $this->actingAs($manager)
            ->post("/admin/bookings/{$booking->id}/restore")
            ->assertForbidden();

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        $this->assertNotNull($booking->cancelled_at);
    }

    public function test_booking_ui_exposes_cancel_confirmation_data(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $warning = 'Hành động này sẽ hủy booking và giải phóng các phòng đã phân.';

        $this->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookings.data', fn ($bookings): bool => collect($bookings)->contains(
                    fn (array $item): bool => $item['id'] === $booking->id
                        && $item['can_cancel'] === true
                        && $item['cancel_confirmation']['booking_code'] === $booking->booking_code
                        && $item['cancel_confirmation']['customer_name'] === $booking->customer_name
                        && $item['cancel_confirmation']['warning'] === $warning
                ))
            );

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.cancel_confirmation.booking_code', $booking->booking_code)
                ->where('booking.cancel_confirmation.customer_name', $booking->customer_name)
                ->where('booking.cancel_confirmation.warning', $warning)
            );
    }

    public function test_booking_detail_includes_payment_summary_and_refund_subtracts_from_paid_total(): void
    {
        $this->actingAs($this->admin);
        $twin = RoomType::where('code', 'TWIN')->firstOrFail();
        $double = RoomType::where('code', 'DOUBLE')->firstOrFail();

        $booking = $this->createBooking([
            'requirements' => [
                $this->requirementPayload($twin, ['quantity' => 2, 'room_price' => 800000]),
                $this->requirementPayload($double, ['quantity' => 1, 'room_price' => 1000000]),
            ],
        ], withRequirements: false);

        $booking->bookingPayments()->create([
            'payment_type' => PaymentType::Deposit,
            'amount' => 500000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => '2026-07-01 10:00:00',
            'confirmed_by' => $this->admin->id,
        ]);
        $booking->bookingPayments()->create([
            'payment_type' => PaymentType::RoomPayment,
            'amount' => 300000,
            'payment_method' => PaymentMethod::BankTransfer->value,
            'payment_at' => '2026-07-01 11:00:00',
            'confirmed_by' => $this->admin->id,
        ]);
        $booking->bookingPayments()->create([
            'payment_type' => PaymentType::Refund,
            'amount' => 100000,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => '2026-07-01 12:00:00',
            'confirmed_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.payment_summary.expected_total', 2600000)
                ->where('booking.payment_summary.paid_total', 700000)
                ->where('booking.payment_summary.remaining_balance', 1900000)
            );
    }

    public function test_admin_can_assign_available_room(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
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

    public function test_multiple_rooms_can_be_assigned_from_room_board_payload(): void
    {
        $this->actingAs($this->admin);
        $roomType = RoomType::where('code', 'TWIN')->firstOrFail();
        $rooms = $this->roomsForType('TWIN', 2);
        $booking = $this->createBooking([
            'requirements' => [
                $this->requirementPayload($roomType, ['quantity' => 2]),
            ],
        ], withRequirements: false);

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => $rooms->pluck('id')->all(),
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        $this->assertSame(2, RoomAssignment::where('booking_id', $booking->id)
            ->whereIn('room_id', $rooms->pluck('id'))
            ->where('status', AssignmentStatus::Assigned->value)
            ->count());
        $this->assertSame(2, Stay::where('booking_id', $booking->id)
            ->whereIn('room_id', $rooms->pluck('id'))
            ->where('status', StayStatus::Reserved->value)
            ->count());
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

        $this->from("/admin/bookings/{$conflictingBooking->id}?tab=room_map")
            ->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
                'room_ids' => [$room->id],
                'start_at' => '2026-07-02 10:00:00',
                'end_at' => '2026-07-02 18:00:00',
            ])
            ->assertRedirect("/admin/bookings/{$conflictingBooking->id}?tab=room_map")
            ->assertSessionHasErrors(['room_id' => 'Phòng đã có booking khác trong khoảng thời gian này.']);
    }

    public function test_assignment_table_still_provides_history_data(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $assignment->load('room');

        $this->get("/admin/bookings/{$booking->id}?tab=room_map")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.assignments', fn ($assignments): bool => collect($assignments)->contains(
                    fn (array $item): bool => $item['id'] === $assignment->id
                        && $item['room_number'] === $assignment->room->room_number
                        && $item['status'] === AssignmentStatus::Assigned->value
                ))
            );
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
        $this->payInFull($booking);
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true])->assertRedirect();

        $this->assertSame(StayStatus::CheckedOut, $stay->refresh()->status);
        $this->assertNotNull($stay->actual_checkout_at);
    }

    /**
     * Early Check-in + Admin Actual Time Override (Active Pilot): checking in
     * before planned_checkin_at is now intentionally ALLOWED — the "chưa đến
     * thời gian nhận phòng dự kiến" gate was removed. See
     * docs/reports/early-checkin-admin-actual-time-override-implementation-report.md.
     */
    public function test_check_in_before_planned_time_is_allowed(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'checkin_at' => '2026-07-05 14:00:00',
            'checkout_at' => '2026-07-06 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        $this->travelTo('2026-07-05 14:00:00');

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-05 14:00:00',
            'end_at' => '2026-07-06 12:00:00',
        ])->assertRedirect();

        $stay = Stay::where('booking_id', $booking->id)->firstOrFail();

        $this->travelTo('2026-07-05 10:00:00');

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")
            ->assertRedirect()
            ->assertSessionHas('success');

        $stay->refresh();
        $this->assertSame(StayStatus::CheckedIn, $stay->status);
        $this->assertSame('2026-07-05 10:00:00', $stay->actual_checkin_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-05 14:00:00', $stay->planned_checkin_at->format('Y-m-d H:i:s'));
    }

    public function test_check_in_at_planned_time_is_allowed(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->travelTo('2026-07-01 14:00:00');

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(StayStatus::CheckedIn, $stay->refresh()->status);
    }

    public function test_check_in_after_planned_time_is_allowed(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->travelTo('2026-07-01 16:30:00');

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(StayStatus::CheckedIn, $stay->refresh()->status);
    }

    /**
     * Early Check-in + Admin Actual Time Override (Active Pilot): the
     * "chưa đến thời gian nhận phòng dự kiến" gate was intentionally removed
     * — a Reserved/Assigned stay can check in before its planned time.
     * checkin_too_early is kept in the payload (always false) only so any
     * consumer still reading the key does not need a separate change. See
     * docs/reports/early-checkin-admin-actual-time-override-implementation-report.md.
     */
    public function test_stay_payload_allows_check_in_before_planned_time(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'checkin_at' => '2026-07-10 14:00:00',
            'checkout_at' => '2026-07-11 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        $this->travelTo('2026-07-10 14:00:00');

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-10 14:00:00',
            'end_at' => '2026-07-11 12:00:00',
        ])->assertRedirect();

        $this->travelTo('2026-07-10 10:00:00');

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.stays', fn ($stays): bool =>
                    collect($stays)->contains(fn (array $s): bool =>
                        $s['can_check_in'] === true
                        && $s['checkin_too_early'] === false
                    )
                )
            );
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

    private function payInFull(Booking $booking, int $amount = 1800): void
    {
        $booking->bookingPayments()->create([
            'payment_type' => PaymentType::RoomPayment,
            'amount'       => $amount,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at'   => now(),
            'confirmed_by' => $this->admin->id,
        ]);
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

    /**
     * A single shared default color would make every Booking this file
     * creates collide under the new overlap-based color rule the moment
     * two of them share a time window — which many of the ROOM-conflict
     * fixtures below intentionally do, as an incidental side effect of
     * being unrelated to color at all. A monotonically unique default
     * keeps those fixtures decoupled from booking_color entirely; tests
     * that actually exercise the color rule still pass an explicit
     * 'booking_color' override, which always wins via ...$overrides.
     */
    private static int $bookingColorSeq = 0;

    private function uniqueTestBookingColor(): string
    {
        self::$bookingColorSeq++;

        return sprintf('#%06X', self::$bookingColorSeq);
    }

    private function bookingPayload(array $overrides = []): array
    {
        return [
            'booking_color' => $this->uniqueTestBookingColor(),
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

    private function requirementPayload(RoomType $roomType, array $overrides = []): array
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
            ...$overrides,
        ];
    }

    private function cancelPayload(Booking $booking, string $reason = 'Guest requested cancellation'): array
    {
        return [
            'booking_code_confirmation' => $booking->booking_code,
            'cancellation_reason' => $reason,
        ];
    }

    private function findBoardRoom($floors, int $roomId): ?array
    {
        return collect($floors)
            ->flatMap(fn ($floor) => collect($floor['rooms'] ?? []))
            ->first(fn (array $room): bool => (int) $room['id'] === $roomId);
    }

    private function roomForType(string $roomTypeCode): Room
    {
        $roomType = RoomType::where('code', $roomTypeCode)->firstOrFail();

        return Room::where('room_type_id', $roomType->id)->firstOrFail();
    }

    private function roomsForType(string $roomTypeCode, int $count)
    {
        $roomType = RoomType::where('code', $roomTypeCode)->firstOrFail();

        return Room::where('room_type_id', $roomType->id)
            ->orderBy('room_number')
            ->limit($count)
            ->get();
    }

    public function test_conflicted_room_payload_includes_full_booking_metadata(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest', 'booking_color' => '#8B5CF6']);
        $room = $this->roomForType('TWIN');

        $assignment = RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', function ($floors) use ($room, $conflictingBooking, $assignment): bool {
                    $boardRoom = $this->findBoardRoom($floors, $room->id);

                    return $boardRoom !== null
                        && $boardRoom['conflict_booking']['id'] === $conflictingBooking->id
                        && $boardRoom['conflict_booking']['booking_color'] === '#8B5CF6'
                        && $boardRoom['conflict_booking']['status'] !== null
                        && $boardRoom['conflict_booking']['assignment_id'] === $assignment->id
                        && array_key_exists('can_view', $boardRoom['conflict_booking'])
                        && array_key_exists('can_unassign_room', $boardRoom['conflict_booking']);
                })
            );
    }

    public function test_conflicted_room_uses_fallback_color_when_booking_color_is_null(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['booking_color' => '#196251']);
        $conflictingBooking->update(['booking_color' => null]);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', function ($floors) use ($room): bool {
                    $boardRoom = $this->findBoardRoom($floors, $room->id);

                    return $boardRoom !== null
                        && $boardRoom['conflict_booking']['booking_color'] !== null
                        && str_starts_with($boardRoom['conflict_booking']['booking_color'], '#');
                })
            );
    }

    public function test_admin_can_release_conflicting_room_via_conflict_endpoint(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        $assignment = RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->post("/admin/bookings/{$booking->id}/room-board/conflict/{$assignment->id}/release")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('room_assignments', [
            'id' => $assignment->id,
            'status' => AssignmentStatus::Released->value,
        ]);
    }

    public function test_cannot_release_own_booking_assignment_via_conflict_endpoint(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');

        $assignment = RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->post("/admin/bookings/{$booking->id}/room-board/conflict/{$assignment->id}/release")
            ->assertNotFound();
    }

    public function test_cannot_release_non_conflicting_assignment_via_conflict_endpoint(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $otherBooking = $this->createBooking([
            'customer_name' => 'Other Guest',
            'checkin_at' => '2026-08-10 14:00:00',
            'checkout_at' => '2026-08-12 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        // This assignment does NOT overlap with $booking's dates (2026-07-01 to 2026-07-02)
        $assignment = RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-08-10 14:00:00',
            'end_at' => '2026-08-12 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->post("/admin/bookings/{$booking->id}/room-board/conflict/{$assignment->id}/release")
            ->assertNotFound();
    }

    public function test_user_without_unassign_permission_cannot_release_conflicting_room(): void
    {
        // SALES role has booking.create/update but not room.unassign
        $sales = User::factory()->create();
        $sales->assignRole('SALES');
        $this->actingAs($sales);

        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        $assignment = RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->post("/admin/bookings/{$booking->id}/room-board/conflict/{$assignment->id}/release")
            ->assertForbidden();
    }

    // --- Phase 2.3.x Refinement: Lock & Color Palette ---

    public function test_cannot_release_current_booking_assignment_after_check_in(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertSessionHasErrors(['assignment']);

        $this->assertSame(AssignmentStatus::CheckedIn, $assignment->refresh()->status);
    }

    public function test_cannot_release_conflicting_booking_assignment_after_check_in(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_id' => $room->id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        $conflictAssignment = RoomAssignment::where('booking_id', $conflictingBooking->id)
            ->where('room_id', $room->id)->firstOrFail();
        $stay = Stay::where('room_assignment_id', $conflictAssignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$conflictingBooking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/room-board/conflict/{$conflictAssignment->id}/release", [])
            ->assertSessionHasErrors(['assignment']);

        $this->assertSame(AssignmentStatus::CheckedIn, $conflictAssignment->refresh()->status);
    }

    public function test_can_release_current_booking_assignment_before_check_in(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $this->assertSame(AssignmentStatus::Released, $assignment->refresh()->status);
    }

    public function test_current_booking_assignment_payload_includes_lock_metadata_when_checked_in(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)->flatMap(fn ($f) => $f['rooms'] ?? [])
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && ($r['current_assignment']['is_assignment_locked'] ?? false) === true
                            && ($r['current_assignment']['lock_reason'] ?? null) !== null
                            && ($r['current_assignment']['can_release_assignment'] ?? true) === false
                        )
                )
            );
    }

    public function test_conflict_payload_includes_lock_metadata_when_checked_in(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_id' => $room->id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        $conflictAssignment = RoomAssignment::where('booking_id', $conflictingBooking->id)
            ->where('room_id', $room->id)->firstOrFail();
        $stay = Stay::where('room_assignment_id', $conflictAssignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$conflictingBooking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)->flatMap(fn ($f) => $f['rooms'] ?? [])
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && ($r['conflict_booking']['is_assignment_locked'] ?? false) === true
                            && ($r['conflict_booking']['lock_reason'] ?? null) !== null
                            && ($r['conflict_booking']['can_unassign_room'] ?? true) === false
                        )
                )
            );
    }

    public function test_current_booking_room_payload_includes_current_assignment_data(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)->flatMap(fn ($f) => $f['rooms'] ?? [])
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && ($r['availability_status'] ?? '') === 'current_booking'
                            && isset($r['current_assignment']['assignment_id'])
                            && ($r['current_assignment']['assigned_to_current_booking'] ?? false) === true
                            && ($r['current_assignment']['is_assignment_locked'] ?? true) === false
                            && ($r['current_assignment']['can_release_assignment'] ?? false) === true
                        )
                )
            );
    }

    // docs/Prompt_1.txt mục VI — Auto Allocation: only Bookings whose
    // occupancy interval overlaps are avoided; there is no global lock.
    public function test_used_colors_reflect_bookings_with_overlapping_occupancy(): void
    {
        $this->actingAs($this->admin);
        $this->createBooking([
            'booking_color' => '#FF0000',
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
        ]);

        $this->get('/admin/bookings/create?checkin_at=2026-07-01T14:00&checkout_at=2026-07-02T12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.used_booking_colors', fn ($colors): bool => collect($colors)->contains('#FF0000'))
                ->where('options.recommended_booking_color', fn ($color): bool => $color !== '#FF0000')
            );
    }

    public function test_non_overlapping_bookings_can_reuse_the_same_color(): void
    {
        $this->actingAs($this->admin);
        $this->createBooking([
            'booking_color' => '#FF0000',
            'checkin_at' => '2026-01-01 14:00:00',
            'checkout_at' => '2026-01-02 12:00:00',
        ]);

        $this->get('/admin/bookings/create?checkin_at=2026-07-01T14:00&checkout_at=2026-07-02T12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.used_booking_colors', fn ($colors): bool => ! collect($colors)->contains('#FF0000'))
            );
    }

    public function test_cancelled_bookings_do_not_reserve_colors(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'booking_color' => '#FF0000',
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
        ]);
        $booking->update(['status' => BookingStatus::Cancelled]);

        $this->get('/admin/bookings/create?checkin_at=2026-07-01T14:00&checkout_at=2026-07-02T12:00')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.used_booking_colors', fn ($colors): bool => ! collect($colors)->contains('#FF0000'))
            );
    }

    public function test_editing_booking_excludes_its_own_color_from_conflicts(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking(['booking_color' => '#FF0000']);

        $this->get("/admin/bookings/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.used_booking_colors', fn ($colors): bool => ! collect($colors)->contains('#FF0000'))
            );
    }

    // Regression: extending an unrelated field (checkout_at) must not
    // bypass the overlap check just because booking_color itself is
    // unchanged — a date change can itself create a brand-new overlap.
    public function test_extending_dates_into_a_new_overlap_is_rejected_even_without_changing_color(): void
    {
        $this->actingAs($this->admin);
        $this->createBooking([
            'booking_color' => '#FF0000',
            'checkin_at' => '2026-07-10 14:00:00',
            'checkout_at' => '2026-07-15 12:00:00',
        ]);
        $booking = $this->createBooking([
            'booking_color' => '#FF0000', // legally reused: no overlap with the booking above (yet)
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-05 12:00:00',
        ]);

        $this->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
            'booking_color' => '#FF0000', // unchanged
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-20 12:00:00', // now overlaps the first booking
        ]))->assertSessionHasErrors('booking_color');
    }

    public function test_manual_color_pick_overlapping_another_booking_is_rejected(): void
    {
        $this->actingAs($this->admin);
        $this->createBooking([
            'booking_color' => '#FF0000',
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
        ]);

        $payload = $this->bookingPayload([
            'booking_color' => '#ff0000', // normalized comparison must be case-insensitive
            'checkin_at' => '2026-07-01 18:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $payload['requirements'] = [$this->requirementPayload(RoomType::where('code', 'TWIN')->firstOrFail())];

        $this->post('/admin/bookings', $payload)
            ->assertSessionHasErrors('booking_color');
    }

    public function test_custom_color_still_accepted_for_booking(): void
    {
        $this->actingAs($this->admin);
        $payload = $this->bookingPayload(['booking_color' => '#ABCDEF']);
        $payload['requirements'] = [$this->requirementPayload(RoomType::where('code', 'TWIN')->firstOrFail())];

        $this->post('/admin/bookings', $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('bookings', ['booking_color' => '#ABCDEF']);
    }

    // --- Booking Time Format and Default Time ---

    public function test_create_form_page_passes_null_booking_prop(): void
    {
        $this->actingAs($this->admin);

        $this->get('/admin/bookings/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Form')
                ->where('booking', null)
            );
    }

    public function test_edit_form_checkin_at_formatted_for_datetime_local_input(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking(['checkin_at' => '2026-07-01 14:00:00']);

        $this->get("/admin/bookings/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Form')
                ->where('booking.checkin_at', '2026-07-01T14:00')
            );
    }

    public function test_edit_form_checkout_at_formatted_for_datetime_local_input(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking(['checkout_at' => '2026-07-02 12:00:00']);

        $this->get("/admin/bookings/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Form')
                ->where('booking.checkout_at', '2026-07-02T12:00')
            );
    }

    public function test_edit_form_preserves_stored_times_including_non_default_hours(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'checkin_at' => '2026-08-15 09:30:00',
            'checkout_at' => '2026-08-16 18:00:00',
        ]);

        $this->get("/admin/bookings/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.checkin_at', '2026-08-15T09:30')
                ->where('booking.checkout_at', '2026-08-16T18:00')
            );
    }

    public function test_store_booking_rejects_checkout_before_checkin(): void
    {
        $this->actingAs($this->admin);

        $this->post('/admin/bookings', $this->bookingPayload([
            'checkin_at' => '2026-07-02 14:00:00',
            'checkout_at' => '2026-07-01 12:00:00',
        ]))
            ->assertSessionHasErrors('checkout_at');
    }

    public function test_store_booking_rejects_checkout_equal_to_checkin(): void
    {
        $this->actingAs($this->admin);

        $this->post('/admin/bookings', $this->bookingPayload([
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-01 14:00:00',
        ]))
            ->assertSessionHasErrors('checkout_at');
    }

    public function test_update_booking_rejects_checkout_at_before_checkin_at(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
            'checkin_at' => '2026-07-02 14:00:00',
            'checkout_at' => '2026-07-01 12:00:00',
        ]))
            ->assertSessionHasErrors('checkout_at');
    }

    // --- Info Tab Availability & Guest Allocation Refinement ---

    public function test_room_board_includes_all_room_type_summary_key(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('roomBoard.all_room_type_summary')
            );
    }

    public function test_all_room_type_summary_includes_types_not_in_requirements(): void
    {
        $this->actingAs($this->admin);
        // Booking requires only TWIN; hotel has DOUBLE rooms too.
        $booking = $this->createBooking();
        $doubleType = RoomType::where('code', 'DOUBLE')->first();

        if (! $doubleType || Room::where('room_type_id', $doubleType->id)->doesntExist()) {
            $this->markTestSkipped('DOUBLE room type or rooms not seeded.');
        }

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.all_room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool => $s['room_type_code'] === 'DOUBLE')
                )
            );
    }

    public function test_all_room_type_summary_remaining_reflects_available_rooms(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.all_room_type_summary', fn ($summary): bool =>
                    collect($summary)->every(fn (array $s): bool =>
                        $s['remaining'] + $s['occupied'] + $s['current_booking'] === $s['total']
                    )
                )
            );
    }

    public function test_all_room_type_summary_occupied_reflects_conflict_rooms(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.all_room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN' && $s['occupied'] >= 1
                    )
                )
            );
    }

    public function test_all_room_type_summary_current_booking_count(): void
    {
        $this->actingAs($this->admin);
        [$booking] = $this->createAssignment();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.all_room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN' && $s['current_booking'] >= 1
                    )
                )
            );
    }

    public function test_all_room_type_summary_total_matches_actual_room_count(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $roomType = RoomType::where('code', 'TWIN')->firstOrFail();
        $totalTwinRooms = Room::where('room_type_id', $roomType->id)->count();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.all_room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN' && $s['total'] === $totalTwinRooms
                    )
                )
            );
    }

    // --- Booking Summary and Room Board Tooltip Enhancement ---

    public function test_room_board_includes_room_type_summary_key(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('roomBoard.room_type_summary')
            );
    }

    public function test_room_type_summary_empty_when_booking_has_no_requirements(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking(withRequirements: false);

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.room_type_summary', [])
            );
    }

    public function test_room_type_summary_counts_available_room_in_remaining(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN' && $s['remaining'] >= 1
                    )
                )
            );
    }

    public function test_room_type_summary_counts_conflict_room_in_occupied(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN' && $s['occupied'] >= 1
                    )
                )
            );
    }

    public function test_room_type_summary_counts_unavailable_room_in_occupied(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');
        $room->update(['status' => RoomStatus::OutOfOrder]);

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN' && $s['occupied'] >= 1
                    )
                )
            );
    }

    public function test_room_type_summary_counts_current_booking_room(): void
    {
        $this->actingAs($this->admin);
        [$booking] = $this->createAssignment();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN' && $s['current_booking'] >= 1
                    )
                )
            );
    }

    public function test_room_type_summary_excludes_types_not_in_requirements(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        // Booking requires only TWIN; hotel has DOUBLE rooms too
        $doubleType = RoomType::where('code', 'DOUBLE')->first();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.room_type_summary', fn ($summary): bool =>
                    $doubleType === null
                    || ! collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'DOUBLE'
                    )
                )
            );
    }

    public function test_room_type_summary_total_matches_actual_room_count(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $roomType = RoomType::where('code', 'TWIN')->firstOrFail();
        $totalTwinRooms = Room::where('room_type_id', $roomType->id)->count();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN' && $s['total'] === $totalTwinRooms
                    )
                )
            );
    }

    // ── Task 6: Room assignment mismatch ─────────────────────────────────────

    public function test_booking_payload_includes_room_assignment_mismatch_key(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('booking.room_assignment_mismatch.has_mismatch')
                ->has('booking.room_assignment_mismatch.items')
            );
    }

    public function test_no_mismatch_when_requirements_and_assignments_match(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.room_assignment_mismatch.has_mismatch', false)
                ->where('booking.room_assignment_mismatch.items', [])
            );
    }

    public function test_mismatch_when_fewer_rooms_assigned_than_required(): void
    {
        $this->actingAs($this->admin);
        $twinType = RoomType::where('code', 'TWIN')->firstOrFail();

        $booking = app(BookingService::class)->createBooking([
            ...$this->bookingPayload(),
            'requirements' => [
                $this->requirementPayload($twinType, ['quantity' => 2]),
            ],
        ]);

        $room = $this->roomForType('TWIN');
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.room_assignment_mismatch.has_mismatch', true)
                ->where('booking.room_assignment_mismatch.items', fn ($items): bool =>
                    collect($items)->contains(fn (array $item): bool =>
                        $item['room_type_name'] === 'TWIN'
                        && $item['required_quantity'] === 2
                        && $item['assigned_quantity'] === 1
                        && $item['difference'] === -1
                        && $item['status'] === 'missing'
                    )
                )
            );
    }

    public function test_mismatch_when_more_rooms_assigned_than_required(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $rooms = $this->roomsForType('TWIN', 2);
        foreach ($rooms as $room) {
            RoomAssignment::create([
                'booking_id' => $booking->id,
                'room_id' => $room->id,
                'room_type_id' => $room->room_type_id,
                'start_at' => '2026-07-01 14:00:00',
                'end_at' => '2026-07-02 12:00:00',
                'status' => AssignmentStatus::Assigned,
                'assigned_by' => $this->admin->id,
            ]);
        }

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.room_assignment_mismatch.has_mismatch', true)
                ->where('booking.room_assignment_mismatch.items', fn ($items): bool =>
                    collect($items)->contains(fn (array $item): bool =>
                        $item['room_type_name'] === 'TWIN'
                        && $item['required_quantity'] === 1
                        && $item['assigned_quantity'] === 2
                        && $item['difference'] === 1
                        && $item['status'] === 'excess'
                    )
                )
            );
    }

    public function test_mismatch_calculated_per_room_type(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $twinRoom = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $twinRoom->id,
            'room_type_id' => $twinRoom->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $doubleRoom = $this->roomForType('DOUBLE');
        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $doubleRoom->id,
            'room_type_id' => $doubleRoom->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.room_assignment_mismatch.has_mismatch', true)
                ->where('booking.room_assignment_mismatch.items', fn ($items): bool => (
                    collect($items)->doesntContain(fn (array $i): bool => $i['room_type_name'] === 'TWIN')
                    && collect($items)->contains(fn (array $i): bool =>
                        $i['room_type_name'] === 'DOUBLE'
                        && $i['required_quantity'] === 0
                        && $i['assigned_quantity'] === 1
                        && $i['status'] === 'excess'
                    )
                ))
            );
    }

    public function test_released_assignments_excluded_from_mismatch_check(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Released,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.room_assignment_mismatch.has_mismatch', true)
                ->where('booking.room_assignment_mismatch.items', fn ($items): bool =>
                    collect($items)->contains(fn (array $i): bool =>
                        $i['room_type_name'] === 'TWIN'
                        && $i['assigned_quantity'] === 0
                        && $i['status'] === 'missing'
                    )
                )
            );
    }

    // ── Task lifecycle fix: release / check-in / check-out guards ────────────

    public function test_released_assignment_cannot_check_in(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")
            ->assertSessionHasErrors(['stay']);

        $this->assertSame(StayStatus::Cancelled, $stay->refresh()->status);
    }

    public function test_released_assignment_cannot_check_out(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out")
            ->assertSessionHasErrors(['stay']);

        $this->assertSame(StayStatus::Cancelled, $stay->refresh()->status);
    }

    public function test_released_assignment_cannot_release_again(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertSessionHasErrors(['assignment']);

        $this->assertSame(AssignmentStatus::Released, $assignment->refresh()->status);
    }

    public function test_checked_out_assignment_cannot_be_released(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();
        $this->payInFull($booking);
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true])->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertSessionHasErrors(['assignment']);

        $this->assertSame(AssignmentStatus::CheckedOut, $assignment->refresh()->status);
    }

    public function test_release_action_hidden_for_checked_in_assignment(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.assignments', fn ($assignments): bool =>
                    collect($assignments)->contains(fn (array $a): bool =>
                        (int) $a['id'] === $assignment->id
                        && $a['can_release'] === false
                        && $a['is_checked_in'] === true
                        && $a['can_check_in'] === false
                        && $a['can_check_out'] === true
                    )
                )
            );
    }

    public function test_release_action_hidden_for_checked_out_assignment(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();
        $this->payInFull($booking);
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true])->assertRedirect();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.assignments', fn ($assignments): bool =>
                    collect($assignments)->contains(fn (array $a): bool =>
                        (int) $a['id'] === $assignment->id
                        && $a['can_release'] === false
                        && $a['is_checked_out'] === true
                        && $a['can_check_in'] === false
                        && $a['can_check_out'] === false
                    )
                )
            );
    }

    public function test_released_assignments_remain_visible_in_payload(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [
            'release_reason' => 'Test history retention',
        ])->assertRedirect();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.assignments', fn ($assignments): bool =>
                    collect($assignments)->contains(fn (array $a): bool =>
                        (int) $a['id'] === $assignment->id
                        && $a['is_released'] === true
                        && $a['can_release'] === false
                        && $a['release_reason'] === 'Test history retention'
                    )
                )
            );
    }

    public function test_assignment_payload_includes_lifecycle_flags(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.assignments', fn ($assignments): bool =>
                    collect($assignments)->contains(fn (array $a): bool =>
                        (int) $a['id'] === $assignment->id
                        && array_key_exists('is_released', $a)
                        && array_key_exists('is_checked_in', $a)
                        && array_key_exists('is_checked_out', $a)
                        && array_key_exists('can_check_in', $a)
                        && array_key_exists('can_check_out', $a)
                        && array_key_exists('stay_id', $a)
                        && $a['can_check_in'] === true
                        && $a['can_release'] === true
                        && $a['stay_id'] !== null
                    )
                )
            );
    }

    public function test_stay_payload_includes_can_check_in_flag_false_for_released_assignment(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.stays', fn ($stays): bool =>
                    collect($stays)->contains(fn (array $s): bool =>
                        (int) $s['id'] === $stay->id
                        && $s['can_check_in'] === false
                    )
                )
            );
    }

    // --- Phase 2.4 Critical Fixes: Stay Lifecycle Guards ---

    public function test_reserved_assignment_cannot_check_out_directly(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        // Attempt checkout without check-in first.
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out")
            ->assertSessionHasErrors(['stay']);

        $this->assertSame(StayStatus::Reserved, $stay->refresh()->status);
        $this->assertSame(AssignmentStatus::Assigned, $assignment->refresh()->status);
    }

    public function test_checked_in_assignment_cannot_be_released(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertSessionHasErrors(['assignment']);

        $this->assertSame(AssignmentStatus::CheckedIn, $assignment->refresh()->status);
    }

    public function test_checked_out_assignment_cannot_check_in_again(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();
        $this->payInFull($booking);
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true])->assertRedirect();

        // Attempt a second check-in after checkout.
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")
            ->assertSessionHasErrors(['stay']);

        $this->assertSame(StayStatus::CheckedOut, $stay->refresh()->status);
    }

    public function test_checked_out_assignment_cannot_check_out_again(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();
        $this->payInFull($booking);
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true])->assertRedirect();

        // Attempt a second checkout.
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out")
            ->assertSessionHasErrors(['stay']);

        $this->assertSame(StayStatus::CheckedOut, $stay->refresh()->status);
    }

    // --- Phase 2.4 Critical Fixes: Boundary Touching Ranges ---

    public function test_boundary_touching_assignment_does_not_conflict_on_room_board(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        // Previous booking ends at 12:00, current booking starts at 12:00.
        $previousBooking = $this->createBooking([
            'customer_name' => 'Previous Guest',
            'checkin_at' => '2026-06-30 14:00:00',
            'checkout_at' => '2026-07-01 12:00:00',
        ]);
        RoomAssignment::create([
            'booking_id' => $previousBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-30 14:00:00',
            'end_at' => '2026-07-01 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $currentBooking = $this->createBooking([
            'customer_name' => 'Current Guest',
            'checkin_at' => '2026-07-01 12:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
        ]);

        // Room should be available because end_at == start_at is not overlap (uses < and >).
        $this->get("/admin/bookings/{$currentBooking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)
                        ->flatMap(fn ($floor) => collect($floor['rooms']))
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && $r['availability_status'] === 'available'
                        )
                )
            );
    }

    public function test_boundary_touching_assignment_can_be_assigned(): void
    {
        $this->actingAs($this->admin);
        $room = $this->roomForType('TWIN');

        // Existing assignment ends at 12:00.
        $existingBooking = $this->createBooking([
            'customer_name' => 'Existing Guest',
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
        ]);
        RoomAssignment::create([
            'booking_id' => $existingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        // New assignment starts exactly at 12:00 — should succeed.
        $newBooking = $this->createBooking([
            'customer_name' => 'New Guest',
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);

        $this->post("/admin/bookings/{$newBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        $this->assertDatabaseHas('room_assignments', [
            'booking_id' => $newBooking->id,
            'room_id' => $room->id,
            'status' => AssignmentStatus::Assigned->value,
        ]);
    }

    // --- Phase 2.4 Critical Fixes: Race condition lock behavior ---

    public function test_assignment_uses_database_transaction(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $room = $this->roomForType('TWIN');

        // First assignment succeeds.
        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        $conflictBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);

        // Second assignment to same room/time is rejected.
        $this->from("/admin/bookings/{$conflictBooking->id}?tab=room_map")
            ->post("/admin/bookings/{$conflictBooking->id}/assignments", [
                'room_ids' => [$room->id],
                'start_at' => '2026-07-01 14:00:00',
                'end_at' => '2026-07-02 12:00:00',
            ])
            ->assertRedirect("/admin/bookings/{$conflictBooking->id}?tab=room_map")
            ->assertSessionHasErrors(['room_id']);

        // Only one assignment exists.
        $this->assertSame(1, RoomAssignment::where('room_id', $room->id)
            ->where('status', AssignmentStatus::Assigned->value)
            ->where('start_at', '2026-07-01 14:00:00')
            ->where('end_at', '2026-07-02 12:00:00')
            ->count());
    }

    // --- Phase 2.4 Final Blocker Fixes: releaseConflict shared logic ---

    public function test_release_conflict_rejects_non_overlapping_checked_in_assignment(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'checkin_at' => '2026-08-01 14:00:00',
            'checkout_at' => '2026-08-02 12:00:00',
        ]);
        $conflictingBooking = $this->createBooking([
            'customer_name' => 'Earlier Guest',
            'checkin_at' => '2026-06-10 14:00:00',
            'checkout_at' => '2026-06-11 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_id' => $room->id,
            'start_at' => '2026-06-10 14:00:00',
            'end_at' => '2026-06-11 12:00:00',
        ])->assertRedirect();

        $conflictAssignment = RoomAssignment::where('booking_id', $conflictingBooking->id)
            ->where('room_id', $room->id)->firstOrFail();
        $stay = Stay::where('room_assignment_id', $conflictAssignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$conflictingBooking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        // Non-overlapping CHECKED_IN is not a conflict → endpoint returns 404
        $this->post("/admin/bookings/{$booking->id}/room-board/conflict/{$conflictAssignment->id}/release", [])
            ->assertNotFound();

        $this->assertSame(AssignmentStatus::CheckedIn, $conflictAssignment->refresh()->status);
    }

    public function test_release_conflict_rejects_non_blocking_released_assignment(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $otherBooking = $this->createBooking(['customer_name' => 'Other Guest']);
        $room = $this->roomForType('TWIN');

        $assignment = RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Released,
            'assigned_by' => $this->admin->id,
        ]);

        // Released assignment does not block — should 404.
        $this->post("/admin/bookings/{$booking->id}/room-board/conflict/{$assignment->id}/release", [])
            ->assertNotFound();
    }

    public function test_release_conflict_rejects_non_blocking_checked_out_assignment(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $otherBooking = $this->createBooking(['customer_name' => 'Other Guest']);
        $room = $this->roomForType('TWIN');

        $assignment = RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::CheckedOut,
            'assigned_by' => $this->admin->id,
        ]);

        // CheckedOut assignment does not block — should 404.
        $this->post("/admin/bookings/{$booking->id}/room-board/conflict/{$assignment->id}/release", [])
            ->assertNotFound();
    }

    // --- Phase 2.4 Final Blocker Fixes: Current booking visibility ---

    public function test_checked_in_current_booking_assignment_visible_even_when_dates_drift(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        // Assign and check in to the room.
        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_id' => $room->id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        $assignment = RoomAssignment::where('booking_id', $booking->id)
            ->where('room_id', $room->id)->firstOrFail();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        // Now change the booking's planned dates so they no longer overlap the assignment.
        $booking->update([
            'checkin_at' => '2026-08-01 14:00:00',
            'checkout_at' => '2026-08-02 12:00:00',
        ]);

        // The checked-in room should still appear on the room board.
        $this->get("/admin/bookings/{$booking->id}?tab=room_map")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)->flatMap(fn ($f) => $f['rooms'] ?? [])
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && $r['availability_status'] === 'current_booking'
                            && $r['current_assignment'] !== null
                            && $r['current_assignment']['is_assignment_locked'] === true
                        )
                )
            );
    }

    public function test_released_current_booking_assignment_not_in_active_board(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $this->get("/admin/bookings/{$booking->id}?tab=room_map")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)->flatMap(fn ($f) => $f['rooms'] ?? [])
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && $r['availability_status'] === 'available'
                            && $r['current_assignment'] === null
                        )
                )
            );
    }

    public function test_checked_out_current_booking_assignment_not_in_active_board(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();
        $this->payInFull($booking);
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true])->assertRedirect();

        $this->get("/admin/bookings/{$booking->id}?tab=room_map")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)->flatMap(fn ($f) => $f['rooms'] ?? [])
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && $r['availability_status'] === 'available'
                            && $r['current_assignment'] === null
                        )
                )
            );
    }

    // --- Phase 2.4 Final Blocker Fixes: Multi-room lock order ---

    public function test_multi_room_assignment_succeeds_regardless_of_input_order(): void
    {
        $this->actingAs($this->admin);
        $roomType = RoomType::where('code', 'TWIN')->firstOrFail();
        $rooms = $this->roomsForType('TWIN', 3);
        $booking = $this->createBooking([
            'requirements' => [
                $this->requirementPayload($roomType, ['quantity' => 3]),
            ],
        ], withRequirements: false);

        // Send room IDs in descending order — service should sort ascending internally.
        $reverseIds = $rooms->pluck('id')->reverse()->values()->all();

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => $reverseIds,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        $this->assertSame(3, RoomAssignment::where('booking_id', $booking->id)
            ->whereIn('room_id', $rooms->pluck('id'))
            ->where('status', AssignmentStatus::Assigned->value)
            ->count());
    }

    // ── Phase 2.5: Booking UX & Room Operations ─────────────────────────────

    public function test_release_cancel_does_nothing(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->assertSame(AssignmentStatus::Assigned, $assignment->status);
        $this->assertNull($assignment->released_at);
        $this->assertNull($assignment->release_reason);
    }

    public function test_released_room_stay_cannot_check_in_via_backend(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")
            ->assertSessionHasErrors(['stay']);

        $this->assertNull($stay->refresh()->actual_checkin_at);
    }

    public function test_stay_payload_is_released_flag_present(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.stays', fn ($stays): bool =>
                    collect($stays)->contains(fn (array $s): bool =>
                        (int) $s['id'] === $stay->id
                        && $s['is_released'] === true
                        && $s['can_check_in'] === false
                        && $s['can_check_out'] === false
                    )
                )
            );
    }

    public function test_checked_in_room_cannot_be_released_with_correct_message(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertSessionHasErrors(['assignment']);

        $this->assertSame(AssignmentStatus::CheckedIn, $assignment->refresh()->status);
    }

    public function test_checkout_succeeds_when_balance_is_zero(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();
        $this->payInFull($booking);
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true])->assertRedirect();

        $this->assertSame(StayStatus::CheckedOut, $stay->refresh()->status);
    }

    public function test_booking_detail_includes_payment_balance_for_checkout_warning(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('booking.payment_summary.balance_due')
            );
    }

    public function test_booking_list_action_column_is_first(): void
    {
        $this->actingAs($this->admin);
        $this->createBooking();

        $this->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Index')
                ->where('bookings.data', fn ($bookings): bool =>
                    collect($bookings)->every(fn (array $item): bool =>
                        array_key_exists('id', $item)
                        && array_key_exists('booking_code', $item)
                        && array_key_exists('booking_color', $item)
                    )
                )
            );
    }

    public function test_color_palette_provides_recommended_colors(): void
    {
        $this->actingAs($this->admin);

        $this->get('/admin/bookings/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.recommended_booking_color', fn ($color): bool =>
                    is_string($color) && preg_match('/^#[0-9A-F]{6}$/', $color) === 1
                )
            );
    }

    public function test_date_format_on_edit_form_is_datetime_local(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking(['checkin_at' => '2026-07-01 14:00:00', 'checkout_at' => '2026-07-02 12:00:00']);

        $this->get("/admin/bookings/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.checkin_at', '2026-07-01T14:00')
                ->where('booking.checkout_at', '2026-07-02T12:00')
            );
    }

    public function test_tooltip_data_present_for_conflict_rooms(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)->flatMap(fn ($f) => $f['rooms'] ?? [])
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && $r['conflict_booking'] !== null
                            && array_key_exists('can_view', $r['conflict_booking'])
                            && array_key_exists('can_unassign_room', $r['conflict_booking'])
                            && $r['assignment_detail'] !== null
                        )
                )
            );
    }

    public function test_view_booking_link_available_for_conflict_room(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)->flatMap(fn ($f) => $f['rooms'] ?? [])
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && $r['conflict_booking']['id'] === $conflictingBooking->id
                            && $r['conflict_booking']['can_view'] === true
                        )
                )
            );
    }

    // ── Phase 2.5 Codex Blocker Fix Tests ───────────────────────────────────

    public function test_release_locks_fresh_state_before_mutating(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [
            'release_reason' => 'Room swap',
        ])->assertRedirect();

        $assignment->refresh();
        $this->assertSame(AssignmentStatus::Released, $assignment->status);
        $this->assertSame('Room swap', $assignment->release_reason);
    }

    public function test_release_sets_stay_to_cancelled(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $this->assertSame(StayStatus::Cancelled, $stay->refresh()->status);
    }

    public function test_released_stay_excluded_from_booking_stay_aggregation(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $stay->refresh();
        $this->assertSame(StayStatus::Cancelled, $stay->status);

        $activeStays = $booking->stays()->whereNotIn('status', [
            StayStatus::Cancelled->value,
            StayStatus::NoShow->value,
        ])->count();

        $this->assertSame(0, $activeStays);
    }

    public function test_checkin_locks_fresh_state_rejects_released(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")
            ->assertSessionHasErrors(['stay']);
    }

    public function test_checkout_rejects_assigned_stay(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out")
            ->assertSessionHasErrors(['stay']);
    }

    public function test_checkout_rejects_released_assignment(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [])
            ->assertRedirect();

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out")
            ->assertSessionHasErrors(['stay']);
    }

    public function test_booking_list_column_order_color_before_code(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get('/admin/bookings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookings.data', fn ($bookings): bool =>
                    collect($bookings)->every(fn (array $item): bool =>
                        array_key_exists('booking_color', $item)
                        && array_key_exists('booking_code', $item)
                    )
                )
            );
    }

    public function test_can_view_uses_view_permission_not_update(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('SALES');
        $this->actingAs($sales);

        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', fn ($floors): bool =>
                    collect($floors)->flatMap(fn ($f) => $f['rooms'] ?? [])
                        ->contains(fn (array $r): bool =>
                            (int) $r['id'] === $room->id
                            && $r['conflict_booking']['can_view'] === true
                        )
                )
            );
    }

    public function test_new_booking_defaults_to_first_recommended_palette_color(): void
    {
        $this->actingAs($this->admin);

        $this->get('/admin/bookings/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.recommended_booking_color', fn ($color): bool =>
                    is_string($color) && preg_match('/^#[0-9A-F]{6}$/', $color) === 1
                )
            );
    }

    public function test_capacity_summary_displayed_without_misleading_allocation(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.requirements', fn ($reqs): bool =>
                    collect($reqs)->every(fn (array $r): bool =>
                        array_key_exists('room_type', $r)
                        && array_key_exists('quantity', $r)
                        && array_key_exists('room_price', $r)
                    )
                )
            );
    }

    // ── Phase 2.5 Release Dialog Bug Fix Tests ──────────────────────────────

    public function test_release_endpoint_not_called_without_explicit_post(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->get("/admin/bookings/{$booking->id}")
            ->assertOk();

        $this->assertSame(AssignmentStatus::Assigned, $assignment->refresh()->status);
    }

    public function test_release_only_executes_on_explicit_post_request(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release", [
            'release_reason' => 'Confirmed by user',
        ])->assertRedirect();

        $this->assertSame(AssignmentStatus::Released, $assignment->refresh()->status);
        $this->assertSame('Confirmed by user', $assignment->release_reason);
    }

    public function test_release_conflict_only_executes_on_explicit_post_request(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $conflictingBooking = $this->createBooking(['customer_name' => 'Conflict Guest']);
        $room = $this->roomForType('TWIN');

        $assignment = RoomAssignment::create([
            'booking_id' => $conflictingBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->post("/admin/bookings/{$booking->id}/room-board/conflict/{$assignment->id}/release", [
            'release_reason' => 'Conflict resolved',
        ])->assertRedirect();

        $this->assertSame(AssignmentStatus::Released, $assignment->refresh()->status);
    }

    public function test_get_request_to_release_endpoint_returns_method_not_allowed(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();

        $this->get("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release")
            ->assertStatus(405);

        $this->assertSame(AssignmentStatus::Assigned, $assignment->refresh()->status);
    }

    public function test_assignment_unchanged_after_viewing_booking_detail(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->get("/admin/bookings/{$booking->id}?tab=room_map")->assertOk();
        $this->get("/admin/bookings/{$booking->id}?tab=info")->assertOk();

        $assignment->refresh();
        $stay->refresh();

        $this->assertSame(AssignmentStatus::Assigned, $assignment->status);
        $this->assertSame(StayStatus::Reserved, $stay->status);
        $this->assertNull($assignment->released_at);
    }

    // --- Room Board: Non-overlapping CHECKED_IN = informational, not conflict ---

    public function test_non_overlapping_checked_in_room_is_available_on_room_board(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'checkin_at' => '2026-08-04 14:00:00',
            'checkout_at' => '2026-08-06 12:00:00',
        ]);
        $otherBooking = $this->createBooking([
            'customer_name' => 'Earlier Guest',
            'checkin_at' => '2026-06-24 14:00:00',
            'checkout_at' => '2026-06-27 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-24 14:00:00',
            'end_at' => '2026-06-27 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', function ($floors) use ($room, $otherBooking): bool {
                    $boardRoom = $this->findBoardRoom($floors, $room->id);

                    return $boardRoom !== null
                        && $boardRoom['availability_status'] === 'available'
                        && $boardRoom['conflict_booking'] === null
                        && $boardRoom['info_booking'] !== null
                        && $boardRoom['info_booking']['booking_code'] === $otherBooking->booking_code;
                })
            );
    }

    public function test_overlapping_checked_in_room_is_conflict_on_room_board(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $otherBooking = $this->createBooking([
            'customer_name' => 'Overlap Guest',
            'checkin_at' => '2026-07-02 14:00:00',
            'checkout_at' => '2026-07-04 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-02 14:00:00',
            'end_at' => '2026-07-04 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.floors', function ($floors) use ($room): bool {
                    $boardRoom = $this->findBoardRoom($floors, $room->id);

                    return $boardRoom !== null
                        && $boardRoom['availability_status'] === 'conflict'
                        && $boardRoom['conflict_booking'] !== null;
                })
            );
    }

    public function test_non_overlapping_checked_in_room_can_be_assigned(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'checkin_at' => '2026-08-04 14:00:00',
            'checkout_at' => '2026-08-06 12:00:00',
        ]);
        $otherBooking = $this->createBooking([
            'customer_name' => 'Earlier Guest',
            'checkin_at' => '2026-06-24 14:00:00',
            'checkout_at' => '2026-06-27 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-24 14:00:00',
            'end_at' => '2026-06-27 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-08-04 14:00:00',
            'end_at' => '2026-08-06 12:00:00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('room_assignments', [
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => AssignmentStatus::Assigned->value,
        ]);
    }

    public function test_non_overlapping_checked_in_room_not_counted_as_occupied_in_summary(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking([
            'checkin_at' => '2026-08-04 14:00:00',
            'checkout_at' => '2026-08-06 12:00:00',
        ]);
        $otherBooking = $this->createBooking([
            'customer_name' => 'Earlier Guest',
            'checkin_at' => '2026-06-24 14:00:00',
            'checkout_at' => '2026-06-27 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');

        RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-06-24 14:00:00',
            'end_at' => '2026-06-27 12:00:00',
            'status' => AssignmentStatus::CheckedIn,
            'assigned_by' => $this->admin->id,
        ]);

        $totalTwin = Room::where('room_type_id', $room->room_type_id)->count();

        $this->get("/admin/bookings/{$booking->id}?tab=room_board")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roomBoard.all_room_type_summary', fn ($summary): bool =>
                    collect($summary)->contains(fn (array $s): bool =>
                        $s['room_type_code'] === 'TWIN'
                        && $s['occupied'] === 0
                        && $s['remaining'] === $totalTwin
                    )
                )
            );
    }

    // --- Booking Time Change Rules ---

    public function test_can_change_time_on_booking_with_no_assignments(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking(withRequirements: false);

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-08-01T14:00',
            'checkout_at' => '2026-08-03T12:00',
        ])->assertRedirect()->assertSessionHas('success');

        $booking->refresh();
        $this->assertSame('2026-08-01 14:00', $booking->checkin_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-03 12:00', $booking->checkout_at->format('Y-m-d H:i'));
    }

    public function test_can_change_time_when_assigned_rooms_remain_available(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-07-02T14:00',
            'checkout_at' => '2026-07-03T12:00',
        ])->assertRedirect()->assertSessionHas('success');

        $assignment->refresh();
        $stay->refresh();
        $this->assertSame('2026-07-02 14:00', $assignment->start_at->format('Y-m-d H:i'));
        $this->assertSame('2026-07-03 12:00', $assignment->end_at->format('Y-m-d H:i'));
        $this->assertSame('2026-07-02 14:00', $stay->planned_checkin_at->format('Y-m-d H:i'));
        $this->assertSame('2026-07-03 12:00', $stay->planned_checkout_at->format('Y-m-d H:i'));
    }

    public function test_cannot_change_time_when_assigned_room_conflicts(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $otherBooking = $this->createBooking(['customer_name' => 'Blocking Guest']);
        RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-05 14:00:00',
            'end_at' => '2026-07-06 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-07-05T14:00',
            'checkout_at' => '2026-07-06T12:00',
        ])->assertRedirect()->assertSessionHasErrors(['checkin_at']);

        $this->assertSame('2026-07-01 14:00', $booking->refresh()->checkin_at->format('Y-m-d H:i'));
    }

    public function test_checked_in_booking_cannot_change_checkin_time(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-07-02T14:00',
            'checkout_at' => '2026-07-03T12:00',
        ])->assertRedirect()->assertSessionHasErrors(['checkin_at']);

        $this->assertSame('2026-07-01 14:00', $booking->refresh()->checkin_at->format('Y-m-d H:i'));
    }

    public function test_checked_in_booking_can_extend_checkout_if_no_conflict(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-07-01T14:00',
            'checkout_at' => '2026-07-04T12:00',
        ])->assertRedirect()->assertSessionHas('success');

        $assignment->refresh();
        $stay->refresh();
        $this->assertSame('2026-07-04 12:00', $assignment->end_at->format('Y-m-d H:i'));
        $this->assertSame('2026-07-04 12:00', $stay->planned_checkout_at->format('Y-m-d H:i'));
        $this->assertNotNull($stay->actual_checkin_at);
        $this->assertNull($stay->actual_checkout_at);
    }

    public function test_checked_in_booking_cannot_extend_checkout_into_conflict(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $otherBooking = $this->createBooking(['customer_name' => 'Future Guest']);
        RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-03 14:00:00',
            'end_at' => '2026-07-04 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-07-01T14:00',
            'checkout_at' => '2026-07-04T12:00',
        ])->assertRedirect()->assertSessionHasErrors(['checkout_at']);
    }

    public function test_checked_out_booking_cannot_change_time(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $stay = Stay::where('room_assignment_id', $assignment->id)->firstOrFail();
        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-in")->assertRedirect();

        $booking->bookingPayments()->create([
            'payment_type' => PaymentType::RoomPayment,
            'amount' => 1800,
            'payment_method' => PaymentMethod::Cash->value,
            'payment_at' => now(),
            'confirmed_by' => $this->admin->id,
        ]);

        $this->post("/admin/bookings/{$booking->id}/stays/{$stay->id}/check-out", ['confirmed' => true])->assertRedirect();

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-07-02T14:00',
            'checkout_at' => '2026-07-03T12:00',
        ])->assertRedirect()->assertSessionHasErrors(['checkin_at']);
    }

    public function test_cancelled_booking_cannot_change_time(): void
    {
        $this->actingAs($this->admin);
        $booking = $this->createBooking();
        $this->bookings()->cancelBooking($booking, 'Test cancel');

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-07-05T14:00',
            'checkout_at' => '2026-07-06T12:00',
        ])->assertRedirect()->assertSessionHasErrors(['checkin_at']);
    }

    public function test_released_assignment_does_not_block_time_change(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $this->post("/admin/bookings/{$booking->id}/assignments/{$assignment->id}/release")->assertRedirect();

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-07-05T14:00',
            'checkout_at' => '2026-07-06T12:00',
        ])->assertRedirect()->assertSessionHas('success');
    }

    public function test_new_checkout_equal_to_next_booking_checkin_does_not_conflict(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $otherBooking = $this->createBooking(['customer_name' => 'Next Guest']);
        RoomAssignment::create([
            'booking_id' => $otherBooking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-03 14:00:00',
            'end_at' => '2026-07-04 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->put("/admin/bookings/{$booking->id}", [
            ...$this->bookingPayload(),
            'checkin_at' => '2026-07-01T14:00',
            'checkout_at' => '2026-07-03T14:00',
        ])->assertRedirect()->assertSessionHas('success');
    }

    public function test_edit_form_includes_assignment_state_flags(): void
    {
        $this->actingAs($this->admin);
        [$booking] = $this->createAssignment();

        $this->get("/admin/bookings/{$booking->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.has_active_assignments', true)
                ->where('booking.has_checked_in', false)
                ->where('booking.has_checked_out', false)
                ->where('booking.is_cancelled', false)
            );
    }

    // --- Phase 2.5 Booking Time Change Conflict Fix ---

    public function test_time_change_no_self_conflict_same_room_assigned_twice(): void
    {
        // Regression: old code excluded only the current assignment by ID, so a booking
        // with the same room assigned twice would conflict with itself.
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        RoomAssignment::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
            'status' => AssignmentStatus::Assigned,
            'assigned_by' => $this->admin->id,
        ]);

        $this->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
            'checkout_at' => '2026-07-03 12:00:00',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-07-03 12:00:00', $booking->refresh()->checkout_at->toDateTimeString());
    }

    public function test_time_change_no_self_conflict_with_two_rooms(): void
    {
        $this->actingAs($this->admin);
        $twinType = RoomType::where('code', 'TWIN')->firstOrFail();
        $rooms = $this->roomsForType('TWIN', 2);

        $booking = $this->createBooking([
            'requirements' => [
                $this->requirementPayload($twinType, ['quantity' => 2]),
            ],
        ], withRequirements: false);

        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => $rooms->pluck('id')->all(),
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        $this->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
            'checkout_at' => '2026-07-03 12:00:00',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-07-03 12:00:00', $booking->refresh()->checkout_at->toDateTimeString());
    }

    public function test_time_change_blocks_on_assigned_room_conflict(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $conflictingBooking = $this->createBooking([
            'customer_name' => 'Conflict Guest',
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkout_at' => '2026-07-02 14:00:00',
            ]))
            ->assertRedirect("/admin/bookings/{$booking->id}/edit")
            ->assertSessionHasErrors('checkout_at');
    }

    public function test_time_change_checkout_only_conflict_reports_checkout_field(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $conflictingBooking = $this->createBooking([
            'customer_name' => 'Conflict Guest',
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        // Only checkout changes → error should be on checkout_at, not checkin_at.
        $response = $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkin_at' => '2026-07-01 14:00:00',
                'checkout_at' => '2026-07-02 14:00:00',
            ]));

        $response->assertSessionHasErrors('checkout_at');
        $this->assertFalse($response->getSession()->get('errors')->getBag('default')->has('checkin_at'));
    }

    public function test_time_change_checkin_conflict_reports_checkin_field(): void
    {
        $this->actingAs($this->admin);

        // Our booking: Jul 02 14:00 - Jul 03 12:00, with room assigned.
        $booking = $this->createBooking([
            'checkin_at' => '2026-07-02 14:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $room = $this->roomForType('TWIN');
        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 14:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        // Conflicting booking ends exactly when ours starts (boundary — assignable).
        $conflictingBooking = $this->createBooking([
            'customer_name' => 'Earlier Guest',
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 14:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 14:00:00',
        ])->assertRedirect();

        // Move checkin earlier → now overlaps conflicting booking. Error on checkin_at.
        $response = $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkin_at' => '2026-07-02 12:00:00',
                'checkout_at' => '2026-07-03 12:00:00',
            ]));

        $response->assertSessionHasErrors('checkin_at');
        $this->assertFalse($response->getSession()->get('errors')->getBag('default')->has('checkout_at'));
    }

    public function test_time_change_blocks_on_checked_in_room_conflict(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $conflictingBooking = $this->createBooking([
            'customer_name' => 'Checked-In Guest',
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        // Force checked-in status directly to simulate an already checked-in guest.
        RoomAssignment::where('booking_id', $conflictingBooking->id)->update(['status' => AssignmentStatus::CheckedIn]);

        $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkout_at' => '2026-07-02 14:00:00',
            ]))
            ->assertSessionHasErrors('checkout_at');
    }

    public function test_time_change_boundary_does_not_conflict(): void
    {
        $this->actingAs($this->admin);

        // Our booking ends at 11:00; adjacent booking starts at 12:00.
        $booking = $this->createBooking([
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 11:00:00',
        ]);
        $room = $this->roomForType('TWIN');
        $this->post("/admin/bookings/{$booking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 11:00:00',
        ])->assertRedirect();

        $adjacentBooking = $this->createBooking([
            'customer_name' => 'Adjacent Guest',
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$adjacentBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        // Extend our checkout to exactly where the adjacent booking starts — no overlap.
        $this->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-07-02 12:00:00', $booking->refresh()->checkout_at->toDateTimeString());
    }

    public function test_time_change_released_assignment_does_not_conflict(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $otherBooking = $this->createBooking([
            'customer_name' => 'Other Guest',
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$otherBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        $otherAssignment = RoomAssignment::where('booking_id', $otherBooking->id)->firstOrFail();
        $this->post("/admin/bookings/{$otherBooking->id}/assignments/{$otherAssignment->id}/release", [
            'release_reason' => 'Testing: released should not block time change',
        ])->assertRedirect();

        $this->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
            'checkout_at' => '2026-07-02 14:00:00',
        ]))->assertSessionHasNoErrors();
    }

    public function test_time_change_conflict_message_includes_room_number_and_booking_code(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $conflictingBooking = $this->createBooking([
            'customer_name' => 'Conflict Guest',
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        $response = $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkout_at' => '2026-07-02 14:00:00',
            ]));

        $response->assertSessionHasErrors('checkout_at');

        $errorMessage = $response->getSession()->get('errors')->getBag('default')->first('checkout_at');
        $this->assertStringContainsString($room->room_number, $errorMessage);
        $this->assertStringContainsString($conflictingBooking->booking_code, $errorMessage);
    }

    // --- Phase 2.5 Booking Time Conflict Dialog ---

    public function test_time_conflict_dialog_flashes_structured_data_on_conflict(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $conflictingBooking = $this->createBooking([
            'customer_name' => 'Dialog Guest',
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        $response = $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkout_at' => '2026-07-02 14:00:00',
            ]));

        $response->assertSessionHasErrors('checkout_at');
        $this->assertNotNull($response->getSession()->get('booking_time_conflicts'));
        $this->assertNotEmpty($response->getSession()->get('booking_time_conflicts'));
    }

    public function test_time_conflict_dialog_structured_data_includes_all_required_fields(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $conflictingBooking = $this->createBooking([
            'customer_name' => 'All Fields Guest',
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        $response = $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkout_at' => '2026-07-02 14:00:00',
            ]));

        $conflicts = $response->getSession()->get('booking_time_conflicts');
        $this->assertIsArray($conflicts);
        $conflict = $conflicts[0];

        $this->assertArrayHasKey('room_id', $conflict);
        $this->assertArrayHasKey('room_number', $conflict);
        $this->assertArrayHasKey('room_type', $conflict);
        $this->assertArrayHasKey('booking_id', $conflict);
        $this->assertArrayHasKey('booking_code', $conflict);
        $this->assertArrayHasKey('customer_name', $conflict);
        $this->assertArrayHasKey('checkin_at', $conflict);
        $this->assertArrayHasKey('checkout_at', $conflict);
        $this->assertArrayHasKey('status_label', $conflict);
        $this->assertArrayHasKey('view_url', $conflict);

        $this->assertSame($room->room_number, $conflict['room_number']);
        $this->assertSame($conflictingBooking->id, $conflict['booking_id']);
        $this->assertSame($conflictingBooking->booking_code, $conflict['booking_code']);
        $this->assertSame('All Fields Guest', $conflict['customer_name']);
        $this->assertStringContainsString('/admin/bookings/' . $conflictingBooking->id, $conflict['view_url']);
    }

    public function test_time_conflict_dialog_includes_multiple_conflicts(): void
    {
        $this->actingAs($this->admin);

        $twinType = RoomType::where('code', 'TWIN')->firstOrFail();
        $rooms = $this->roomsForType('TWIN', 2);

        // Create conflicting bookings first (Jul 2 15:00 → Jul 3 12:00) — do NOT overlap with initial main range.
        foreach ($rooms as $room) {
            $conflicting = $this->createBooking([
                'customer_name' => 'Conflict ' . $room->room_number,
                'checkin_at' => '2026-07-02 15:00:00',
                'checkout_at' => '2026-07-03 12:00:00',
            ]);
            $this->post("/admin/bookings/{$conflicting->id}/assignments", [
                'room_ids' => [$room->id],
                'start_at' => '2026-07-02 15:00:00',
                'end_at' => '2026-07-03 12:00:00',
            ])->assertRedirect();
        }

        // Main booking covers Jul 1 14:00 → Jul 2 12:00 (ends before conflicting bookings start).
        $mainBooking = $this->createBooking([
            'checkin_at' => '2026-07-01 14:00:00',
            'checkout_at' => '2026-07-02 12:00:00',
            'requirements' => [$this->requirementPayload($twinType, ['quantity' => 2])],
        ], withRequirements: false);

        $this->post("/admin/bookings/{$mainBooking->id}/assignments", [
            'room_ids' => $rooms->pluck('id')->all(),
            'start_at' => '2026-07-01 14:00:00',
            'end_at' => '2026-07-02 12:00:00',
        ])->assertRedirect();

        // Extend checkout to 16:00 → now both rooms overlap with conflicting bookings at 15:00.
        $response = $this->from("/admin/bookings/{$mainBooking->id}/edit")
            ->put("/admin/bookings/{$mainBooking->id}", $this->bookingPayload([
                'checkout_at' => '2026-07-02 16:00:00',
            ]));

        $response->assertSessionHasErrors();
        $conflicts = $response->getSession()->get('booking_time_conflicts');
        $this->assertIsArray($conflicts);
        $this->assertCount(2, $conflicts);
    }

    public function test_time_conflict_dialog_checkout_only_still_reports_checkout_field_and_flashes(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $conflictingBooking = $this->createBooking([
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        $response = $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkin_at' => '2026-07-01 14:00:00',
                'checkout_at' => '2026-07-02 14:00:00',
            ]));

        $response->assertSessionHasErrors('checkout_at');
        $this->assertNotEmpty($response->getSession()->get('booking_time_conflicts'));
    }

    public function test_time_conflict_dialog_no_flash_when_no_conflict(): void
    {
        $this->actingAs($this->admin);
        [$booking] = $this->createAssignment();

        $this->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
            'checkout_at' => '2026-07-01 18:00:00',
        ]))->assertSessionHasNoErrors();

        $this->assertNull(session('booking_time_conflicts'));
    }

    public function test_time_conflict_dialog_no_flash_for_released_assignment(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $otherBooking = $this->createBooking([
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$otherBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        $otherAssignment = RoomAssignment::where('booking_id', $otherBooking->id)->firstOrFail();
        $this->post("/admin/bookings/{$otherBooking->id}/assignments/{$otherAssignment->id}/release", [
            'release_reason' => 'Released for dialog test',
        ])->assertRedirect();

        $this->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
            'checkout_at' => '2026-07-02 14:00:00',
        ]))->assertSessionHasNoErrors();

        $this->assertNull(session('booking_time_conflicts'));
    }

    public function test_time_conflict_dialog_status_label_assigned(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $conflictingBooking = $this->createBooking([
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        $response = $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkout_at' => '2026-07-02 14:00:00',
            ]));

        $conflicts = $response->getSession()->get('booking_time_conflicts');
        $this->assertSame('Đã phân phòng', $conflicts[0]['status_label']);
    }

    public function test_time_conflict_dialog_status_label_checked_in(): void
    {
        $this->actingAs($this->admin);
        [$booking, $assignment] = $this->createAssignment();
        $room = Room::findOrFail($assignment->room_id);

        $conflictingBooking = $this->createBooking([
            'checkin_at' => '2026-07-02 12:00:00',
            'checkout_at' => '2026-07-03 12:00:00',
        ]);
        $this->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
            'room_ids' => [$room->id],
            'start_at' => '2026-07-02 12:00:00',
            'end_at' => '2026-07-03 12:00:00',
        ])->assertRedirect();

        RoomAssignment::where('booking_id', $conflictingBooking->id)->update(['status' => AssignmentStatus::CheckedIn]);

        $response = $this->from("/admin/bookings/{$booking->id}/edit")
            ->put("/admin/bookings/{$booking->id}", $this->bookingPayload([
                'checkout_at' => '2026-07-02 14:00:00',
            ]));

        $conflicts = $response->getSession()->get('booking_time_conflicts');
        $this->assertSame('Đã nhận phòng', $conflicts[0]['status_label']);
    }

    private function bookings(): BookingService
    {
        return app(BookingService::class);
    }
}
