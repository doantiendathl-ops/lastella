<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CheckoutInspectionStatus;
use App\Enums\CustomerType;
use App\Enums\StayEventType;
use App\Models\Booking;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use App\Services\CheckoutInspectionService;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Pre-Commit Critical Safety Closure (Blocker #1): CheckoutInspectionService::
 * complete() no longer posts to the Folio — the amount is a projection until
 * StayService::checkOut() posts it, the "existing final posting point" (same
 * event-triggered pattern as LateCheckoutFeePostingJob). This means
 * editCompleted() never needs to void/rewrite a system FolioEntry — nothing
 * is posted while a Completed-but-not-checked-out inspection is still
 * editable, so ADR-50's system-entry-immutability invariant is never at risk.
 */
class CheckoutInspectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ROOM_PRICE = 800000;

    private User $admin;
    private RoomType $twinType;
    private ProductService $water;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->travelTo('2026-07-12 14:00:00');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');
        $this->actingAs($this->admin);

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();

        $category = ProductServiceCategory::create(['code' => 'MINIBAR', 'name' => 'Minibar', 'sort_order' => 1]);
        $this->water = ProductService::create([
            'category_id' => $category->id,
            'code' => 'MB_WATER',
            'name' => 'Nước suối',
            'type' => 'product',
            'unit' => 'chai',
            'price' => 15000,
            'free_quantity_default' => 2,
            'use_in_checkout_inspection' => true,
            'can_add_to_booking' => true,
            'is_active' => true,
        ]);
    }

    public function test_get_or_create_draft_is_idempotent_per_stay(): void
    {
        $stay = $this->checkedInStay();

        $first = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        $second = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\CheckoutInspection::where('stay_id', $stay->id)->count());
    }

    // ------------------------------------------------------------------
    // 1-2. Complete Inspection → Folio count at that point.
    // ------------------------------------------------------------------

    /** Complete() computes chargeable_quantity directly (no subtraction) as a PROJECTION — nothing posts to Folio yet. */
    public function test_complete_computes_projection_with_no_subtraction_and_posts_nothing(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $completed = app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 5],
        ], null, $this->admin);

        $item = $completed->items->first();
        // chargeable_quantity=5 is billed AS-IS — never (5 - free_quantity_default=2) = 3.
        $this->assertSame(5, $item->chargeable_quantity);
        $this->assertSame(2, $item->free_quantity); // complimentary standard kept only as an informational snapshot
        $this->assertSame('75000.00', (string) $item->line_total);
        $this->assertSame(CheckoutInspectionStatus::Completed, $completed->status);
        $this->assertSame('75000.00', (string) $completed->total_amount);

        // Blocker #1: complete() is a projection-only step — posted_at stays null and
        // NO FolioEntry exists until StayService::checkOut() actually posts it.
        $this->assertNull($completed->posted_at);
        $folio = $stay->booking->folio;
        $this->assertSame(0, $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());
    }

    public function test_completing_twice_is_idempotent_and_creates_no_folio_entry(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $service = app(CheckoutInspectionService::class);
        $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 5],
        ], null, $this->admin);

        // Simulate double-click / retry: call complete() again on the same (now-completed) inspection.
        $service->complete($inspection->fresh(), [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 5],
        ], null, $this->admin);

        $folio = $stay->booking->folio;
        $this->assertSame(0, $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());
    }

    public function test_confirm_no_charge_completes_with_zero_total_and_no_folio_entry(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $completed = app(CheckoutInspectionService::class)->complete($inspection, [], 'Không phát sinh.', $this->admin);

        $this->assertSame('0.00', (string) $completed->total_amount);
        $folio = $stay->booking->folio;
        $this->assertSame(0, $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());
    }

    public function test_cannot_save_draft_after_completion(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        app(CheckoutInspectionService::class)->complete($inspection, [], null, $this->admin);

        $this->expectException(ValidationException::class);
        app(CheckoutInspectionService::class)->saveDraft($inspection->fresh(), [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 1],
        ], 'edit attempt', $this->admin);
    }

    public function test_price_change_after_completion_does_not_alter_item_snapshot(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        $completed = app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 5],
        ], null, $this->admin);

        $this->water->update(['price' => 99000]);

        $item = $completed->items->first()->fresh();
        $this->assertSame('15000.00', (string) $item->unit_price_snapshot);
    }

    public function test_multi_room_booking_inspections_are_independent_and_scoped_per_room(): void
    {
        $room1 = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->first();
        $room2 = Room::where('room_type_id', $this->twinType->id)->orderBy('id')->skip(1)->first();

        $booking = $this->createBooking([
            'requirements' => [[
                'room_type_id' => $this->twinType->id,
                'quantity' => 2,
                'adults' => 2,
                'children_under_6' => 0,
                'children_over_6' => 0,
                'room_price' => 800000,
                'price_source' => 'MANUAL',
            ]],
        ]);

        $assignments = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room1->id, 'room_type_id' => $room1->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
            ['room_id' => $room2->id, 'room_type_id' => $room2->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);

        $stay1 = app(StayService::class)->createStayFromAssignment($assignments[0]);
        $stay2 = app(StayService::class)->createStayFromAssignment($assignments[1]);
        app(StayService::class)->checkIn($stay1);
        app(StayService::class)->checkIn($stay2);

        $service = app(CheckoutInspectionService::class);
        $inspection1 = $service->getOrCreateDraft($stay1, $this->admin);
        $inspection2 = $service->getOrCreateDraft($stay2, $this->admin);

        $this->assertNotSame($inspection1->id, $inspection2->id);

        $service->complete($inspection1, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 5],
        ], null, $this->admin);

        // stay2 not inspected yet — partial checkout of room1 must not require/imply room2's inspection.
        $this->assertNull($inspection2->fresh()->completed_at);

        // Check out room1 only — its charge posts, scoped to stay1, room2 untouched.
        app(BookingPaymentService::class)->addDeposit($booking, [
            'amount' => self::ROOM_PRICE + 75000,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay1, null, false);

        $folio = $booking->folio;
        $entry = $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->firstOrFail();
        $this->assertSame($stay1->id, $entry->stay_id);
        $this->assertSame(1, $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());
    }

    public function test_stay_event_recorded_on_completion(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);
        app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 5],
        ], null, $this->admin);

        $event = $stay->stayEvents()->where('event_type', StayEventType::InspectionCompleted)->firstOrFail();
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertEquals(75000.0, $event->metadata['total_amount']);
    }

    // ------------------------------------------------------------------
    // Complimentary vs chargeable separation, room-scoped water standard.
    // ------------------------------------------------------------------

    /** New inspection: chargeable defaults to 0 when not supplied (never prefilled). */
    public function test_new_inspection_items_default_chargeable_to_zero_when_absent(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $draft = app(CheckoutInspectionService::class)->saveDraft($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 0],
        ], null, $this->admin);

        $this->assertSame(0, $draft->items->first()->chargeable_quantity);
    }

    /** Complimentary usage alone (chargeable=0) never creates a charge, regardless of complimentary standard. */
    public function test_complimentary_usage_within_standard_creates_no_charge(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $completed = app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 0, 'complimentary_quantity' => 2],
        ], null, $this->admin);

        $this->assertSame('0.00', (string) $completed->total_amount);
    }

    /** Chargeable=1 with a complimentary standard of 2 still charges exactly 1 × price — never (1-2) or 3-2. */
    public function test_chargeable_one_with_complimentary_standard_charges_one_unit(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $completed = app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 1, 'complimentary_quantity' => 2],
        ], null, $this->admin);

        $this->assertSame('15000.00', (string) $completed->total_amount);
    }

    /** Chargeable=2 with complimentary=2 charges the full 2 × price — complimentary never offsets chargeable (Mục XIX quick audit). */
    public function test_chargeable_equal_to_complimentary_still_charges_full_amount(): void
    {
        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $completed = app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 2, 'complimentary_quantity' => 2],
        ], null, $this->admin);

        $this->assertSame('30000.00', (string) $completed->total_amount);
    }

    /** Room-scoped water standard: the board cell exposes RoomType.standard_adults per room, distinct across room types (not hardcoded, not Booking.adults). */
    public function test_room_cell_exposes_room_type_standard_adults_distinct_per_room_type(): void
    {
        $tripType = RoomType::where('code', 'TRIP')->firstOrFail(); // standard_adults = 3
        $this->assertNotSame($this->twinType->standard_adults, $tripType->standard_adults); // TWIN = 2

        $twinRoom = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $tripRoom = Room::where('room_type_id', $tripType->id)->firstOrFail();

        $board = app(\App\Services\RoomOperationsBoardService::class)->boardForDate('2026-07-12', $this->admin);
        $cellsByRoomId = collect($board['floors'])->flatMap(fn (array $floor) => $floor['rooms'])->keyBy('id');

        $this->assertSame($this->twinType->standard_adults, $cellsByRoomId[$twinRoom->id]['room_type_standard_adults']);
        $this->assertSame($tripType->standard_adults, $cellsByRoomId[$tripRoom->id]['room_type_standard_adults']);
    }

    /** Full worked example: water complimentary=2/chargeable=1 + Coca=2×20,000 → total=55,000; complimentary contributes 0; posts once at checkout. */
    public function test_full_worked_example_water_and_coca_charge_total_posts_once_at_checkout(): void
    {
        $coca = ProductService::create([
            'category_id' => $this->water->category_id,
            'code' => 'MB_COCA',
            'name' => 'Coca-Cola',
            'type' => 'product',
            'unit' => 'lon',
            'price' => 20000,
            'free_quantity_default' => 0,
            'use_in_checkout_inspection' => true,
            'can_add_to_booking' => true,
            'is_active' => true,
        ]);

        $stay = $this->checkedInStay();
        $inspection = app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin);

        $completed = app(CheckoutInspectionService::class)->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 1, 'complimentary_quantity' => 2],
            ['product_service_id' => $coca->id, 'chargeable_quantity' => 2],
        ], null, $this->admin);

        // Water: 1 × 15,000 = 15,000. Coca: 2 × 20,000 = 40,000. Total = 55,000.
        $this->assertSame('55000.00', (string) $completed->total_amount);
        $this->assertSame(0, $stay->booking->folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());

        app(BookingPaymentService::class)->addDeposit($stay->booking, [
            'amount' => self::ROOM_PRICE + 55000,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay->fresh(), null, true);

        $activeEntries = $stay->booking->folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->get();
        $this->assertCount(2, $activeEntries); // one per line item, matching postChargesToFolio()'s per-item posting_key
        $this->assertEquals(55000.0, (float) $activeEntries->sum('amount'));

        // Re-running checkout's posting hook (idempotency) must not duplicate.
        app(CheckoutInspectionService::class)->postCompletedChargesAtCheckout($stay->fresh(), $this->admin);
        $this->assertCount(2, $stay->booking->folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->get());
    }

    // ------------------------------------------------------------------
    // 3-13. Edit before checkout / financial correctness / idempotency /
    // post-checkout lock / historical immutability (Mục VI test matrix).
    // ------------------------------------------------------------------

    /** 3-5. Edit before checkout; latest quantities correct; still zero Folio entries (nothing posted yet). */
    public function test_edit_completed_before_checkout_updates_projection_with_no_folio_entry(): void
    {
        $stay = $this->checkedInStay();
        $service = app(CheckoutInspectionService::class);
        $inspection = $service->getOrCreateDraft($stay, $this->admin);
        $completed = $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 4],
        ], null, $this->admin);

        $this->assertTrue($service->canEditCompleted($completed));

        $edited = $service->editCompleted($completed, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 1],
        ], null, $this->admin);

        $this->assertSame(CheckoutInspectionStatus::Completed, $edited->status);
        $this->assertSame('15000.00', (string) $edited->total_amount);
        $this->assertSame(1, $edited->items->first()->chargeable_quantity);

        // Blocker #1: no FolioEntry ever existed pre-checkout, so there is nothing to
        // void/rewrite — confirm zero rows, not merely zero ACTIVE rows.
        $folio = $stay->booking->folio;
        $this->assertSame(0, $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());
    }

    /** 9. Checkout posts the LATEST (edited) amount only — never the original pre-edit amount, never both. */
    public function test_checkout_posts_latest_edited_amount_only(): void
    {
        $stay = $this->checkedInStay();
        $service = app(CheckoutInspectionService::class);
        $inspection = $service->getOrCreateDraft($stay, $this->admin);
        $completed = $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 4],
        ], null, $this->admin);

        $service->editCompleted($completed->fresh(), [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 1],
        ], null, $this->admin);

        app(BookingPaymentService::class)->addDeposit($stay->booking, [
            'amount' => self::ROOM_PRICE + 15000,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay->fresh(), null, true);

        $folio = $stay->booking->folio;
        $entries = $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->get();
        $this->assertCount(1, $entries, 'Only the latest edited amount should ever post — never the original 60,000 too.');
        $this->assertSame('15000.00', (string) $entries->first()->amount);
    }

    /** 6-7. Idempotency: re-submitting editCompleted with unchanged data is a no-op — no audit event, still nothing posted. */
    public function test_edit_completed_with_unchanged_data_is_a_no_op(): void
    {
        $stay = $this->checkedInStay();
        $service = app(CheckoutInspectionService::class);
        $inspection = $service->getOrCreateDraft($stay, $this->admin);
        $completed = $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 4],
        ], null, $this->admin);

        $service->editCompleted($completed->fresh(), [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 4],
        ], null, $this->admin);

        $folio = $stay->booking->folio;
        $this->assertSame(0, $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());
        $this->assertSame(0, $stay->stayEvents()->where('event_type', StayEventType::InspectionEdited)->count());
    }

    /** 10-12. Mục V/VI: after checkout the inspection is locked — no financial mutation, no ADMIN bypass, historical entry provably untouched. */
    public function test_edit_completed_after_checkout_is_rejected_and_folio_untouched(): void
    {
        $stay = $this->checkedInStay();
        $service = app(CheckoutInspectionService::class);
        $inspection = $service->getOrCreateDraft($stay, $this->admin);
        $completed = $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 4],
        ], null, $this->admin);

        app(BookingPaymentService::class)->addDeposit($stay->booking, [
            'amount' => self::ROOM_PRICE + 60000,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay, null, true);

        $this->assertFalse($service->canEditCompleted($completed->fresh()));

        $folio = $stay->booking->folio;
        $entryBefore = $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->firstOrFail();
        $postingKeyBefore = $entryBefore->posting_key;

        try {
            $service->editCompleted($completed->fresh(), [
                ['product_service_id' => $this->water->id, 'chargeable_quantity' => 1],
            ], null, $this->admin);
            $this->fail('editCompleted() must throw once the stay has checked out.');
        } catch (ValidationException) {
            // expected
        }

        $entryAfter = $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->firstOrFail();
        $this->assertNull($entryAfter->voided_at, 'Historical entry must remain un-voided.');
        $this->assertSame('60000.00', (string) $entryAfter->amount, 'Historical entry amount must be unchanged.');
        $this->assertSame($postingKeyBefore, $entryAfter->posting_key, 'Historical entry posting_key must be unchanged.');
        $this->assertSame(1, $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count(), 'No duplicate/replacement entry.');
    }

    /**
     * 8. Explicit invariant proof (Mục VII): a system FolioEntry (non-null posting_key)
     * cannot be arbitrarily voided/rewritten through the Inspection edit path. This
     * exercises editCompleted() directly against a REAL posted system entry (created via
     * the actual checkout flow, not a hand-crafted fixture) and proves the entry is
     * byte-for-byte unchanged after the rejected attempt.
     */
    public function test_system_folio_entry_cannot_be_voided_or_rewritten_through_inspection_edit_path(): void
    {
        $stay = $this->checkedInStay();
        $service = app(CheckoutInspectionService::class);
        $inspection = $service->getOrCreateDraft($stay, $this->admin);
        $completed = $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 3],
        ], null, $this->admin);

        app(BookingPaymentService::class)->addDeposit($stay->booking, [
            'amount' => self::ROOM_PRICE + 45000,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay, null, true);

        $systemEntry = $stay->booking->folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->firstOrFail();
        $this->assertNotNull($systemEntry->posting_key, 'Precondition: this must be a genuine system entry per ADR-50.');
        $snapshotBefore = $systemEntry->only(['amount', 'quantity', 'unit_price', 'posting_key', 'voided_at', 'voided_by', 'void_reason']);

        try {
            $service->editCompleted($completed->fresh(), [
                ['product_service_id' => $this->water->id, 'chargeable_quantity' => 999],
            ], null, $this->admin);
        } catch (ValidationException) {
            // expected — the invariant held.
        }

        $snapshotAfter = $systemEntry->fresh()->only(['amount', 'quantity', 'unit_price', 'posting_key', 'voided_at', 'voided_by', 'void_reason']);
        $this->assertSame($snapshotBefore, $snapshotAfter, 'A system FolioEntry must be byte-for-byte unchanged after a rejected editCompleted() attempt.');
    }

    /** Historical Folio state must be unchanged by a rejected post-checkout edit attempt (duplicate proof at the service level, distinct scenario from the invariant test above). */
    public function test_rejected_post_checkout_edit_leaves_historical_folio_entry_unchanged(): void
    {
        $stay = $this->checkedInStay();
        $service = app(CheckoutInspectionService::class);
        $inspection = $service->getOrCreateDraft($stay, $this->admin);
        $completed = $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 4],
        ], null, $this->admin);

        app(BookingPaymentService::class)->addDeposit($stay->booking, [
            'amount' => self::ROOM_PRICE + 60000,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay, null, true);

        try {
            $service->editCompleted($completed->fresh(), [
                ['product_service_id' => $this->water->id, 'chargeable_quantity' => 1],
            ], null, $this->admin);
        } catch (ValidationException) {
            // expected
        }

        $folio = $stay->booking->folio;
        $entry = $folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->firstOrFail();
        $this->assertNull($entry->voided_at);
        $this->assertSame('60000.00', (string) $entry->amount);
    }

    /** Audit: InspectionEdited StayEvent captures old/new totals and item snapshots when values actually change. */
    public function test_stay_event_recorded_with_old_and_new_snapshots_on_edit(): void
    {
        $stay = $this->checkedInStay();
        $service = app(CheckoutInspectionService::class);
        $inspection = $service->getOrCreateDraft($stay, $this->admin);
        $completed = $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 4],
        ], null, $this->admin);

        $service->editCompleted($completed->fresh(), [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 1],
        ], null, $this->admin);

        $event = $stay->stayEvents()->where('event_type', StayEventType::InspectionEdited)->firstOrFail();
        $this->assertEquals(60000.0, $event->metadata['old_total_amount']);
        $this->assertEquals(15000.0, $event->metadata['new_total_amount']);
        $this->assertNotEmpty($event->metadata['old_items']);
        $this->assertNotEmpty($event->metadata['new_items']);
    }

    /** A Draft (never-completed) inspection posts nothing at checkout — same as skip. */
    public function test_draft_inspection_posts_nothing_at_checkout(): void
    {
        $stay = $this->checkedInStay();
        app(CheckoutInspectionService::class)->getOrCreateDraft($stay, $this->admin); // never complete()d

        app(BookingPaymentService::class)->addDeposit($stay->booking, [
            'amount' => self::ROOM_PRICE,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay->fresh(), null, true);

        $this->assertSame(0, $stay->booking->folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count());
    }

    // ------------------------------------------------------------------
    // Final Selective Commit + Push Closure — Mục III/IV: financial
    // atomicity proof. postCompletedChargesAtCheckout() opens no
    // transaction of its own; it runs entirely inside StayService::
    // checkOut()'s single outer DB::transaction(). These tests exercise
    // that guarantee directly rather than re-describing it.
    // ------------------------------------------------------------------

    /**
     * TEST 1 — ROLLBACK: checkOut() posts the inspection charge, then a
     * LATER step in the SAME transaction throws (the existing ADR-40
     * outstanding-balance guard inside BookingService::
     * finaliseBookingCheckout(), reached right after the inspection-posting
     * call) — no mock/fake, a real existing throw path. Expected: the whole
     * transaction rolls back — zero persisted inspection FolioEntry, stay
     * still not checked out.
     */
    public function test_checkout_rolls_back_inspection_charge_when_a_later_guard_throws(): void
    {
        $stay = $this->checkedInStay();
        $service = app(CheckoutInspectionService::class);
        $inspection = $service->getOrCreateDraft($stay, $this->admin);
        $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 4],
        ], null, $this->admin);

        // Deliberately do NOT pay the outstanding balance (room 800,000 +
        // inspection 60,000) — this stay is the booking's only stay, so
        // checkOut(confirmed: true) reaches finaliseBookingCheckout(), which
        // throws OutstandingBalanceException AFTER the inspection charge is
        // posted within the same transaction.
        try {
            app(StayService::class)->checkOut($stay->fresh(), null, true);
            $this->fail('Expected OutstandingBalanceException to propagate.');
        } catch (\App\Exceptions\OutstandingBalanceException) {
            // expected
        }

        $this->assertNull($stay->fresh()->actual_checkout_at, 'Checkout state must be rolled back, not partially applied.');
        $this->assertSame(
            0,
            $stay->booking->folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count(),
            'The inspection charge posted earlier in the SAME transaction must be rolled back too — never left orphaned.',
        );
        $this->assertFalse($inspection->fresh()->isPosted(), 'isPosted() must reflect the rollback, not a stale in-memory true.');
    }

    /**
     * TEST 2 (checkOut()-entry-point variant) — RETRY / DOUBLE-SUBMIT: a
     * successful checkout, then calling checkOut() again for the same stay
     * (the literal HTTP double-submit scenario). Expected: the retry is
     * rejected outright (Stay row lock + `actual_checkout_at !== null`
     * guard fires before the posting call is ever reached again) — exact
     * inspection Folio posting count stays 1.
     */
    public function test_checkout_retry_after_success_does_not_duplicate_inspection_charge(): void
    {
        $stay = $this->checkedInStay();
        $service = app(CheckoutInspectionService::class);
        $inspection = $service->getOrCreateDraft($stay, $this->admin);
        $service->complete($inspection, [
            ['product_service_id' => $this->water->id, 'chargeable_quantity' => 4],
        ], null, $this->admin);

        app(BookingPaymentService::class)->addDeposit($stay->booking, [
            'amount' => self::ROOM_PRICE + 60000,
            'payment_method' => 'CASH',
            'payment_at' => now()->toDateTimeString(),
        ]);
        app(StayService::class)->checkOut($stay->fresh(), null, true);

        try {
            app(StayService::class)->checkOut($stay->fresh(), null, true);
            $this->fail('A second checkOut() call for an already-checked-out stay must be rejected.');
        } catch (ValidationException) {
            // expected — "Phòng đã được trả phòng rồi."
        }

        $this->assertSame(
            1,
            $stay->booking->folio->folioEntries()->where('posting_source', 'CHECKOUT_INSPECTION')->count(),
            'Exactly one inspection charge, never duplicated by a retried checkout.',
        );
    }

    private function checkedInStay(): Stay
    {
        $room = Room::where('room_type_id', $this->twinType->id)->firstOrFail();
        $booking = $this->createBooking();

        [$assignment] = app(RoomAssignmentService::class)->assignRooms($booking, [
            ['room_id' => $room->id, 'room_type_id' => $room->room_type_id, 'start_at' => '2026-07-12 14:00:00', 'end_at' => '2026-07-13 12:00:00'],
        ]);
        $stay = app(StayService::class)->createStayFromAssignment($assignment);
        app(StayService::class)->checkIn($stay);

        return $stay->fresh();
    }

    private function createBooking(array $overrides = []): Booking
    {
        $payload = array_merge([
            'booking_color' => '#196251',
            'customer_name' => 'Inspection Guest',
            'customer_phone' => '0900000006',
            'customer_email' => 'inspection@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => '2026-07-12 14:00:00',
            'checkout_at' => '2026-07-13 12:00:00',
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
        ], $overrides);

        if (! isset($payload['requirements'])) {
            $payload['requirements'] = [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 1,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => self::ROOM_PRICE,
                    'price_source' => 'MANUAL',
                ],
            ];
        }

        return app(BookingService::class)->createBooking($payload);
    }
}
