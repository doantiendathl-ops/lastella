<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Service;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingServiceEnrollmentService;
use App\Services\BusinessDateService;
use App\Services\Posting\PostingContext;
use App\Services\Posting\UnifiedServicePostingJob;
use Carbon\Carbon;
use Database\Seeders\UnifiedServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — Slice 2: Ăn sáng +
 * Người thêm, migrated onto the exact engine Slice 1 built and proved.
 * These tests run against the REAL seeded catalog (UnifiedServiceSeeder),
 * not synthetic fixtures — proving the actual production-bound
 * configuration behaves correctly, especially the two new variations
 * Slice 1 didn't exercise via real data: quantity_enabled=false (Ăn sáng)
 * and a chargeable Service seeded with NO price yet (Người thêm).
 */
class UnifiedServiceSlice2SeedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->seed(UnifiedServiceSeeder::class);
    }

    public function test_seeder_creates_exactly_the_three_expected_services(): void
    {
        $this->assertDatabaseCount('services', 3);
        $this->assertDatabaseHas('services', ['code' => 'EXTRA_BED_PER_NIGHT', 'scope' => 'ROOM']);
        $this->assertDatabaseHas('services', ['code' => 'BREAKFAST_PER_NIGHT', 'scope' => 'BOOKING', 'quantity_enabled' => false]);
        $this->assertDatabaseHas('services', ['code' => 'EXTRA_PERSON_PER_NIGHT', 'scope' => 'BOOKING', 'quantity_enabled' => true]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(UnifiedServiceSeeder::class);
        $this->seed(UnifiedServiceSeeder::class);

        $this->assertDatabaseCount('services', 3);
        $this->assertDatabaseCount('service_categories', 3);
        $this->assertDatabaseCount('service_prices', 2); // Breakfast + Extra Bed only — Extra Person deliberately has none.
    }

    public function test_breakfast_has_a_real_carried_forward_price_but_extra_person_does_not(): void
    {
        $breakfast = Service::where('code', 'BREAKFAST_PER_NIGHT')->firstOrFail();
        $extraPerson = Service::where('code', 'EXTRA_PERSON_PER_NIGHT')->firstOrFail();

        $this->assertSame('120000.00', (string) $breakfast->currentPrice('2026-08-17')?->unit_price);
        $this->assertNull($extraPerson->currentPrice('2026-08-17'));
    }

    public function test_cannot_enroll_extra_person_until_admin_sets_a_price(): void
    {
        $booking = Booking::factory()->create();
        $extraPerson = Service::where('code', 'EXTRA_PERSON_PER_NIGHT')->firstOrFail();
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn(Carbon::parse('2026-08-17'));
        $enrollment = new BookingServiceEnrollmentService(app(\App\Services\ServicePricingResolver::class), $mock);

        $this->expectException(ValidationException::class);
        $enrollment->enroll($booking, $extraPerson, null, 1, null, null, null, $this->admin);
    }

    public function test_extra_person_becomes_enrollable_once_admin_adds_a_price(): void
    {
        $booking = Booking::factory()->create();
        $extraPerson = Service::where('code', 'EXTRA_PERSON_PER_NIGHT')->firstOrFail();
        $extraPerson->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-08-01', 'is_active' => true]);

        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn(Carbon::parse('2026-08-17'));
        $enrollment = new BookingServiceEnrollmentService(app(\App\Services\ServicePricingResolver::class), $mock);

        $bs = $enrollment->enroll($booking, $extraPerson, null, 2, null, null, null, $this->admin);

        $this->assertSame(2, $bs->quantity);
        $this->assertSame('100000.00', (string) $bs->actual_price);
    }

    private function makeCheckedInStayWithFolio(?Booking $booking = null): Stay
    {
        $stay = Stay::factory()->create(array_filter([
            'status' => StayStatus::CheckedIn,
            'booking_id' => $booking?->id,
        ]));
        $b = $booking ?? Booking::find($stay->booking_id);
        if ($b->folio === null) {
            Folio::factory()->for($b)->create(['status' => FolioStatus::Open]);
        }
        $stay->loadMissing('room');
        BookingRequirement::factory()->create([
            'booking_id' => $b->id,
            'room_type_id' => $stay->room->room_type_id,
            'room_price' => 500000,
        ]);

        return $stay;
    }

    public function test_breakfast_posts_exactly_once_per_night_regardless_of_room_count_and_always_quantity_one(): void
    {
        $stayA = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stayA->booking_id);
        $stayB = $this->makeCheckedInStayWithFolio($booking);
        $stayC = $this->makeCheckedInStayWithFolio($booking);

        $breakfast = Service::where('code', 'BREAKFAST_PER_NIGHT')->firstOrFail();
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn(Carbon::parse('2026-08-17'));
        $enrollment = new BookingServiceEnrollmentService(app(\App\Services\ServicePricingResolver::class), $mock);

        // quantity_enabled=false — even asking for 5, must clamp to 1.
        $enrollment->enroll($booking, $breakfast, null, 5, null, null, null, $this->admin);

        $date = Carbon::parse('2026-08-17');
        $job = app(UnifiedServicePostingJob::class);
        foreach ([$stayA, $stayB, $stayC] as $stay) {
            $job->execute(new PostingContext(booking: $booking, folio: $booking->folio, businessDate: $date, stay: $stay));
        }

        $this->assertDatabaseCount('folio_entries', 1);
        $this->assertDatabaseHas('folio_entries', ['amount' => 120000, 'quantity' => '1.00']);
    }

    public function test_extra_person_quantity_multiplies_and_still_posts_once_per_night(): void
    {
        $stayA = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stayA->booking_id);
        $stayB = $this->makeCheckedInStayWithFolio($booking);

        $extraPerson = Service::where('code', 'EXTRA_PERSON_PER_NIGHT')->firstOrFail();
        $extraPerson->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-08-01', 'is_active' => true]);

        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn(Carbon::parse('2026-08-17'));
        $enrollment = new BookingServiceEnrollmentService(app(\App\Services\ServicePricingResolver::class), $mock);
        $enrollment->enroll($booking, $extraPerson, null, 3, null, null, null, $this->admin);

        $date = Carbon::parse('2026-08-17');
        $job = app(UnifiedServicePostingJob::class);
        foreach ([$stayA, $stayB] as $stay) {
            $job->execute(new PostingContext(booking: $booking, folio: $booking->folio, businessDate: $date, stay: $stay));
        }

        $this->assertDatabaseCount('folio_entries', 1);
        $this->assertDatabaseHas('folio_entries', ['amount' => 300000]);
    }

    public function test_breakfast_and_extra_person_both_enrolled_produce_two_independent_charges_per_night(): void
    {
        $stay = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);

        $breakfast = Service::where('code', 'BREAKFAST_PER_NIGHT')->firstOrFail();
        $extraPerson = Service::where('code', 'EXTRA_PERSON_PER_NIGHT')->firstOrFail();
        $extraPerson->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-08-01', 'is_active' => true]);

        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn(Carbon::parse('2026-08-17'));
        $enrollment = new BookingServiceEnrollmentService(app(\App\Services\ServicePricingResolver::class), $mock);
        $enrollment->enroll($booking, $breakfast, null, 1, null, null, null, $this->admin);
        $enrollment->enroll($booking, $extraPerson, null, 1, null, null, null, $this->admin);

        $date = Carbon::parse('2026-08-17');
        app(UnifiedServicePostingJob::class)->execute(new PostingContext(booking: $booking, folio: $booking->folio, businessDate: $date, stay: $stay));

        $this->assertDatabaseCount('folio_entries', 2);
        $this->assertSame(220000.0, (float) FolioEntry::sum('amount'));
    }
}
