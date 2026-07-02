<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Enums\RateStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Enums\PaymentType;
use App\Models\BookingPayment;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\RoomRate;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\HotelSettingsService;
use App\Services\Posting\LateCheckoutFeePostingJob;
use App\Services\Posting\PostingContext;
use App\Services\StayService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LateCheckoutFeeTest extends TestCase
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

    private function makeContext(int $lateMinutes = 120): array
    {
        $roomType   = RoomType::factory()->create();
        $room       = Room::factory()->for($roomType)->create();
        $booking    = Booking::factory()->create();
        $folio      = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'status' => AssignmentStatus::CheckedIn,
        ]);

        $plannedCheckout = now()->setTime(12, 0, 0);
        $actualCheckout  = $plannedCheckout->copy()->addMinutes($lateMinutes);

        $stay = Stay::factory()->for($booking)->for($room)->create([
            'room_assignment_id'  => $assignment->id,
            'status'              => StayStatus::CheckedOut,
            'planned_checkout_at' => $plannedCheckout,
            'actual_checkout_at'  => $actualCheckout,
            'actual_checkin_at'   => $plannedCheckout->copy()->subDay(),
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

    public function test_late_checkout_fee_is_posted_when_checkout_exceeds_grace(): void
    {
        [$context] = $this->makeContext(lateMinutes: 120);

        $job    = app(LateCheckoutFeePostingJob::class);
        $result = $job->execute($context);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->entry);

        $this->assertDatabaseHas('folio_entries', [
            'charge_type'    => ChargeType::LateCheckout->value,
            'posting_source' => 'SYSTEM_AUTO',
            'posting_key'    => "LATE_CHECKOUT_{$context->stay->id}",
        ]);
    }

    public function test_late_checkout_not_posted_within_grace_period(): void
    {
        [$context] = $this->makeContext(lateMinutes: 20);

        $job    = app(LateCheckoutFeePostingJob::class);
        $result = $job->execute($context);

        $this->assertNull($result->entry);
        $this->assertFalse($result->alreadyPosted);
        $this->assertDatabaseMissing('folio_entries', ['charge_type' => ChargeType::LateCheckout->value]);
    }

    public function test_late_checkout_fee_is_idempotent(): void
    {
        [$context] = $this->makeContext(lateMinutes: 120);

        $job = app(LateCheckoutFeePostingJob::class);
        $job->execute($context);
        $result2 = $job->execute($context);

        $this->assertTrue($result2->alreadyPosted);
        $this->assertDatabaseCount('folio_entries', 1);
    }

    public function test_late_checkout_skipped_when_no_room_rate(): void
    {
        $roomType   = RoomType::factory()->create();
        $room       = Room::factory()->for($roomType)->create();
        $booking    = Booking::factory()->create();
        $folio      = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'status' => AssignmentStatus::CheckedIn,
        ]);

        $plannedCheckout = now()->setTime(12, 0, 0);
        $stay = Stay::factory()->for($booking)->for($room)->create([
            'room_assignment_id'  => $assignment->id,
            'status'              => StayStatus::CheckedOut,
            'planned_checkout_at' => $plannedCheckout,
            'actual_checkout_at'  => $plannedCheckout->copy()->addHours(2),
            'actual_checkin_at'   => $plannedCheckout->copy()->subDay(),
        ]);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job    = app(LateCheckoutFeePostingJob::class);
        $result = $job->execute($context);

        $this->assertNull($result->entry);
        $this->assertDatabaseMissing('folio_entries', ['charge_type' => ChargeType::LateCheckout->value]);
    }

    public function test_check_out_triggers_late_checkout_fee_via_stay_service(): void
    {
        $roomType   = RoomType::factory()->create();
        $room       = Room::factory()->for($roomType)->create();
        $booking    = Booking::factory()->create();
        $folio      = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'status' => AssignmentStatus::CheckedIn,
        ]);

        $plannedCheckout = now()->subHours(2)->setTime(12, 0, 0);
        $stay = Stay::factory()->for($booking)->for($room)->create([
            'room_assignment_id'  => $assignment->id,
            'status'              => StayStatus::CheckedIn,
            'planned_checkin_at'  => $plannedCheckout->copy()->subDay(),
            'planned_checkout_at' => $plannedCheckout,
            'actual_checkin_at'   => $plannedCheckout->copy()->subDay(),
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

        // Pay enough to cover room estimate (500000) + late fee (300000).
        BookingPayment::factory()->for($booking)->create([
            'payment_type' => PaymentType::RoomPayment,
            'amount'       => 900000,
        ]);

        $this->actingAs($this->staff);
        $service = app(StayService::class);
        $service->checkOut($stay, now(), confirmed: true);

        $this->assertDatabaseHas('folio_entries', [
            'charge_type'    => ChargeType::LateCheckout->value,
            'posting_source' => 'SYSTEM_AUTO',
        ]);
    }

    public function test_late_checkout_skipped_when_folio_closed(): void
    {
        $roomType   = RoomType::factory()->create();
        $room       = Room::factory()->for($roomType)->create();
        $booking    = Booking::factory()->create();
        $folio      = Folio::factory()->for($booking)->create(['status' => FolioStatus::Closed]);
        $assignment = RoomAssignment::factory()->for($booking)->for($room)->create([
            'status' => AssignmentStatus::CheckedIn,
        ]);

        $plannedCheckout = now()->setTime(12, 0, 0);
        $stay = Stay::factory()->for($booking)->for($room)->create([
            'room_assignment_id'  => $assignment->id,
            'status'              => StayStatus::CheckedOut,
            'planned_checkout_at' => $plannedCheckout,
            'actual_checkout_at'  => $plannedCheckout->copy()->addHours(2),
            'actual_checkin_at'   => $plannedCheckout->copy()->subDay(),
        ]);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job    = app(LateCheckoutFeePostingJob::class);
        $result = $job->execute($context);

        $this->assertNull($result->entry);
    }
}
