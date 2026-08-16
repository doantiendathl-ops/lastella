<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FolioStatus;
use App\Enums\ServiceBillingMode;
use App\Enums\ServiceFulfillmentStatus;
use App\Enums\ServiceScope;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\BookingService;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Stay;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\NightAuditService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 17/26) —
 * end-to-end proof through the REAL NightAuditService/NightAuditPipeline
 * (not the posting job in isolation): the new job is registered
 * additively alongside the 4 legacy jobs, a real Night Audit run posts
 * the unified PER_NIGHT charge exactly once, and a retry for the same
 * business date changes nothing (catch-up safe, regression-safe for the
 * existing legacy jobs sharing the same pipeline run).
 */
class NightAuditUnifiedServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create();
    }

    private function mockBusinessDate(Carbon $date): BusinessDateService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($date);
        $mock->method('businessDateFor')->willReturn($date);

        return $mock;
    }

    public function test_real_night_audit_run_posts_the_unified_service_charge(): void
    {
        $businessDate = Carbon::parse('2026-08-16');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($businessDate));

        $stay = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $booking = Booking::find($stay->booking_id);
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        $stay->loadMissing('room');
        BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $stay->room->room_type_id,
            'room_price' => 500000,
        ]);

        $category = ServiceCategory::create(['code' => 'CAT_NA', 'name' => 'Test', 'is_active' => true]);
        $service = Service::create([
            'category_id' => $category->id,
            'code' => 'SVC_NA',
            'name' => 'Giường phụ',
            'is_chargeable' => true,
            'scope' => ServiceScope::Room->value,
            'billing_mode' => ServiceBillingMode::PerNight->value,
            'quantity_enabled' => true,
            'default_quantity' => 1,
            'unit_label' => 'giường',
            'is_active' => true,
            'is_bookable' => true,
        ]);

        BookingService::create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'room_assignment_id' => $stay->room_assignment_id,
            'quantity' => 1,
            'billing_mode_selected' => ServiceBillingMode::PerNight->value,
            'suggested_price' => '150000.00',
            'actual_price' => '150000.00',
            'fulfillment_status' => ServiceFulfillmentStatus::Created->value,
            'created_by' => $this->admin->id,
        ]);

        $run = app(NightAuditService::class)->runForDate($businessDate);

        $this->assertSame('COMPLETED', $run->status);
        $this->assertDatabaseHas('folio_entries', [
            'folio_id' => $booking->folio->id,
            'stay_id' => $stay->id,
            'amount' => 150000,
        ]);
    }

    public function test_retrying_the_same_business_date_never_duplicates_the_unified_charge(): void
    {
        $businessDate = Carbon::parse('2026-08-16');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($businessDate));

        $stay = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $booking = Booking::find($stay->booking_id);
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
        $stay->loadMissing('room');
        BookingRequirement::factory()->create([
            'booking_id' => $booking->id,
            'room_type_id' => $stay->room->room_type_id,
            'room_price' => 500000,
        ]);

        $category = ServiceCategory::create(['code' => 'CAT_NA2', 'name' => 'Test', 'is_active' => true]);
        $service = Service::create([
            'category_id' => $category->id,
            'code' => 'SVC_NA2',
            'name' => 'Giường phụ',
            'is_chargeable' => true,
            'scope' => ServiceScope::Room->value,
            'billing_mode' => ServiceBillingMode::PerNight->value,
            'quantity_enabled' => true,
            'default_quantity' => 1,
            'unit_label' => 'giường',
            'is_active' => true,
            'is_bookable' => true,
        ]);

        BookingService::create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'room_assignment_id' => $stay->room_assignment_id,
            'quantity' => 1,
            'billing_mode_selected' => ServiceBillingMode::PerNight->value,
            'suggested_price' => '150000.00',
            'actual_price' => '150000.00',
            'fulfillment_status' => ServiceFulfillmentStatus::Created->value,
            'created_by' => $this->admin->id,
        ]);

        $unifiedEntries = fn () => FolioEntry::where('posting_key', 'like', 'UNIFIED_SVC_%')->count();

        // First run posts (also posts the separate, expected RoomChargePostingJob room
        // charge — this test only asserts on the unified service's own entries).
        app(NightAuditService::class)->runForDate($businessDate);
        $this->assertSame(1, $unifiedEntries());

        // Directly invoke the pipeline a second time for the same date (simulating a retry
        // path, bypassing the NightAuditRun-level "already COMPLETED" guard which is a
        // separate, non-financial safety rail — the real guarantee must hold at this layer).
        $existingRun = \App\Models\NightAuditRun::where('business_date', $businessDate->toDateString())->firstOrFail();
        app(\App\Services\NightAuditPipeline::class)
            ->register(app(\App\Services\Posting\UnifiedServicePostingJob::class))
            ->run($existingRun, $businessDate);

        $this->assertSame(1, $unifiedEntries(), 'A retried pipeline run must never duplicate an already-posted unified service charge.');
    }
}
