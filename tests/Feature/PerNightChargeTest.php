<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Stay;
use App\Models\User;
use App\Models\BookingRequirement;
use App\Services\Posting\PostingContext;
use App\Services\Posting\RoomChargePostingJob;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerNightChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private RoomChargePostingJob $job;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->businessDate = Carbon::parse('2026-07-01');
        $this->job = app(RoomChargePostingJob::class);
    }

    private function makeContext(Stay $stay, Carbon $date = null): PostingContext
    {
        $booking = Booking::find($stay->booking_id);
        $folio   = Folio::where('booking_id', $booking->id)->first()
            ?? Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        // Ensure there is a BookingRequirement matching the stay's room type
        // so resolveUnitPrice returns a non-zero value.
        $stay->loadMissing('room');
        if ($stay->room !== null) {
            $exists = BookingRequirement::where('booking_id', $booking->id)
                ->where('room_type_id', $stay->room->room_type_id)
                ->exists();

            if (! $exists) {
                BookingRequirement::factory()->create([
                    'booking_id'   => $booking->id,
                    'room_type_id' => $stay->room->room_type_id,
                    'room_price'   => 500000,
                ]);
            }
        }

        return new PostingContext(
            booking:      $booking->fresh(),
            folio:        $folio,
            businessDate: $date ?? $this->businessDate,
            stay:         $stay,
            postedBy:     $this->admin,
        );
    }

    public function test_posts_room_charge_entry_on_first_call(): void
    {
        $stay    = Stay::factory()->create();
        $context = $this->makeContext($stay);

        $result = $this->job->execute($context);

        $this->assertTrue($result->success);
        $this->assertFalse($result->alreadyPosted);
        $this->assertNotNull($result->entry);
        $this->assertEquals(ChargeType::Room->value, $result->entry->charge_type->value);
        $this->assertEquals('NIGHT_AUDIT', $result->entry->posting_source);
        $this->assertEquals($stay->id, $result->entry->stay_id);
    }

    public function test_posting_key_matches_room_night_format(): void
    {
        $stay    = Stay::factory()->create();
        $context = $this->makeContext($stay);

        $this->job->execute($context);

        $expectedKey = 'ROOM_NIGHT_' . $stay->id . '_2026-07-01';
        $this->assertDatabaseHas('folio_entries', ['posting_key' => $expectedKey]);
    }

    public function test_second_call_returns_already_posted(): void
    {
        $stay    = Stay::factory()->create();
        $context = $this->makeContext($stay);

        $this->job->execute($context);
        $result = $this->job->execute($context);

        $this->assertTrue($result->alreadyPosted);
        $this->assertDatabaseCount('folio_entries', 1);
    }

    public function test_different_business_date_creates_new_entry(): void
    {
        $stay     = Stay::factory()->create();
        $context1 = $this->makeContext($stay, Carbon::parse('2026-07-01'));
        $context2 = $this->makeContext($stay, Carbon::parse('2026-07-02'));

        $this->job->execute($context1);
        $result2 = $this->job->execute($context2);

        $this->assertTrue($result2->success);
        $this->assertFalse($result2->alreadyPosted);
        $this->assertDatabaseCount('folio_entries', 2);
    }

    public function test_is_already_posted_returns_false_before_first_post(): void
    {
        $stay    = Stay::factory()->create();
        $context = $this->makeContext($stay);

        $this->assertFalse($this->job->isAlreadyPosted($context));
    }

    public function test_is_already_posted_returns_true_after_post(): void
    {
        $stay    = Stay::factory()->create();
        $context = $this->makeContext($stay);

        $this->job->execute($context);

        $this->assertTrue($this->job->isAlreadyPosted($context));
    }

    public function test_check_in_posts_first_night_charge(): void
    {
        $this->actingAs($this->admin);
        $stay = Stay::factory()->create([
            'status'            => StayStatus::Reserved,
            'planned_checkin_at'  => now()->subHour(),
            'planned_checkout_at' => now()->addDays(2),
        ]);

        $booking = Booking::find($stay->booking_id);
        $folio   = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        // Ensure room charge will be non-zero
        $stay->loadMissing('room');
        if ($stay->room !== null) {
            BookingRequirement::factory()->create([
                'booking_id'   => $booking->id,
                'room_type_id' => $stay->room->room_type_id,
                'room_price'   => 500000,
            ]);
        }

        // Ensure assignment is in Assigned state
        $stay->roomAssignment()->update([
            'status'   => AssignmentStatus::Assigned,
            'start_at' => now()->subHour(),
        ]);

        $this->post(
            route('admin.bookings.stays.check-in', [
                'booking' => $stay->booking_id,
                'stay'    => $stay->id,
            ])
        )->assertRedirect();

        $this->assertDatabaseHas('folio_entries', [
            'stay_id'        => $stay->id,
            'posting_source' => 'NIGHT_AUDIT',
            'charge_type'    => ChargeType::Room->value,
        ]);
    }

    public function test_skips_post_when_folio_is_closed(): void
    {
        $stay    = Stay::factory()->create();
        $booking = Booking::find($stay->booking_id);
        $folio   = Folio::factory()->for($booking)->create(['status' => FolioStatus::Closed]);

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $result = $this->job->execute($context);

        $this->assertTrue($result->success);
        $this->assertNull($result->entry);
        $this->assertDatabaseCount('folio_entries', 0);
    }
}
