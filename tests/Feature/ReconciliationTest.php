<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\User;
use App\Services\BookingService;
use App\Services\BusinessDateService;
use App\Services\ReconciliationService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Booking $booking;
    private Folio $folio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $this->booking = Booking::factory()->create(['status' => BookingStatus::CheckedIn]);
        $this->folio   = Folio::factory()->for($this->booking)->create(['status' => FolioStatus::Open]);

        $businessDate = Carbon::parse('2026-07-04');
        $this->instance(BusinessDateService::class, $this->mockBusinessDate($businessDate));
    }

    private function mockBusinessDate(Carbon $date): BusinessDateService
    {
        $mock = $this->createMock(BusinessDateService::class);
        $mock->method('currentBusinessDate')->willReturn($date);
        $mock->method('businessDateFor')->willReturn($date);
        return $mock;
    }

    private function service(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    private function addCharge(float $amount, array $overrides = []): FolioEntry
    {
        return FolioEntry::factory()->create(array_merge([
            'folio_id'    => $this->folio->id,
            'charge_type' => ChargeType::Room,
            'amount'      => $amount,
            'entry_date'  => '2026-07-01',
        ], $overrides));
    }

    private function addPayment(float $amount, PaymentType $type = PaymentType::Deposit): BookingPayment
    {
        return BookingPayment::factory()->create([
            'booking_id'   => $this->booking->id,
            'payment_type' => $type,
            'amount'       => $amount,
        ]);
    }

    // -------------------------------------------------------------------------
    // 1. Outstanding balances — positive balance_due included
    // -------------------------------------------------------------------------

    public function test_outstanding_balances_returns_bookings_with_positive_balance_due(): void
    {
        $this->addCharge(1_000_000);
        $this->addPayment(600_000);

        $rows = $this->service()->outstandingBalances();

        $this->assertCount(1, $rows);
        $this->assertSame($this->booking->id, $rows[0]['booking_id']);
        $this->assertSame(1_000_000.0, $rows[0]['total_charges']);
        $this->assertSame(600_000.0, $rows[0]['paid_total']);
        $this->assertSame(400_000.0, $rows[0]['balance_due']);
    }

    // -------------------------------------------------------------------------
    // 2. Outstanding balances — fully paid booking excluded
    // -------------------------------------------------------------------------

    public function test_outstanding_balances_excludes_fully_paid_bookings(): void
    {
        $this->addCharge(500_000);
        $this->addPayment(500_000);

        $rows = $this->service()->outstandingBalances();

        $this->assertEmpty($rows);
    }

    // -------------------------------------------------------------------------
    // 3. Outstanding balances — overpaid booking excluded (balance_due < 0)
    // -------------------------------------------------------------------------

    public function test_outstanding_balances_excludes_overpaid_bookings(): void
    {
        $this->addCharge(500_000);
        $this->addPayment(700_000);

        $rows = $this->service()->outstandingBalances();

        $this->assertEmpty($rows);
    }

    // -------------------------------------------------------------------------
    // 4. Outstanding balance formula agrees with BookingService::paymentSummary()
    // -------------------------------------------------------------------------

    public function test_outstanding_balance_formula_matches_booking_service_payment_summary(): void
    {
        $this->addCharge(900_000);
        $this->addPayment(300_000, PaymentType::Deposit);
        $this->addPayment(100_000, PaymentType::Refund);

        $rows = $this->service()->outstandingBalances();
        $this->assertCount(1, $rows);

        $bookingWithRelations = Booking::with(['folio', 'bookingPayments'])->find($this->booking->id);
        $canonical = app(BookingService::class)->paymentSummary($bookingWithRelations);

        $this->assertSame($canonical['balance_due'], $rows[0]['balance_due']);
        $this->assertSame($canonical['total_charges'], $rows[0]['total_charges']);
        $this->assertSame($canonical['paid_total'], $rows[0]['paid_total']);
    }

    // -------------------------------------------------------------------------
    // 5. Filter by booking status
    // -------------------------------------------------------------------------

    public function test_outstanding_balances_filter_by_status_returns_matching_bookings_only(): void
    {
        $this->addCharge(1_000_000);
        $this->addPayment(400_000);

        $otherBooking = Booking::factory()->create(['status' => BookingStatus::Deposited]);
        $otherFolio   = Folio::factory()->for($otherBooking)->create(['status' => FolioStatus::Open]);
        FolioEntry::factory()->create([
            'folio_id' => $otherFolio->id,
            'amount'   => 2_000_000,
        ]);
        BookingPayment::factory()->create([
            'booking_id'   => $otherBooking->id,
            'payment_type' => PaymentType::Deposit,
            'amount'       => 500_000,
        ]);

        $checkedIn = $this->service()->outstandingBalances(['status' => BookingStatus::CheckedIn->value]);
        $deposited = $this->service()->outstandingBalances(['status' => BookingStatus::Deposited->value]);

        $checkedInIds = array_column($checkedIn, 'booking_id');
        $depositedIds = array_column($deposited, 'booking_id');

        $this->assertContains($this->booking->id, $checkedInIds);
        $this->assertNotContains($otherBooking->id, $checkedInIds);

        $this->assertContains($otherBooking->id, $depositedIds);
        $this->assertNotContains($this->booking->id, $depositedIds);
    }

    // -------------------------------------------------------------------------
    // 6. Discrepancy: closed folio with non-zero balance
    // -------------------------------------------------------------------------

    public function test_closed_folio_with_non_zero_balance_appears_in_discrepancies(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::CheckedOut]);
        $folio   = Folio::factory()->for($booking)->create(['status' => FolioStatus::Closed]);
        FolioEntry::factory()->create(['folio_id' => $folio->id, 'amount' => 500_000]);
        // No payment — balance_due = 500_000

        $discrepancies = $this->service()->discrepancies();

        $ids = array_column($discrepancies, 'booking_id');
        $this->assertContains($booking->id, $ids);

        $row = collect($discrepancies)->firstWhere('booking_id', $booking->id);
        $this->assertNotNull($row);
        $this->assertSame(500_000.0, $row['balance_due']);
    }

    // -------------------------------------------------------------------------
    // 7. Discrepancy: checked-out booking with positive balance_due
    // -------------------------------------------------------------------------

    public function test_checked_out_booking_with_balance_appears_in_discrepancies(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::CheckedOut]);
        $folio   = Folio::factory()->for($booking)->create(['status' => FolioStatus::Closed]);
        FolioEntry::factory()->create(['folio_id' => $folio->id, 'amount' => 1_000_000]);
        BookingPayment::factory()->create([
            'booking_id'   => $booking->id,
            'payment_type' => PaymentType::RoomPayment,
            'amount'       => 700_000,
        ]);

        $discrepancies = $this->service()->discrepancies();

        $row = collect($discrepancies)->firstWhere('booking_id', $booking->id);
        $this->assertNotNull($row);
        $this->assertSame('CHECKED_OUT_OUTSTANDING_BALANCE', $row['type']);
        $this->assertSame(300_000.0, $row['balance_due']);
    }

    // -------------------------------------------------------------------------
    // 7b. Discrepancy type: CLOSED_FOLIO_NON_ZERO_BALANCE (non-checked-out booking)
    // -------------------------------------------------------------------------

    public function test_non_checked_out_booking_with_closed_folio_gets_correct_discrepancy_type(): void
    {
        $booking = Booking::factory()->create(['status' => BookingStatus::Deposited]);
        $folio   = Folio::factory()->for($booking)->create(['status' => FolioStatus::Closed]);
        FolioEntry::factory()->create(['folio_id' => $folio->id, 'amount' => 400_000]);
        // No payment

        $discrepancies = $this->service()->discrepancies();

        $row = collect($discrepancies)->firstWhere('booking_id', $booking->id);
        $this->assertNotNull($row);
        $this->assertSame('CLOSED_FOLIO_NON_ZERO_BALANCE', $row['type']);
    }

    // -------------------------------------------------------------------------
    // 8. Voided entries summary — voided entries included
    // -------------------------------------------------------------------------

    public function test_voided_entries_includes_voided_folio_entries_in_range(): void
    {
        $voidedAt = Carbon::parse('2026-07-02 10:00:00');

        $entry = FolioEntry::factory()->create([
            'folio_id'    => $this->folio->id,
            'amount'      => 300_000,
            'entry_date'  => '2026-07-01',
            'voided_at'   => $voidedAt,
            'void_reason' => 'Nhập sai',
        ]);

        $rows = $this->service()->voidedEntries(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-04'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame($entry->id, $rows[0]['id']);
        $this->assertSame(300_000.0, $rows[0]['amount']);
        $this->assertSame('Nhập sai', $rows[0]['void_reason']);
        $this->assertSame($this->booking->booking_code, $rows[0]['booking_code']);
    }

    // -------------------------------------------------------------------------
    // 9. Voided entries summary — active entries excluded
    // -------------------------------------------------------------------------

    public function test_voided_entries_excludes_active_folio_entries(): void
    {
        FolioEntry::factory()->create([
            'folio_id'   => $this->folio->id,
            'amount'     => 999_999,
            'entry_date' => '2026-07-01',
            'voided_at'  => null,
        ]);

        $rows = $this->service()->voidedEntries(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-04'),
        );

        $this->assertEmpty($rows);
    }

    // -------------------------------------------------------------------------
    // 10. Voided entries — includes voided_by name and void_reason
    // -------------------------------------------------------------------------

    public function test_voided_entries_includes_voided_by_name_and_void_reason(): void
    {
        $voider = User::factory()->create(['name' => 'Nguyễn Văn A']);

        FolioEntry::factory()->create([
            'folio_id'    => $this->folio->id,
            'amount'      => 100_000,
            'entry_date'  => '2026-07-01',
            'voided_at'   => '2026-07-02 09:30:00',
            'voided_by'   => $voider->id,
            'void_reason' => 'Lỗi nhập liệu',
        ]);

        $rows = $this->service()->voidedEntries(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-04'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Nguyễn Văn A', $rows[0]['voided_by']);
        $this->assertSame('Lỗi nhập liệu', $rows[0]['void_reason']);
        $this->assertSame('2026-07-02 09:30', $rows[0]['voided_at']);
    }

    // -------------------------------------------------------------------------
    // 11. CSV export — outstanding balances correct headers and rows
    // -------------------------------------------------------------------------

    public function test_csv_export_outstanding_contains_correct_headers_and_rows(): void
    {
        $this->addCharge(800_000);
        $this->addPayment(200_000);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Mã đặt phòng', $content);
        $this->assertStringContainsString('Còn nợ', $content);
        $this->assertStringContainsString($this->booking->booking_code, $content);
        $this->assertStringContainsString('600000', $content); // balance_due
    }

    // -------------------------------------------------------------------------
    // 12. CSV export — voided entries correct headers and rows
    // -------------------------------------------------------------------------

    public function test_csv_export_voided_contains_correct_headers_and_rows(): void
    {
        FolioEntry::factory()->create([
            'folio_id'    => $this->folio->id,
            'amount'      => 250_000,
            'entry_date'  => '2026-07-01',
            'voided_at'   => '2026-07-02 08:00:00',
            'void_reason' => 'Test reason',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.voids-export', [
                'from' => '2026-07-01',
                'to'   => '2026-07-04',
            ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Mã đặt phòng', $content);
        $this->assertStringContainsString('Người hủy', $content);
        $this->assertStringContainsString('250000', $content);
        $this->assertStringContainsString('Test reason', $content);
    }

    // -------------------------------------------------------------------------
    // 13. Authorization — authorized roles can view reconciliation
    // -------------------------------------------------------------------------

    public function test_admin_can_view_reconciliation_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reconciliation/Index', false)
                ->has('outstanding')
                ->has('discrepancies')
                ->has('filters')
                ->has('businessDate')
            );
    }

    public function test_manager_can_view_reconciliation(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $this->actingAs($manager)
            ->get(route('admin.reconciliation.index'))
            ->assertOk();
    }

    public function test_accountant_can_view_reconciliation(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');

        $this->actingAs($accountant)
            ->get(route('admin.reconciliation.index'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // 14. Authorization — unauthorized roles cannot view reconciliation
    // -------------------------------------------------------------------------

    public function test_reception_cannot_view_reconciliation(): void
    {
        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');

        $this->actingAs($reception)
            ->get(route('admin.reconciliation.index'))
            ->assertForbidden();
    }

    public function test_sales_cannot_view_reconciliation(): void
    {
        $sales = User::factory()->create();
        $sales->assignRole('SALES');

        $this->actingAs($sales)
            ->get(route('admin.reconciliation.index'))
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_view_reconciliation(): void
    {
        $this->get(route('admin.reconciliation.index'))->assertRedirect('/login');
    }

    // -------------------------------------------------------------------------
    // 15. Reconciliation does not mutate data
    // -------------------------------------------------------------------------

    public function test_reconciliation_service_does_not_mutate_any_records(): void
    {
        $this->addCharge(1_000_000);
        $this->addPayment(300_000);

        $folioEntryCountBefore  = FolioEntry::count();
        $bookingCountBefore     = Booking::count();
        $bookingPaymentCountBefore = BookingPayment::count();
        $folioCountBefore       = Folio::count();

        $service = $this->service();
        $service->outstandingBalances();
        $service->discrepancies();
        $service->voidedEntries(Carbon::now()->subDays(7), Carbon::now());

        $this->assertSame($folioEntryCountBefore, FolioEntry::count());
        $this->assertSame($bookingCountBefore, Booking::count());
        $this->assertSame($bookingPaymentCountBefore, BookingPayment::count());
        $this->assertSame($folioCountBefore, Folio::count());

        $this->booking->refresh();
        $this->assertSame(BookingStatus::CheckedIn, $this->booking->status);
        $this->folio->refresh();
        $this->assertSame(FolioStatus::Open, $this->folio->status);
    }

    // -------------------------------------------------------------------------
    // 16. Voids page is accessible and returns correct Inertia props
    // -------------------------------------------------------------------------

    public function test_admin_can_view_reconciliation_voids_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reconciliation.voids'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reconciliation/Voids', false)
                ->has('voids')
                ->has('filters')
                ->has('businessDate')
            );
    }

    // -------------------------------------------------------------------------
    // 17. Refund payment reduces paid_total in outstanding balance formula
    // -------------------------------------------------------------------------

    public function test_refund_reduces_paid_total_in_outstanding_balance(): void
    {
        $this->addCharge(1_000_000);
        $this->addPayment(800_000, PaymentType::Deposit);
        $this->addPayment(200_000, PaymentType::Refund); // effective paid = 600_000

        $rows = $this->service()->outstandingBalances();

        $this->assertCount(1, $rows);
        $this->assertSame(600_000.0, $rows[0]['paid_total']);
        $this->assertSame(400_000.0, $rows[0]['balance_due']);
    }

    // -------------------------------------------------------------------------
    // 18. Voided folio entries are excluded from outstanding balance calculation
    // -------------------------------------------------------------------------

    public function test_voided_charges_excluded_from_outstanding_balance_calculation(): void
    {
        $this->addCharge(500_000); // active
        $this->addCharge(999_000, ['voided_at' => now(), 'voided_by' => $this->admin->id]); // voided
        $this->addPayment(500_000); // fully pays the active charge

        $rows = $this->service()->outstandingBalances();

        $this->assertEmpty($rows); // voided charge not counted, so balance = 0
    }

    // -------------------------------------------------------------------------
    // 19. Voided entries date range filter — entries outside range excluded
    // -------------------------------------------------------------------------

    public function test_voided_entries_excludes_entries_outside_date_range(): void
    {
        FolioEntry::factory()->create([
            'folio_id'   => $this->folio->id,
            'amount'     => 111_000,
            'entry_date' => '2026-06-01',
            'voided_at'  => '2026-06-01 10:00:00', // outside range
        ]);

        FolioEntry::factory()->create([
            'folio_id'   => $this->folio->id,
            'amount'     => 222_000,
            'entry_date' => '2026-07-01',
            'voided_at'  => '2026-07-02 10:00:00', // inside range
        ]);

        $rows = $this->service()->voidedEntries(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-04'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame(222_000.0, $rows[0]['amount']);
    }
}
