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
                ->where('tabs', [
                    ['key' => 'info', 'label' => 'Thông tin Booking'],
                    ['key' => 'room_map', 'label' => 'Sơ đồ phòng'],
                    ['key' => 'payments', 'label' => 'Thanh toán'],
                    ['key' => 'history', 'label' => 'Lịch sử'],
                ])
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

        $this->from("/admin/bookings/{$conflictingBooking->id}?tab=room_map")
            ->post("/admin/bookings/{$conflictingBooking->id}/assignments", [
                'room_id' => $room->id,
                'start_at' => '2026-07-02 10:00:00',
                'end_at' => '2026-07-02 18:00:00',
            ])
            ->assertRedirect("/admin/bookings/{$conflictingBooking->id}?tab=room_map")
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

    private function roomForType(string $roomTypeCode): Room
    {
        $roomType = RoomType::where('code', $roomTypeCode)->firstOrFail();

        return Room::where('room_type_id', $roomType->id)->firstOrFail();
    }
}
