<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Enums\RateStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomRate;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\Posting\EarlyCheckinFeePostingJob;
use App\Services\Posting\PostingContext;
use App\Services\StayService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EarlyCheckinFeeTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $businessDate;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->staff = User::factory()->create();
        $this->staff->assignRole('RECEPTION');

        $this->businessDate = Carbon::parse('2026-07-01');
    }

    private function makeContext(int $earlyMinutes = 120): array
    {
        $roomType   = RoomType::factory()->create();
        $room       = Room::factory()->for($roomType)->create();
        $booking    = Booking::factory()->create();
        $folio      = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'status' => AssignmentStatus::CheckedIn,
        ]);

        $plannedCheckin = now()->setTime(14, 0, 0);
        $actualCheckin  = $plannedCheckin->copy()->subMinutes($earlyMinutes);

        $stay = Stay::factory()->for($booking)->for($room)->create([
            'room_assignment_id' => $assignment->id,
            'status'             => StayStatus::CheckedIn,
            'planned_checkin_at' => $plannedCheckin,
            'actual_checkin_at'  => $actualCheckin,
        ]);

        BookingRequirement::factory()->create([
            'booking_id'   => $booking->id,
            'room_type_id' => $roomType->id,
            'room_price'   => 500000,
        ]);

        RoomRate::factory()->for($roomType)->create([
            'status'              => RateStatus::Active,
            'valid_from'          => now()->subYear()->toDateString(),
            'valid_to'            => now()->addYear()->toDateString(),
            'late_checkout_price' => 300000,
            'early_checkin_price' => 200000,
        ]);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
            postedBy:     $this->staff,
        );

        return [$context, $stay, $folio];
    }

    public function test_early_checkin_fee_is_posted_when_checkin_is_early(): void
    {
        [$context] = $this->makeContext(earlyMinutes: 120);

        $job    = app(EarlyCheckinFeePostingJob::class);
        $result = $job->execute($context);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->entry);

        $this->assertDatabaseHas('folio_entries', [
            'charge_type'    => ChargeType::EarlyCheckin->value,
            'posting_source' => 'SYSTEM_AUTO',
            'posting_key'    => "EARLY_CHECKIN_{$context->stay->id}",
        ]);
    }

    public function test_early_checkin_not_posted_within_grace_period(): void
    {
        [$context] = $this->makeContext(earlyMinutes: 20);

        $job    = app(EarlyCheckinFeePostingJob::class);
        $result = $job->execute($context);

        $this->assertNull($result->entry);
        $this->assertFalse($result->alreadyPosted);
        $this->assertDatabaseMissing('folio_entries', ['charge_type' => ChargeType::EarlyCheckin->value]);
    }

    public function test_early_checkin_fee_is_idempotent(): void
    {
        [$context] = $this->makeContext(earlyMinutes: 120);

        $job = app(EarlyCheckinFeePostingJob::class);
        $job->execute($context);
        $result2 = $job->execute($context);

        $this->assertTrue($result2->alreadyPosted);
        $this->assertDatabaseCount('folio_entries', 1);
    }
}
