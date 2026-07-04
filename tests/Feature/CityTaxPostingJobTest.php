<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\NightAuditRun;
use App\Models\ServiceRate;
use App\Models\Stay;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\HotelSettingsService;
use App\Services\NightAuditService;
use App\Services\Posting\CityTaxPostingJob;
use App\Services\Posting\PostingContext;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CityTaxPostingJobTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin        = User::factory()->create();
        $this->businessDate = Carbon::parse('2026-07-04');

        $this->instance(BusinessDateService::class, $this->mockBusinessDate($this->businessDate));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function mockBusinessDate(Carbon $date): BusinessDateService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($date);
        $mock->method('businessDateFor')->willReturn($date);

        return $mock;
    }

    private function mockSettings(bool $enabled = true, int $quantity = 1): void
    {
        $mock = $this->createMock(HotelSettingsService::class);
        $mock->method('getBool')->willReturnCallback(
            fn (string $key) => $key === 'city_tax_enabled' ? $enabled : false
        );
        $mock->method('getInt')->willReturnCallback(
            fn (string $key, int $default = 0) => $key === 'city_tax_quantity' ? $quantity : $default
        );
        $this->instance(HotelSettingsService::class, $mock);
    }

    private function makeCheckedInStayWithFolio(): Stay
    {
        $stay    = Stay::factory()->create(['status' => StayStatus::CheckedIn]);
        $booking = Booking::find($stay->booking_id);
        Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);

        $stay->loadMissing('room');
        if ($stay->room !== null) {
            BookingRequirement::factory()->create([
                'booking_id'   => $booking->id,
                'room_type_id' => $stay->room->room_type_id,
                'room_price'   => 500000,
            ]);
        }

        return $stay;
    }

    private function makeCityTaxRate(string $unitPrice = '50000.00'): ServiceRate
    {
        return ServiceRate::create([
            'name'           => 'Thuế du lịch',
            'charge_type'    => ChargeType::CityTax->value,
            'unit_price'     => $unitPrice,
            'effective_from' => '2026-01-01',
            'unit_label'     => 'đêm',
            'tax_rate'       => '0.0000',
            'is_active'      => true,
            'display_order'  => 90,
            'created_by'     => null,
        ]);
    }

    private function makeAuditRun(): NightAuditRun
    {
        return NightAuditRun::create([
            'business_date' => $this->businessDate->toDateString(),
            'status'        => 'PENDING',
            'run_by'        => $this->admin->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // shouldProcess
    // -------------------------------------------------------------------------

    public function test_should_process_returns_false_when_city_tax_disabled(): void
    {
        $this->mockSettings(enabled: false);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $this->assertFalse(app(CityTaxPostingJob::class)->shouldProcess($context));
    }

    public function test_should_process_returns_false_when_folio_not_open(): void
    {
        $this->mockSettings(enabled: true);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $folio->update(['status' => FolioStatus::Closed]);
        $folio->refresh();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $this->assertFalse(app(CityTaxPostingJob::class)->shouldProcess($context));
    }

    public function test_should_process_returns_false_when_no_stay_in_context(): void
    {
        $this->mockSettings(enabled: true);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         null,
        );

        $this->assertFalse(app(CityTaxPostingJob::class)->shouldProcess($context));
    }

    public function test_should_process_returns_true_when_enabled_and_folio_open(): void
    {
        $this->mockSettings(enabled: true);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $this->assertTrue(app(CityTaxPostingJob::class)->shouldProcess($context));
    }

    // -------------------------------------------------------------------------
    // execute
    // -------------------------------------------------------------------------

    public function test_execute_skips_when_no_active_city_tax_rate(): void
    {
        $this->mockSettings(enabled: true);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $result = app(CityTaxPostingJob::class)->execute($context);

        $this->assertTrue($result->success);
        $this->assertNull($result->entry);
        $this->assertStringContainsString('CITY_TAX', $result->message);
        $this->assertDatabaseCount('folio_entries', 0);
    }

    public function test_execute_posts_entry_with_correct_fields_and_posting_key(): void
    {
        $this->mockSettings(enabled: true, quantity: 1);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->makeCityTaxRate('50000.00');

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $result = app(CityTaxPostingJob::class)->execute($context);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->entry);

        $expectedKey = "CITY_TAX_{$stay->id}_" . $this->businessDate->toDateString();

        $this->assertDatabaseHas('folio_entries', [
            'folio_id'       => $folio->id,
            'stay_id'        => $stay->id,
            'posting_source' => 'NIGHT_AUDIT',
            'charge_type'    => ChargeType::CityTax->value,
            'posting_key'    => $expectedKey,
            'quantity'       => '1.00',
            'unit_price'     => '50000.00',
            'amount'         => '50000.00',
        ]);
    }

    public function test_execute_amount_uses_configured_quantity(): void
    {
        $this->mockSettings(enabled: true, quantity: 3);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->makeCityTaxRate('50000.00');

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        app(CityTaxPostingJob::class)->execute($context);

        $this->assertDatabaseHas('folio_entries', [
            'folio_id'  => $folio->id,
            'quantity'  => '3.00',
            'unit_price' => '50000.00',
            'amount'    => '150000.00',
        ]);
    }

    public function test_execute_is_idempotent_on_retry(): void
    {
        $this->mockSettings(enabled: true, quantity: 1);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $folio   = $booking->folio;
        $this->makeCityTaxRate();

        $context = new PostingContext(
            booking:      $booking,
            folio:        $folio,
            businessDate: $this->businessDate,
            stay:         $stay,
        );

        $job = app(CityTaxPostingJob::class);
        $job->execute($context);

        $result2 = $job->execute($context);
        $this->assertTrue($result2->alreadyPosted);
        $this->assertDatabaseCount('folio_entries', 1);
    }

    public function test_night_audit_service_registers_city_tax_job(): void
    {
        $this->mockSettings(enabled: true, quantity: 1);

        $stay    = $this->makeCheckedInStayWithFolio();
        $booking = Booking::find($stay->booking_id);
        $this->makeCityTaxRate();

        $run = app(NightAuditService::class)->runForDate($this->businessDate);

        $this->assertEquals('COMPLETED', $run->status);

        $this->assertDatabaseHas('night_audit_booking_logs', [
            'job_class' => CityTaxPostingJob::class,
            'result'    => 'POSTED',
        ]);
    }
}
