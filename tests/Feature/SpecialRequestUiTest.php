<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\RequestCategory;
use App\Enums\RequestStatus;
use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SpecialRequestUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $reception;
    private User $housekeeping;
    private User $accountant;
    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin        = tap(User::factory()->create())->assignRole('ADMIN');
        $this->manager      = tap(User::factory()->create())->assignRole('MANAGER');
        $this->reception    = tap(User::factory()->create())->assignRole('RECEPTION');
        $this->housekeeping = tap(User::factory()->create())->assignRole('HOUSEKEEPING');
        $this->accountant   = tap(User::factory()->create())->assignRole('ACCOUNTANT');

        $this->booking = Booking::factory()->create(['status' => BookingStatus::PendingAssignment]);
    }

    // ─── Booking Detail props ────────────────────────────────────────────────

    public function test_booking_show_includes_special_request_props(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->has('booking.specialRequests')
                ->has('booking.pendingCount')
                ->has('booking.availableStays')
            );
    }

    // ─── Tab visibility ──────────────────────────────────────────────────────

    public function test_special_requests_tab_visible_for_admin(): void
    {
        $this->assertTabVisible($this->admin);
    }

    public function test_special_requests_tab_visible_for_manager(): void
    {
        $this->assertTabVisible($this->manager);
    }

    public function test_special_requests_tab_visible_for_reception(): void
    {
        $this->assertTabVisible($this->reception);
    }

    public function test_special_requests_tab_hidden_for_accountant(): void
    {
        $this->actingAs($this->accountant)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('tabs', fn ($tabs) => collect($tabs)->where('key', 'special_requests')->isEmpty())
            );
    }

    public function test_housekeeping_cannot_access_booking_detail(): void
    {
        // HOUSEKEEPING lacks booking-level perms; BookingPolicy::view() returns false
        $this->actingAs($this->housekeeping)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertForbidden();
    }

    // ─── can flags ───────────────────────────────────────────────────────────

    public function test_admin_gets_all_special_request_permissions(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('can.createSpecialRequest', true)
                ->where('can.fulfillSpecialRequest', true)
                ->where('can.cancelSpecialRequest', true)
            );
    }

    public function test_housekeeping_restricted_mode_via_standalone_page(): void
    {
        // HOUSEKEEPING accesses special requests via the standalone index page
        // (not booking detail — they are blocked by BookingPolicy::view)
        $this->actingAs($this->housekeeping)
            ->get(route('admin.bookings.special-requests.index', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Booking/SpecialRequests')
                ->where('can.create', false)
                ->where('can.fulfill', true)
                ->where('can.cancel', false)
            );
    }

    public function test_reception_cannot_cancel(): void
    {
        $this->actingAs($this->reception)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('can.createSpecialRequest', true)
                ->where('can.cancelSpecialRequest', false)
            );
    }

    public function test_accountant_has_no_special_request_permissions(): void
    {
        $this->actingAs($this->accountant)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('can.createSpecialRequest', false)
                ->where('can.fulfillSpecialRequest', false)
                ->where('can.cancelSpecialRequest', false)
            );
    }

    // ─── specialRequests payload ─────────────────────────────────────────────

    public function test_empty_state_when_no_requests(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('booking.specialRequests', [])
                ->where('booking.pendingCount', 0)
            );
    }

    public function test_pending_count_reflects_active_requests(): void
    {
        BookingSpecialRequest::factory()->create([
            'booking_id'   => $this->booking->id,
            'category'     => RequestCategory::BedConfig,
            'request_type' => 'extra_bed',
            'quantity'      => 1,
            'status'        => RequestStatus::Pending,
        ]);
        BookingSpecialRequest::factory()->create([
            'booking_id'   => $this->booking->id,
            'category'     => RequestCategory::Decoration,
            'request_type' => 'birthday',
            'quantity'      => 1,
            'status'        => RequestStatus::Acknowledged,
        ]);
        BookingSpecialRequest::factory()->create([
            'booking_id'   => $this->booking->id,
            'category'     => RequestCategory::General,
            'request_type' => 'other',
            'quantity'      => 1,
            'status'        => RequestStatus::Fulfilled,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('booking.pendingCount', 2)
                ->has('booking.specialRequests', 3)
            );
    }

    public function test_special_request_payload_shape(): void
    {
        $request = BookingSpecialRequest::factory()->create([
            'booking_id'   => $this->booking->id,
            'category'     => RequestCategory::BedConfig,
            'request_type' => 'extra_bed',
            'quantity'      => 2,
            'note'          => 'Test note',
            'status'        => RequestStatus::Pending,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->has('booking.specialRequests.0', fn (Assert $item) => $item
                    ->where('id', $request->id)
                    ->where('category', 'bed_config')
                    ->where('request_type', 'extra_bed')
                    ->where('quantity', 2)
                    ->where('status', 'pending')
                    ->where('note', 'Test note')
                    ->has('category_label')
                    ->has('requested_by')
                    ->has('created_at')
                    ->etc()
                )
            );
    }

    // ─── Tab redirect ─────────────────────────────────────────────────────────

    public function test_special_requests_tab_param_sets_active_tab(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.bookings.show', ['booking' => $this->booking, 'tab' => 'special_requests']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('activeTab', 'special_requests')
            );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function assertTabVisible(User $user): void
    {
        $this->actingAs($user)
            ->get(route('admin.bookings.show', $this->booking))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Bookings/Show')
                ->where('tabs', fn ($tabs) => collect($tabs)->contains('key', 'special_requests'))
            );
    }
}
