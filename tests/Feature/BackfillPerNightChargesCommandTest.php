<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillPerNightChargesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeCheckedInStay(int $daysBefore = 3): array
    {
        $roomType   = RoomType::factory()->create();
        $room       = Room::factory()->for($roomType)->create();
        $booking    = Booking::factory()->create();
        $folio      = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'status' => AssignmentStatus::CheckedIn,
        ]);

        $checkinAt = now()->subDays($daysBefore)->startOfDay()->addHours(14);

        $stay = Stay::factory()->for($booking)->for($room)->create([
            'room_assignment_id' => $assignment->id,
            'status'             => StayStatus::CheckedIn,
            'actual_checkin_at'  => $checkinAt,
        ]);

        BookingRequirement::factory()->create([
            'booking_id'   => $booking->id,
            'room_type_id' => $roomType->id,
            'room_price'   => 500000,
        ]);

        return [$stay, $folio, $booking];
    }

    public function test_dry_run_reports_counts_without_writing_to_database(): void
    {
        [$stay] = $this->makeCheckedInStay(daysBefore: 3);

        $this->artisan('folio:backfill-per-night-charges', [
            '--dry-run' => true,
            '--date'    => now()->toDateString(),
        ])->assertSuccessful();

        $this->assertDatabaseMissing('folio_entries', [
            'charge_type' => ChargeType::Room->value,
        ]);
    }

    public function test_backfill_posts_missing_nights_for_checked_in_stay(): void
    {
        [$stay, $folio] = $this->makeCheckedInStay(daysBefore: 3);

        $this->artisan('folio:backfill-per-night-charges', [
            '--date' => now()->toDateString(),
        ])->assertSuccessful();

        // 3 days ago (checkin) to yesterday = 3 nights
        $this->assertDatabaseCount('folio_entries', 3);

        $this->assertDatabaseHas('folio_entries', [
            'folio_id'    => $folio->id,
            'charge_type' => ChargeType::Room->value,
        ]);
    }

    public function test_backfill_is_idempotent(): void
    {
        [$stay, $folio] = $this->makeCheckedInStay(daysBefore: 2);

        $this->artisan('folio:backfill-per-night-charges', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->artisan('folio:backfill-per-night-charges', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        // Running twice should not duplicate entries (2 nights)
        $this->assertDatabaseCount('folio_entries', 2);
    }

    public function test_backfill_skips_stays_that_already_have_all_nights_posted(): void
    {
        [$stay, $folio, $booking] = $this->makeCheckedInStay(daysBefore: 1);

        $postingKey = sprintf(
            'ROOM_NIGHT_%d_%s',
            $stay->id,
            now()->subDay()->toDateString(),
        );

        FolioEntry::create([
            'folio_id'       => $folio->id,
            'stay_id'        => $stay->id,
            'posting_key'    => $postingKey,
            'posting_source' => 'NIGHT_AUDIT',
            'charge_type'    => ChargeType::Room,
            'description'    => 'Pre-existing night charge',
            'quantity'       => '1.00',
            'unit_price'     => '500000.00',
            'amount'         => '500000.00',
            'entry_date'     => now()->subDay()->toDateString(),
            'posted_by'      => null,
        ]);

        $this->artisan('folio:backfill-per-night-charges', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        // Still only 1 entry — backfill skipped the already-posted night
        $this->assertDatabaseCount('folio_entries', 1);
    }
}
