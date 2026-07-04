<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ChargeType;
use App\Enums\FolioStatus;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Room;
use App\Models\ServiceRate;
use App\Models\Stay;
use App\Models\User;
use App\Services\PostingTimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingTimelineTest extends TestCase
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
    }

    private function service(): PostingTimelineService
    {
        return app(PostingTimelineService::class);
    }

    private function addEntry(float $amount, array $overrides = []): FolioEntry
    {
        return FolioEntry::factory()->create(array_merge([
            'folio_id'    => $this->folio->id,
            'charge_type' => ChargeType::Room,
            'amount'      => $amount,
            'entry_date'  => '2026-07-01',
            'voided_at'   => null,
        ], $overrides));
    }

    private function addVoidedEntry(float $amount, array $overrides = []): FolioEntry
    {
        return FolioEntry::factory()->create(array_merge([
            'folio_id'    => $this->folio->id,
            'charge_type' => ChargeType::Room,
            'amount'      => $amount,
            'entry_date'  => '2026-07-01',
            'voided_at'   => now(),
            'void_reason' => 'Nhập sai thông tin',
        ], $overrides));
    }

    private function makeServiceRate(array $overrides = []): ServiceRate
    {
        return ServiceRate::create(array_merge([
            'name'           => 'Test rate',
            'charge_type'    => ChargeType::Spa->value,
            'unit_price'     => 200_000,
            'effective_from' => '2026-01-01',
            'is_active'      => true,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // 1. Timeline returns entries in chronological order
    // -------------------------------------------------------------------------

    public function test_timeline_returns_entries_in_chronological_order(): void
    {
        $e1 = $this->addEntry(100_000, ['entry_date' => '2026-07-03']);
        $e2 = $this->addEntry(200_000, ['entry_date' => '2026-07-01']);
        $e3 = $this->addEntry(300_000, ['entry_date' => '2026-07-02']);

        $rows = $this->service()->forFolio($this->folio);

        $this->assertCount(3, $rows);
        $this->assertSame($e2->id, $rows[0]['id']); // July 1 first
        $this->assertSame($e3->id, $rows[1]['id']); // July 2 second
        $this->assertSame($e1->id, $rows[2]['id']); // July 3 last
    }

    // -------------------------------------------------------------------------
    // 2. Timeline includes posting_source from the database
    // -------------------------------------------------------------------------

    public function test_timeline_includes_posting_source(): void
    {
        $this->addEntry(100_000, ['posting_source' => 'NIGHT_AUDIT']);

        $rows = $this->service()->forFolio($this->folio);

        $this->assertCount(1, $rows);
        $this->assertSame('NIGHT_AUDIT', $rows[0]['posting_source']);
    }

    // -------------------------------------------------------------------------
    // 3. DB-default posting_source (MANUAL) is returned as-is; service also
    //    guards against NULL for legacy safety via null-coalescing to MANUAL
    // -------------------------------------------------------------------------

    public function test_default_posting_source_returns_manual(): void
    {
        // posting_source column has DB default 'MANUAL'; no override → uses default
        $this->addEntry(100_000);

        $rows = $this->service()->forFolio($this->folio);

        $this->assertCount(1, $rows);
        $this->assertSame('MANUAL', $rows[0]['posting_source']);
    }

    // -------------------------------------------------------------------------
    // 4. Timeline includes room number for stay-linked entries
    // -------------------------------------------------------------------------

    public function test_timeline_includes_room_number_for_stay_linked_entries(): void
    {
        $room = Room::factory()->create(['room_number' => '101']);
        $stay = Stay::factory()->create(['room_id' => $room->id]);
        $this->addEntry(100_000, ['stay_id' => $stay->id]);

        $rows = $this->service()->forFolio($this->folio);

        $this->assertCount(1, $rows);
        $this->assertSame('101', $rows[0]['room_number']);
        $this->assertSame($stay->id, $rows[0]['stay_id']);
    }

    // -------------------------------------------------------------------------
    // 5. Timeline includes voided entries when includeVoided=true
    // -------------------------------------------------------------------------

    public function test_timeline_includes_voided_entries_when_include_voided_is_true(): void
    {
        $this->addEntry(100_000);
        $this->addVoidedEntry(50_000);

        $rows = $this->service()->forFolio($this->folio, includeVoided: true);

        $this->assertCount(2, $rows);
        $voided = array_filter($rows, fn ($r) => $r['is_voided']);
        $this->assertCount(1, $voided);
    }

    // -------------------------------------------------------------------------
    // 6. Timeline excludes voided entries when includeVoided=false
    // -------------------------------------------------------------------------

    public function test_timeline_excludes_voided_entries_when_include_voided_is_false(): void
    {
        $this->addEntry(100_000);
        $this->addVoidedEntry(50_000);

        $rows = $this->service()->forFolio($this->folio, includeVoided: false);

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['is_voided']);
    }

    // -------------------------------------------------------------------------
    // 7. Running total excludes voided entries
    // -------------------------------------------------------------------------

    public function test_running_total_excludes_voided_entries(): void
    {
        $this->addEntry(300_000, ['entry_date' => '2026-07-01']);
        $this->addVoidedEntry(200_000, ['entry_date' => '2026-07-02']);

        $rows = $this->service()->forFolio($this->folio, includeVoided: true);

        $this->assertCount(2, $rows);
        $this->assertSame('300000.00', $rows[0]['running_total']); // active entry
        $this->assertSame('300000.00', $rows[1]['running_total']); // voided does not change total
    }

    // -------------------------------------------------------------------------
    // 8. Running total accumulates non-voided entries in order
    // -------------------------------------------------------------------------

    public function test_running_total_accumulates_non_voided_entries_in_order(): void
    {
        $this->addEntry(100_000, ['entry_date' => '2026-07-01']);
        $this->addEntry(200_000, ['entry_date' => '2026-07-02']);
        $this->addEntry(300_000, ['entry_date' => '2026-07-03']);

        $rows = $this->service()->forFolio($this->folio);

        $this->assertSame('100000.00', $rows[0]['running_total']);
        $this->assertSame('300000.00', $rows[1]['running_total']);
        $this->assertSame('600000.00', $rows[2]['running_total']);
    }

    // -------------------------------------------------------------------------
    // 9. Timeline service does not mutate folio entries (read-only, ADR-78)
    // -------------------------------------------------------------------------

    public function test_timeline_service_does_not_mutate_folio_entries(): void
    {
        $this->addEntry(100_000);
        $this->addVoidedEntry(50_000);

        $before = FolioEntry::count();
        $this->service()->forFolio($this->folio, includeVoided: true);
        $after  = FolioEntry::count();

        $this->assertSame($before, $after);
        $this->assertDatabaseHas('folio_entries', ['amount' => 100_000, 'voided_at' => null]);
    }

    // -------------------------------------------------------------------------
    // 10. Service rate history returns all versions for a charge type
    // -------------------------------------------------------------------------

    public function test_service_rate_history_returns_all_versions_for_charge_type(): void
    {
        $this->makeServiceRate(['effective_from' => '2025-01-01', 'charge_type' => ChargeType::Spa->value]);
        $this->makeServiceRate(['effective_from' => '2026-01-01', 'charge_type' => ChargeType::Spa->value]);
        $this->makeServiceRate(['charge_type' => ChargeType::Laundry->value]); // different type

        $this->actingAs($this->admin)
            ->get(route('admin.service-rates.history', ChargeType::Spa->value))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ServiceRates/History', false)
                ->has('rates', 2)
                ->where('chargeType', ChargeType::Spa->value)
            );
    }

    // -------------------------------------------------------------------------
    // 11. Service rate history ordered by effective_from DESC
    // -------------------------------------------------------------------------

    public function test_service_rate_history_ordered_by_effective_from_desc(): void
    {
        $this->makeServiceRate(['effective_from' => '2025-01-01', 'charge_type' => ChargeType::Spa->value]);
        $this->makeServiceRate(['effective_from' => '2026-01-01', 'charge_type' => ChargeType::Spa->value]);
        $this->makeServiceRate(['effective_from' => '2024-01-01', 'charge_type' => ChargeType::Spa->value]);

        $this->actingAs($this->admin)
            ->get(route('admin.service-rates.history', ChargeType::Spa->value))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ServiceRates/History', false)
                ->where('rates.0.effective_from', '2026-01-01')
                ->where('rates.1.effective_from', '2025-01-01')
                ->where('rates.2.effective_from', '2024-01-01')
            );
    }

    // -------------------------------------------------------------------------
    // 12. Service rate history includes inactive versions
    // -------------------------------------------------------------------------

    public function test_service_rate_history_includes_inactive_versions(): void
    {
        $this->makeServiceRate(['is_active' => true,  'charge_type' => ChargeType::Spa->value, 'effective_from' => '2026-01-01']);
        $this->makeServiceRate(['is_active' => false, 'charge_type' => ChargeType::Spa->value, 'effective_from' => '2025-01-01']);

        $this->actingAs($this->admin)
            ->get(route('admin.service-rates.history', ChargeType::Spa->value))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ServiceRates/History', false)
                ->has('rates', 2)
            );
    }

    // -------------------------------------------------------------------------
    // 13. Unauthorized user cannot view rate history
    // -------------------------------------------------------------------------

    public function test_unauthorized_user_cannot_view_rate_history(): void
    {
        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');

        $this->actingAs($reception)
            ->get(route('admin.service-rates.history', ChargeType::Spa->value))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // 14. Admin can access timeline and sees voided entries
    // -------------------------------------------------------------------------

    public function test_admin_can_access_timeline_and_sees_voided_entries(): void
    {
        $this->addEntry(100_000);
        $this->addVoidedEntry(50_000);

        $this->actingAs($this->admin)
            ->get(route('admin.bookings.timeline', $this->booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Booking/PostingTimeline', false)
                ->has('timeline', 2)
                ->where('includeVoided', true)
            );
    }

    // -------------------------------------------------------------------------
    // 15. Reception user sees timeline without voided entries
    // -------------------------------------------------------------------------

    public function test_reception_user_sees_timeline_without_voided_entries(): void
    {
        $this->addEntry(100_000);
        $this->addVoidedEntry(50_000);

        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');

        $this->actingAs($reception)
            ->get(route('admin.bookings.timeline', $this->booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Booking/PostingTimeline', false)
                ->has('timeline', 1)
                ->where('includeVoided', false)
            );
    }

    // -------------------------------------------------------------------------
    // 16. User without folio.view cannot access timeline
    // -------------------------------------------------------------------------

    public function test_user_without_folio_view_cannot_access_timeline(): void
    {
        $user = User::factory()->create(); // no role = no permissions

        $this->actingAs($user)
            ->get(route('admin.bookings.timeline', $this->booking))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // 17. Invalid charge type returns 422 on rate history endpoint
    // -------------------------------------------------------------------------

    public function test_invalid_charge_type_returns_unprocessable_on_rate_history(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.service-rates.history', 'NOT_A_REAL_TYPE'))
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // 18. Manager can access rate history via existing service_rates.manage permission
    // -------------------------------------------------------------------------

    public function test_manager_can_access_rate_history(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $this->makeServiceRate(['charge_type' => ChargeType::Spa->value]);

        $this->actingAs($manager)
            ->get(route('admin.service-rates.history', ChargeType::Spa->value))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ServiceRates/History', false)
                ->where('chargeType', ChargeType::Spa->value)
            );
    }
}
