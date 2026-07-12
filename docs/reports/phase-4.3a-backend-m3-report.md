# Phase 4.3A — Backend Milestone 3 — Partial Checkout — Report

**Date:** 2026-07-12
**Branch:** phase-3
**Type:** Implementation (Milestone 3 of 5 — Partial Checkout only)
**Reference:** `docs/implementation-plans/phase-4.3a-stay-foundation-implementation-plan.md` §9
**Status:** COMPLETE — not committed, not pushed. Awaiting ChatGPT review before Milestone 4.

---

## 1. Executive Summary

Milestone 3 formalizes a distinction the system already makes correctly but never recorded: whether a checkout was *partial* (other Stays on the Booking remain active) or *final* (the last active Stay checks out). No new checkout method was written, no existing behavior changed — the only production-code change is two `StayEventService::record()` calls inserted into the two branches of an `if`/`else` that `StayService::checkOut()` already had. `StayEventType` gains two cases: `PartialCheckout` and `Checkout`. No new route, permission, policy method, or Form Request was introduced — this milestone reuses the existing checkout endpoint, request, and authorization entirely.

---

## 2. Business Capability Formalized

- **Partial Checkout:** one Stay checks out while ≥1 other Stay on the same Booking remains `Reserved`/`CheckedIn`. Booking stays active (`PartiallyCheckedOut`), Folio stays open, other Stays are untouched. A `PartialCheckout` `StayEvent` is recorded for the checked-out Stay.
- **Final Checkout:** the last active Stay on the Booking checks out. Existing finalization behavior (Booking → terminal status, Folio → closed) runs exactly as before. A `Checkout` `StayEvent` is recorded instead of `PartialCheckout`.

Both outcomes were already correct in the system before this milestone (per the Gap Analysis and Go-live Scope Review) — this milestone adds the audit record, nothing else.

---

## 3. Files Modified

| File | Change |
|------|--------|
| `app/Enums/StayEventType.php` | +2 cases: `PartialCheckout = 'PARTIAL_CHECKOUT'`, `Checkout = 'CHECKOUT'`, + Vietnamese labels `'Trả phòng một phần'` / `'Trả phòng'` |
| `app/Services/StayService.php` | +14 lines inside the **existing** `if ($remainingActive === 0) { ... } else { ... }` branch of `checkOut()` — one `stayEvents->record()` call added to each branch. No other line in `checkOut()` changed. |
| `tests/Feature/CheckoutIntegrationTest.php` | +19 lines — extended 2 pre-existing tests (`test_checkout_transitions_booking_to_checked_out_when_balance_is_zero`, `test_partial_checkout_of_multi_stay_booking_sets_partially_checked_out`) with `StayEvent` assertions. No pre-existing assertion in either test was changed or removed. |

**No file outside this list was touched.** No new route, no new permission, no new policy method, no new Form Request, no new controller action.

---

## 4. Event Types Added

```php
case PartialCheckout = 'PARTIAL_CHECKOUT';
case Checkout        = 'CHECKOUT';
```

Both were required (not just one) because the milestone's entire purpose is to **distinguish** the two outcomes in the audit log — a single shared case would defeat that purpose. `Checkout` was not deferred to a later milestone because it is the natural counterpart already produced by the same branch this milestone retrofits; adding only `PartialCheckout` would have left the "last room checks out" path unaudited, which is not a smaller diff, just an incomplete one.

---

## 5. Existing Checkout Logic Reused

**No new checkout method was created.** The retrofit is entirely inside the pre-existing branch:

```php
if ($remainingActive === 0) {
    // ADR-48: finalise runs inside caller's transaction; Booking lock already held.
    $this->bookings->finaliseBookingCheckout($lockedBooking);
    $this->stayEvents->record($lockedStay, StayEventType::Checkout, Auth::user(), [
        'version' => 1,
        'booking_id' => $lockedBooking->id,
        'room_assignment_id' => $assignment->id,
        'remaining_active_stays' => $remainingActive,
    ]);
} else {
    $this->bookings->updateBookingStayStatus($lockedBooking);
    $this->stayEvents->record($lockedStay, StayEventType::PartialCheckout, Auth::user(), [
        'version' => 1,
        'booking_id' => $lockedBooking->id,
        'room_assignment_id' => $assignment->id,
        'remaining_active_stays' => $remainingActive,
    ]);
}
```

Every existing line — the `finaliseBookingCheckout()`/`updateBookingStayStatus()` calls, the `$remainingActive` computation above it, the balance check, the late-checkout-fee posting, the housekeeping hook below it — is byte-for-byte unchanged. `git diff` confirms this is the entire production-code change for this milestone.

---

## 6. Partial vs Final Checkout Decision

The `StayEvent` type is derived from the **same** `$remainingActive` value `checkOut()` already computes to choose between `finaliseBookingCheckout()` and `updateBookingStayStatus()` — no new query, no second competing rule. This was an explicit instruction and an explicit review criterion: the event type must *observe* the existing decision, not introduce a new one. `remaining_active_stays` in the event metadata is literally the same variable used in the `if` condition, not a re-derived count.

---

## 7. Event Metadata

```json
{
  "version": 1,
  "booking_id": 123,
  "room_assignment_id": 456,
  "remaining_active_stays": 2
}
```

(`remaining_active_stays: 0` for the `Checkout` case.) All four fields are always present — none are optional for this milestone. `source`, `reported_by`, and `note` remain supported by `StayEventService`'s freeform metadata parameter (unchanged since Milestone 1) but are not populated here, since no scenario in this milestone needs them. `actor` is always the authenticated user (`Auth::user()`) — never a fake/system actor. Event-timeline queries in tests use `orderBy('occurred_at')->orderBy('id')`, per the established ordering convention; no new database column was added for ordering.

---

## 8. Tests Added / Updated

| File | New/Updated | Covers |
|------|-------------|--------|
| `tests/Feature/PartialCheckoutStayEventTest.php` (new, 8 tests) | New | Partial checkout event + metadata + actor; final checkout event + metadata; partial checkout leaves Booking active (not finalized); partial checkout leaves Folio open; partial checkout leaves other Stays byte-identical; no child Booking; no new payment row; no new route/permission exists |
| `tests/Feature/CheckoutIntegrationTest.php` (2 tests extended) | Updated | Final checkout → `Checkout` event exists (extends `test_checkout_transitions_booking_to_checked_out_when_balance_is_zero`); partial checkout → `PartialCheckout` event exists, no `Checkout` event, sibling Stay untouched (extends `test_partial_checkout_of_multi_stay_booking_sets_partially_checked_out`) |

**Total new/updated: 8 new tests + 2 extended tests, 0 pre-existing assertions removed or altered.**

Every scenario listed in the milestone instructions is covered:

| # | Scenario | Test |
|---|----------|------|
| 1 | Partial checkout records `PartialCheckout` | `PartialCheckoutStayEventTest::test_partial_checkout_records_partial_checkout_event_with_correct_metadata_and_actor` |
| 2 | Final checkout records `Checkout` | `test_final_checkout_records_checkout_event_with_correct_metadata` |
| 3 | Partial checkout leaves Booking active | `test_partial_checkout_leaves_booking_active_and_does_not_finalize` |
| 4 | Produces `PartiallyCheckedOut` aggregate | Same test + pre-existing `CheckoutIntegrationTest::test_partial_checkout_of_multi_stay_booking_sets_partially_checked_out` |
| 5 | Does not finalize Booking | `test_partial_checkout_leaves_booking_active_and_does_not_finalize` |
| 6 | Does not close Folio | `test_partial_checkout_does_not_close_folio` |
| 7 | Other active Stays unchanged | `test_partial_checkout_leaves_other_active_stays_unchanged` |
| 8 | Final checkout still finalizes exactly as before | Pre-existing `test_checkout_transitions_booking_to_checked_out_when_balance_is_zero` (unchanged assertions) + `test_folio_auto_closes_at_last_stay_checkout` |
| 9 | Final checkout still follows existing Folio behavior | Same, plus `LateCheckoutFeeTest` (unchanged, re-run) |
| 10 | Event actor correct | Both metadata tests assert `actor_id` |
| 11 | Metadata fields present | Both metadata tests assert `version`/`booking_id`/`room_assignment_id`/`remaining_active_stays` |
| 12 | Partial/final use correct event type | Both metadata tests + extended `CheckoutIntegrationTest` assertions |
| 13 | No child Booking | `test_partial_checkout_creates_no_child_booking` |
| 14 | No new payment row | `test_partial_checkout_creates_no_new_payment_row` |
| 15 | Late-checkout fee unchanged | `LateCheckoutFeeTest` (6 tests, unchanged, re-run — all pass) |
| 16 | Housekeeping checkout hook unchanged | `StayServiceHousekeepingHookTest::test_checkout_auto_creates_cleaning_assignment` (unchanged, re-run — passes) |
| 17 | Room-status transition unchanged | Same test (asserts `VACANT_DIRTY`) |
| 18 | Checkout authorization unchanged | No `StayPolicy`/`CheckOutStayRequest` file touched; existing checkout HTTP tests unaffected |
| 19 | Checkout validation unchanged | Same — `CheckOutStayRequest` untouched |
| 20 | No new route or permission | `test_no_new_route_or_permission_introduced_for_partial_checkout` (asserts no route name contains `"partial"`, no `stay.partial_checkout` permission row exists) |

---

## 9. Targeted Test Results

Run before the full suite, exactly the set named in the milestone instructions:

| Suite | Result |
|-------|--------|
| `CheckoutIntegrationTest` (13, 2 extended) | ✅ all passed |
| `LateCheckoutFeeTest` (6) | ✅ all passed, unchanged |
| `StayServiceHousekeepingHookTest` (2) | ✅ all passed, unchanged |
| `PartialCheckoutStayEventTest` (8, new) | ✅ all passed |
| `StayEventFoundationTest` / `StayEventTest` / `StayEventServiceTest` (M1, 12) | ✅ all passed, unchanged |
| `StayServiceExtendTest` / `StayExtendControllerTest` / `StayExtendPermissionSeederTest` (M2, 20) | ✅ all passed, unchanged |

**Total targeted: 61 passed, 0 failed.**

(No Booking-status-aggregation-specific test file exists separately in this codebase — that coverage lives inside `CheckoutIntegrationTest` itself, e.g. `test_reserved_stay_prevents_premature_finalisation`, and is included above.)

---

## 10. Full Regression Results

Run **twice** after the targeted pass:

| Run | Failed | Passed | Assertions |
|-----|--------|--------|-----------|
| 1 | 23 | 804 | 3551 |
| 2 | 23 | 804 | 3551 |

Both runs' failure sets are **byte-identical** (`diff` confirmed) and are exactly the established pre-existing baseline: 11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest` (date-drift, unrelated to this milestone). No new failing test name or class appears anywhere. Passed count rose from Milestone 2's 796 to 804 — exactly the 8 new test methods added this milestone (`CheckoutIntegrationTest`'s 2 extended tests are pre-existing test *methods* with added assertions, not new methods, so they don't add to the count).

**No flaky or date-sensitive failure needed separate identification this round** — both full-suite runs converged to the identical 23-failure set with zero variance, unlike Milestone 1 (which saw one unreproduced flake on its first run).

**Zero new deterministic regressions.**

---

## 11. Architecture Isolation

Verified via `git diff --stat` / `git status --porcelain` scope review:

- ✅ **No new checkout method** — `checkOut()` is the same method, same signature, same call sites; only two `record()` calls were inserted into its existing branch.
- ✅ **No new route** — `routes/web.php` was not touched in this milestone (it was touched in Milestone 2 for `extend`, not again here). Confirmed explicitly by `test_no_new_route_or_permission_introduced_for_partial_checkout`.
- ✅ **No new permission** — no `stay.partial_checkout` or similar permission was added anywhere; `RolePermissionSeeder.php` was not touched in this milestone. Confirmed by the same test.
- ✅ **Booking business logic unchanged** — no `Booking` model or `BookingService` file appears in this milestone's diff; `finaliseBookingCheckout()`/`updateBookingStayStatus()` are called exactly as before, never edited.
- ✅ **Folio business logic unchanged** — no `FolioService`/`Folio`/`FolioEntry` file in the diff; explicitly tested that Folio stays `Open` after a partial checkout and closes exactly as before on final checkout.
- ✅ **Payment unchanged** — no payment-related file in the diff; explicitly tested that no new `BookingPayment` row is created by a partial checkout.
- ✅ **Night Audit unchanged** — no `NightAuditPipeline`/`NightAuditService` file in the diff; this milestone never touches Night Audit's Stay-selection logic.
- ✅ **Housekeeping unchanged** — no `HousekeepingService`/`HousekeepingController`/Housekeeping Vue file in the diff; `StayServiceHousekeepingHookTest` re-run unchanged and passing.
- ✅ **Revenue unchanged** — no `RevenueReportService`/Revenue controller/view file in the diff.
- ✅ **No child booking** — explicitly tested (`test_partial_checkout_creates_no_child_booking`): `Booking::count()` identical before/after.
- ✅ The only schema-adjacent artifact from the whole Phase 4.3A effort remains Milestone 1's `stay_events` table — this milestone adds zero new tables, columns, or migrations.

---

## 12. Known Limitations

- The event's `remaining_active_stays` count is captured at the moment `checkOut()` already computes it (post-DML, within the same transaction) — it reflects the Stay's own status change but not any concurrent, different transaction's Stay changes on the same Booking (no such concurrency scenario is possible here since `Stay` rows for the same Booking are read fresh within the caller's Booking-level lock).
- `StayEventType::Checkout` reuses the same case name as the *method* `checkOut()` and the HTTP action `checkOut()` — this is intentional (it names the business event, not the code path) but is worth noting for anyone grepping the codebase for "checkout" expecting only method/route matches.
- As with Milestone 2, this is backend-only — no frontend surfaces the new `PartialCheckout`/`Checkout` distinction yet (the existing Booking detail screen shows aggregate `Booking.status`, not the per-Stay event log).

---

## 13. Ready for ChatGPT Review

Milestone 3 — Partial Checkout is complete: all 20 required test scenarios pass, two consecutive full-suite runs are stable and byte-identical at the pre-existing 23-failure baseline, and every architecture-isolation guarantee (no new method/route/permission, no Booking/Folio/Payment/Night-Audit/Housekeeping/Revenue file touched, no child booking) is confirmed by diff and by dedicated test.

**Stopping here as instructed.** No commit, no push, no tag. Awaiting ChatGPT review before Milestone 4 (Split Stay Verification).
