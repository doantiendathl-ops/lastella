<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\RevenueReportService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Folio $folio;
    private Carbon $businessDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->businessDate = Carbon::parse('2026-07-03');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($this->businessDate));

        $booking     = Booking::factory()->create();
        $this->folio = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
    }

    private function mockBusinessDate(Carbon $date): BusinessDateService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($date);
        $mock->method('businessDateFor')->willReturn($date);

        return $mock;
    }

    private function service(): RevenueReportService
    {
        return app(RevenueReportService::class);
    }

    private function entry(array $overrides = []): FolioEntry
    {
        return FolioEntry::factory()->create(array_merge(['folio_id' => $this->folio->id], $overrides));
    }

    // -------------------------------------------------------------------------
    // Daily Summary
    // -------------------------------------------------------------------------

    public function test_daily_summary_sums_non_voided_entries_by_charge_type(): void
    {
        $this->entry(['charge_type' => ChargeType::Room,         'amount' => 500000, 'entry_date' => '2026-07-03']);
        $this->entry(['charge_type' => ChargeType::Room,         'amount' => 300000, 'entry_date' => '2026-07-03']);
        $this->entry(['charge_type' => ChargeType::FoodBeverage, 'amount' => 150000, 'entry_date' => '2026-07-03']);

        $result = $this->service()->dailySummary(Carbon::parse('2026-07-03'));

        $this->assertSame('2026-07-03', $result['date']);
        $this->assertSame(950000.0, $result['total']);

        $byType = collect($result['by_charge_type'])->keyBy('charge_type');
        $this->assertSame(800000.0, $byType['ROOM']['amount']);
        $this->assertSame(2, $byType['ROOM']['count']);
        $this->assertSame(150000.0, $byType['FOOD_BEVERAGE']['amount']);
        $this->assertSame(1, $byType['FOOD_BEVERAGE']['count']);
    }

    public function test_daily_summary_excludes_voided_entries(): void
    {
        $this->entry(['charge_type' => ChargeType::Room, 'amount' => 500000, 'entry_date' => '2026-07-03']);
        $this->entry(['charge_type' => ChargeType::Room, 'amount' => 300000, 'entry_date' => '2026-07-03', 'voided_at' => now()]);

        $result = $this->service()->dailySummary(Carbon::parse('2026-07-03'));

        $this->assertSame(500000.0, $result['total']);
        $byType = collect($result['by_charge_type'])->keyBy('charge_type');
        $this->assertSame(500000.0, $byType['ROOM']['amount']);
        $this->assertSame(1, $byType['ROOM']['count']);
    }

    // -------------------------------------------------------------------------
    // Period Summary
    // -------------------------------------------------------------------------

    public function test_period_summary_aggregates_across_date_range(): void
    {
        $this->entry(['charge_type' => ChargeType::Room, 'amount' => 500000, 'entry_date' => '2026-07-01']);
        $this->entry(['charge_type' => ChargeType::Room, 'amount' => 600000, 'entry_date' => '2026-07-02']);
        $this->entry(['charge_type' => ChargeType::Room, 'amount' => 700000, 'entry_date' => '2026-07-03']);

        $result = $this->service()->periodSummary(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-03'),
        );

        $this->assertSame('2026-07-01', $result['from']);
        $this->assertSame('2026-07-03', $result['to']);
        $this->assertSame(1800000.0, $result['total']);

        $byType = collect($result['by_charge_type'])->keyBy('charge_type');
        $this->assertSame(1800000.0, $byType['ROOM']['amount']);
        $this->assertSame(3, $byType['ROOM']['count']);

        $this->assertCount(3, $result['by_date']);
        $byDate = collect($result['by_date'])->keyBy('date');
        $this->assertSame(500000.0, $byDate['2026-07-01']['total']);
        $this->assertSame(600000.0, $byDate['2026-07-02']['total']);
        $this->assertSame(700000.0, $byDate['2026-07-03']['total']);
    }

    public function test_period_summary_excludes_voided_entries(): void
    {
        $this->entry(['amount' => 500000, 'entry_date' => '2026-07-01']);
        $this->entry(['amount' => 300000, 'entry_date' => '2026-07-01', 'voided_at' => now()]);

        $result = $this->service()->periodSummary(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-01'),
        );

        $this->assertSame(500000.0, $result['total']);
    }

    // -------------------------------------------------------------------------
    // Date Filtering
    // -------------------------------------------------------------------------

    public function test_date_filter_uses_entry_date(): void
    {
        $this->entry(['amount' => 500000, 'entry_date' => '2026-07-01']);
        $this->entry(['amount' => 999999, 'entry_date' => '2026-07-10']); // outside range

        $daily = $this->service()->dailySummary(Carbon::parse('2026-07-01'));
        $this->assertSame(500000.0, $daily['total']);

        $period = $this->service()->periodSummary(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-05'),
        );
        $this->assertSame(500000.0, $period['total']);
    }

    // -------------------------------------------------------------------------
    // Revenue by Source
    // -------------------------------------------------------------------------

    public function test_revenue_by_source_groups_all_posting_sources(): void
    {
        $date = '2026-07-01';
        $this->entry(['amount' => 100000, 'entry_date' => $date, 'posting_source' => 'MANUAL']);
        $this->entry(['amount' => 200000, 'entry_date' => $date, 'posting_source' => 'NIGHT_AUDIT']);
        $this->entry(['amount' => 300000, 'entry_date' => $date, 'posting_source' => 'SYSTEM_AUTO']);

        $result = $this->service()->revenueBySource(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-01'),
        );

        $this->assertSame(600000.0, $result['total']);

        $bySource = collect($result['by_source'])->keyBy('source');
        $this->assertSame(100000.0, $bySource['MANUAL']['amount']);
        $this->assertSame(200000.0, $bySource['NIGHT_AUDIT']['amount']);
        $this->assertSame(300000.0, $bySource['SYSTEM_AUTO']['amount']);
    }

    public function test_entries_with_default_posting_source_are_grouped_as_manual(): void
    {
        // The factory omits posting_source; the DB default 'MANUAL' is used.
        // COALESCE(posting_source, 'MANUAL') in the SQL query also covers legacy rows
        // that may have a NULL posting_source in production databases.
        $this->entry(['amount' => 150000, 'entry_date' => '2026-07-01']);

        $result = $this->service()->revenueBySource(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-01'),
        );

        $bySource = collect($result['by_source'])->keyBy('source');
        $this->assertArrayHasKey('MANUAL', $bySource->all());
        $this->assertSame(150000.0, $bySource['MANUAL']['amount']);
    }

    // -------------------------------------------------------------------------
    // Isolation from Other Financial Data
    // -------------------------------------------------------------------------

    public function test_booking_payments_are_not_counted_as_revenue(): void
    {
        BookingPayment::factory()->create(['amount' => 5000000]);

        $result = $this->service()->periodSummary(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-30'),
        );

        $this->assertSame(0.0, $result['total']);
        $this->assertEmpty($result['by_charge_type']);
    }

    public function test_booking_requirements_are_not_counted_as_revenue(): void
    {
        BookingRequirement::factory()->create(['room_price' => 500000]);

        $result = $this->service()->periodSummary(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-30'),
        );

        $this->assertSame(0.0, $result['total']);
        $this->assertEmpty($result['by_charge_type']);
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    public function test_unauthenticated_user_cannot_view_revenue_report(): void
    {
        $this->get(route('admin.reports.revenue.index'))->assertRedirect('/login');
    }

    public function test_reception_cannot_view_revenue_report(): void
    {
        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');

        $this->actingAs($reception)
            ->get(route('admin.reports.revenue.index'))
            ->assertForbidden();
    }

    public function test_sales_cannot_view_revenue_report(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('SALES');

        $this->actingAs($sales)
            ->get(route('admin.reports.revenue.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_revenue_report(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.revenue.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Revenue/Index', false) // false: skip Vue file existence check (backend-only phase)
                ->has('daily')
                ->has('period')
                ->has('bySource')
                ->has('filters')
                ->has('businessDate')
            );
    }

    public function test_manager_can_view_revenue_report(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $this->actingAs($manager)
            ->get(route('admin.reports.revenue.index'))
            ->assertOk();
    }

    public function test_accountant_can_view_revenue_report(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');

        $this->actingAs($accountant)
            ->get(route('admin.reports.revenue.index'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // CSV Export
    // -------------------------------------------------------------------------

    public function test_csv_export_requires_revenue_view_permission(): void
    {
        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');

        $this->actingAs($reception)
            ->get(route('admin.reports.revenue.export'))
            ->assertForbidden();
    }

    public function test_csv_export_contains_correct_headers_and_rows(): void
    {
        $this->entry([
            'charge_type'    => ChargeType::Room,
            'description'    => 'Tiền phòng 101',
            'quantity'       => '1.00',
            'unit_price'     => '500000.00',
            'amount'         => '500000.00',
            'entry_date'     => '2026-07-01',
            'posting_source' => 'NIGHT_AUDIT',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.revenue.export', ['from' => '2026-07-01', 'to' => '2026-07-01']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Ngày', $content);
        $this->assertStringContainsString('Loại phí', $content);
        $this->assertStringContainsString('Tiền phòng', $content); // ChargeType::Room label
        $this->assertStringContainsString('2026-07-01', $content);
        $this->assertStringContainsString('NIGHT_AUDIT', $content);
        $this->assertStringContainsString('500000', $content);
    }

    public function test_csv_export_excludes_voided_entries(): void
    {
        $this->entry(['amount' => 500000, 'entry_date' => '2026-07-01', 'posting_source' => 'MANUAL']);
        $this->entry(['amount' => 999999, 'entry_date' => '2026-07-01', 'posting_source' => 'MANUAL', 'voided_at' => now()]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.revenue.export', ['from' => '2026-07-01', 'to' => '2026-07-01']));

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('999999', $content);
        $this->assertStringContainsString('500000', $content);
    }
}
