# Phase 3.1.1 Hotfix Report

**Date:** 2026-07-02
**Branch:** phase-3
**Status:** Ready for commit review

---

## Root Cause

### Bug 1 — Room charge quantity missing nights multiplier

`FolioService::autoPostRoomCharge()` computed the room charge as:

```
amount = sum(room_price × rooms_qty)
```

— a per-night-per-room total — but passed it to `doPostRoomCharge()` which hardcoded
`quantity = '1.00'` and `unit_price = $amount`. The number of nights was never factored
in. A two-night booking at 2,100,000 VND/night posted a single entry of 2,100,000 VND
instead of 4,200,000 VND.

The same bug existed in `calculateGuardedFolioTotal()`'s ADR-11 fallback estimate: it
used `sum(room_price × rooms_qty)` without `× nights`, so the displayed balance_due also
understated multi-night charges before check-in.

Because both the posted entry AND the estimate were wrong by the same factor, the OBE
guard was satisfied by paying only one night's charge. The hotel under-charged by
`(nights − 1) × per_night_total` for every multi-night booking.

All existing tests used 1-night bookings (`checkin_at = '2026-07-01 14:00:00'`,
`checkout_at = '2026-07-02 12:00:00'`), so `nights = 1` and the bug was invisible.

### Bug 2 — "Trả tất cả phòng" partial checkout when balance > 0

`RoomBoardPanel.vue::checkOutAll()` used `window.confirm` as a balance "warning" that
the user could click through, then proceeded to check out all stays sequentially. Non-last
stays have no server-side balance guard (OBE only fires on `finaliseBookingCheckout()`,
which runs only when the last active stay checks out). So N-1 stays would check out
successfully, then the last stay would fail with `OutstandingBalanceException`. The booking
was left in `PARTIALLY_CHECKED_OUT` state with N-1 rooms checked out and 1 still active —
an unrecoverable partial state that required manual intervention.

---

## Files Changed

| File | Change |
|------|--------|
| `app/Services/FolioService.php` | Fix nights calculation in `autoPostRoomCharge()`, `doPostRoomCharge()` signature, and `calculateGuardedFolioTotal()` ADR-11 estimate |
| `resources/js/Pages/Admin/Bookings/Partials/RoomBoardPanel.vue` | Render "Trả tất cả phòng" as disabled `<span>` when `balance_due > 0`; remove `window.confirm` bypass from `checkOutAll()` |
| `tests/Feature/RoomChargeHotfixTest.php` | 14 new tests covering both bugs |

---

## Fix Details

### `FolioService.php`

**`autoPostRoomCharge()`** — now computes `perNightTotal` (sum of room_price × qty for
all requirements) and `nights` (calendar-day difference via `.copy().startOfDay().diffInDays()`,
minimum 1), then derives `totalAmount = perNightTotal × nights`.

**`doPostRoomCharge()`** — signature extended to accept `$perNightTotal`, `$nights`,
`$totalAmount`. Posts:
```
quantity  = nights
unit_price = perNightTotal
amount    = totalAmount
description = "Tiền phòng (tổng hợp - {nights} đêm)"
```

**`calculateGuardedFolioTotal()`** — ADR-11 estimate now also applies the nights multiplier
so the pre-check-in balance_due matches what will be posted.

Night counting uses `copy()->startOfDay()` (NOT bare `startOfDay()`) to avoid mutating
the Carbon datetime cached by Eloquent. Calendar-day difference (e.g. Jun-30 14:00 →
Jul-02 12:00 = 2 nights) follows hotel convention, not clock hours.

### `RoomBoardPanel.vue`

- Added `checkOutAllDisabled = computed(() => (paymentSummary?.balance_due ?? 0) > 0)`
- Template renders a `cursor-not-allowed` `<span>` with tooltip when disabled, `<button>` otherwise
- `checkOutAll()` simplified: removed `window.confirm` block — template-level disabled
  state is the UX guard; the server's OBE is the authoritative guard (ADR-52)

---

## Tests Added / Updated

**File:** `tests/Feature/RoomChargeHotfixTest.php` (14 tests, all new)

| Test | Covers |
|------|--------|
| `test_two_night_booking_posts_room_charge_quantity_of_two` | Req 1: quantity = nights |
| `test_two_night_booking_room_charge_total_equals_nightly_price_times_nights` | Req 2: amount = price × nights |
| `test_one_night_booking_still_posts_correct_single_night_charge` | Regression: 1-night unchanged |
| `test_three_night_booking_posts_correct_quantity_and_amount` | 3-night variation |
| `test_multi_room_booking_computes_total_room_charge_correctly` | Req 3: multi-room per-night-total × nights |
| `test_guarded_folio_total_estimate_includes_nights_multiplier` | ADR-11 estimate fix |
| `test_two_night_checkout_succeeds_after_paying_correct_amount` | Req 5 (adapted): succeed after full payment |
| `test_two_night_checkout_blocked_when_only_one_night_paid` | Req 5: blocked when underpaid |
| `test_checkout_all_succeeds_for_multi_stay_booking_when_balance_is_zero` | Req 5: checkOutAll succeeds |
| `test_checkout_all_last_stay_blocked_by_obe_when_balance_outstanding` | Req 4: OBE on last stay |
| `test_partial_checkout_booking_can_checkout_remaining_rooms_after_payment` | Req 6: partial → pay → complete |
| `test_released_room_assignment_cannot_be_checked_out` | Req 7: released room protection |
| `test_checkout_all_http_blocked_when_balance_outstanding` | Req 4 HTTP layer: OBE redirect |
| `test_checkout_all_http_succeeds_after_full_payment` | Req 5 HTTP layer: success redirect |

---

## Test Results

```
✓ All 14 hotfix tests pass
✓ All 80 existing folio/checkout/payment tests pass (CheckoutIntegrationTest,
  CheckoutUiTest, FolioUiTest, FolioCrudTest, PaymentCrudTest, PaymentUiTest)
  Total: 94 tests, 267 assertions, 0 failures
```

**Pre-existing unrelated failures:** 12 tests in `BookingManagementUiTest` (room board
availability feature) — confirmed failing on the same commit before this hotfix. Not
introduced by these changes.

---

## Phase 3.1 Invariants Preserved

| ADR | Invariant | Status |
|-----|-----------|--------|
| ADR-16 | Server-side amount computation (bcmath) | ✓ Unchanged |
| ADR-11 | Guarded folio total estimates room charge | ✓ Fixed to include nights |
| ADR-40 | OBE guard on checkout | ✓ Unchanged — still authoritative |
| ADR-27 | autoPostRoomCharge is the only public entry point | ✓ Unchanged |
| ADR-50 | System entries immutable (void guard) | ✓ Unchanged |
| ADR-52 | Disabled state is UX only; OBE is authoritative | ✓ Strengthened in Vue |
| ADR-53 | Controller catches OBE → payments tab redirect | ✓ Unchanged |
| — | Released-room protection | ✓ Preserved |
| — | Assignment/check-in/check-out concurrency (lockForUpdate) | ✓ Unchanged |

---

## Safe to Commit as phase-3.1.1?

**Yes.** All 94 affected tests pass. Both bugs are fixed without touching any Phase 3.1
ADR constraints. The Vue change is purely UX hardening that aligns with the existing
ADR-52 + ADR-53 server-side protection. The PHP fix correctly implements hotel-night
billing that was always the intended behavior per the domain model (`room_price` field
is documented as per-night in the migration).
