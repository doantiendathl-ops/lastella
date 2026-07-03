<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingPackageFlag;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Stay;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\PackageEnrollmentService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPackageEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $manager;
    private User $reception;
    private Booking $booking;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('MANAGER');

        $this->reception = User::factory()->create();
        $this->reception->assignRole('RECEPTION');

        $this->businessDate = Carbon::parse('2026-07-03');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($this->businessDate));

        $this->booking = Booking::factory()->create();
    }

    private function mockBusinessDate(Carbon $date): BusinessDateService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($date);
        $mock->method('businessDateFor')->willReturn($date);

        return $mock;
    }

    // -------------------------------------------------------------------------
    // Enroll
    // -------------------------------------------------------------------------

    public function test_enroll_creates_booking_package_flag(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);
    }

    public function test_manager_can_enroll_booking_for_breakfast(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);
    }

    public function test_duplicate_enroll_is_idempotent(): void
    {
        BookingPackageFlag::factory()->create([
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('booking_package_flags', 1);
    }

    public function test_receptionist_cannot_enroll(): void
    {
        $this->actingAs($this->reception)
            ->post(route('admin.bookings.packages.enroll', $this->booking), [
                'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id' => $this->booking->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Unenroll
    // -------------------------------------------------------------------------

    public function test_unenroll_removes_booking_package_flag(): void
    {
        BookingPackageFlag::factory()->create([
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.bookings.packages.unenroll', [
                'booking'    => $this->booking,
                'packageKey' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ]))
            ->assertRedirect();

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);
    }

    public function test_receptionist_cannot_unenroll(): void
    {
        BookingPackageFlag::factory()->create([
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);

        $this->actingAs($this->reception)
            ->delete(route('admin.bookings.packages.unenroll', [
                'booking'    => $this->booking,
                'packageKey' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ]))
            ->assertForbidden();

        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);
    }

    // -------------------------------------------------------------------------
    // Unenroll guard: breakfast already posted for current night
    // -------------------------------------------------------------------------

    public function test_unenroll_blocked_when_breakfast_already_posted_for_current_night(): void
    {
        BookingPackageFlag::factory()->create([
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);

        $folio = Folio::factory()->for($this->booking)->create(['status' => FolioStatus::Open]);

        // Simulate a breakfast posting that happened during night audit for today
        FolioEntry::factory()->create([
            'folio_id'       => $folio->id,
            'charge_type'    => ChargeType::FoodBeverage,
            'posting_source' => 'NIGHT_AUDIT',
            'entry_date'     => $this->businessDate->toDateString(),
            'voided_at'      => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.bookings.packages.unenroll', [
                'booking'    => $this->booking,
                'packageKey' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ]));

        $response->assertRedirect();
        $response->assertSessionHasErrors('package');

        // Flag must still exist
        $this->assertDatabaseHas('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);
    }

    public function test_unenroll_allowed_when_breakfast_was_voided(): void
    {
        BookingPackageFlag::factory()->create([
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);

        $folio = Folio::factory()->for($this->booking)->create(['status' => FolioStatus::Open]);

        // Voided entry should NOT block unenroll
        FolioEntry::factory()->create([
            'folio_id'       => $folio->id,
            'charge_type'    => ChargeType::FoodBeverage,
            'posting_source' => 'NIGHT_AUDIT',
            'entry_date'     => $this->businessDate->toDateString(),
            'voided_at'      => now(),
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.bookings.packages.unenroll', [
                'booking'    => $this->booking,
                'packageKey' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('booking_package_flags', [
            'booking_id'  => $this->booking->id,
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
        ]);
    }
}
