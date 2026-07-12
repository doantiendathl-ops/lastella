# Phase 4.3A — Backend Milestone 2 — Stay Extension — Report

**Date:** 2026-07-11
**Branch:** phase-3
**Type:** Implementation (Milestone 2 of 5 — Stay Extension only)
**Reference:** `docs/implementation-plans/phase-4.3a-stay-foundation-implementation-plan.md` §8
**Status:** COMPLETE — not committed, not pushed. Awaiting ChatGPT review before Milestone 3.

---

## 1. Executive Summary

Milestone 2 adds Stay Extension: a `CheckedIn` Stay's planned checkout can move later, gated by a new `stay.extend` permission (ADMIN/MANAGER/RECEPTION), reusing the existing room-conflict logic verbatim, and recorded as an audited `ExtendStay` event through the Milestone-1 `StayEventService`. `Stay.planned_checkout_at` and `RoomAssignment.end_at` are updated in the same transaction and always stay synchronized. No Folio, Payment, Night Audit, Housekeeping, Revenue, or Booking file was touched.

---

## 2. Business Capability Implemented

Reception (or MANAGER/ADMIN) can extend a currently-checked-in guest's stay to a later planned checkout date, directly, with no approval step, no wizard — a single action: `POST /admin/bookings/{booking}/stays/{stay}/extend`. The system:

- Confirms the Stay is `CheckedIn`.
- Confirms the new date is strictly later than the current one.
- Confirms the room has no conflicting booking in the extended range.
- Updates `Stay.planned_checkout_at` and `RoomAssignment.end_at` together.
- Records an `ExtendStay` `StayEvent` with the actor and the old/new dates.
- Does nothing else — no Folio entry, no Booking change, no Night-Audit-visible side effect (Night Audit already bills any `CheckedIn` Stay regardless of `planned_checkout_at`, confirmed unmodified in §13).

---

## 3. Files Added

| File | Purpose |
|------|---------|
| `app/Http/Requests/Booking/ExtendStayRequest.php` | Form Request — validates `new_planned_checkout_at` present/parseable, authorizes via `stay.extend` |
| `tests/Unit/Services/StayServiceExtendTest.php` | Service-level unit tests (8) |
| `tests/Unit/Seeders/StayExtendPermissionSeederTest.php` | Permission-grant + idempotency tests (7) |
| `tests/Feature/StayExtendControllerTest.php` | HTTP-level authorization/validation tests (5) |

**Path deviation, flagged explicitly:** the approved plan named `app/Http/Requests/Stay/ExtendStayRequest.php`. It was placed at `app/Http/Requests/Booking/ExtendStayRequest.php` instead, matching the existing sibling files `CheckInStayRequest`/`CheckOutStayRequest`, which already live in `Requests/Booking/` — the same reasoning already applied (and approved) for the Milestone 4/M5 controller and Vue-page path deviations in Phase 4.2.

---

## 4. Files Modified

| File | Change |
|------|--------|
| `app/Enums/StayEventType.php` | +1 case: `ExtendStay = 'EXTEND_STAY'` + Vietnamese label `'Gia hạn lưu trú'` |
| `app/Services/StayService.php` | +1 constructor dependency (`RoomAvailabilityRuleService $rules`), +2 imports (`Room`, `User`, `Carbon`), +1 new public method `extendStay()` (see §7–§9). No existing method's body changed. |
| `app/Policies/StayPolicy.php` | +1 method: `extend(User $user, Stay $stay): bool` — `$user->can('stay.extend')`, matching the existing `checkIn`/`checkOut` pattern exactly |
| `app/Http/Controllers/Admin/Booking/StayController.php` | +1 thin action: `extend()` — authorize → call `StayService::extendStay()` → redirect. No business logic. |
| `database/seeders/RolePermissionSeeder.php` | +1 permission (`stay.extend`) in `PERMISSIONS` const, MANAGER's list, RECEPTION's list. ADMIN receives it automatically via the existing full-`PERMISSIONS` sync. |
| `routes/web.php` | +1 route: `POST bookings/{booking}/stays/{stay}/extend` → `admin.bookings.stays.extend` |

**Route verb deviation, flagged explicitly:** the approved plan proposed `PATCH .../extend`. It was registered as `POST`, matching the existing sibling routes `.../check-in` and `.../check-out`, which are both `POST` in this codebase — same-controller consistency was prioritized over the plan's literal verb.

Full diff to pre-existing files: **95 lines inserted, 0 removed, across 6 files** (`git diff --stat`).

---

## 5. Business Rules

Enforced inside `StayService::extendStay()`, all re-checked **after** locks are acquired (not just pre-lock):

1. `Stay.status` must be `CheckedIn` → else `ValidationException` ("Chỉ có thể gia hạn lưu trú đang nhận phòng.").
2. New planned checkout must be **strictly later** than the current one → else `ValidationException` ("Ngày trả phòng mới phải sau ngày trả phòng hiện tại.") — this single check rejects both "same date" and "earlier date" inputs.
3. The room must have no conflicting `Assigned`/`CheckedIn` assignment for the extended range, excluding the Stay's own booking → else `ValidationException` naming the conflicting room/booking.
4. Only `Stay.planned_checkout_at` and `RoomAssignment.end_at` are written — both inside the same transaction as the `ExtendStay` event.
5. Nothing else is touched: no Booking row, no Folio entry, no Payment row, no Night Audit file.

---

## 6. Authorization

- New permission: `stay.extend`.
- Granted to: **ADMIN** (via full-set sync), **MANAGER**, **RECEPTION** — identical role set to `stay.checkin`/`stay.checkout`.
- Not granted to: SALES, HOUSEKEEPING, ACCOUNTANT — verified explicitly by test (`StayExtendPermissionSeederTest`).
- Double-gated, matching the existing `checkIn`/`checkOut` pattern exactly: `ExtendStayRequest::authorize()` checks `stay.extend` at the HTTP boundary, and `StayController::extend()` additionally calls `$this->authorize('extend', $stay)` against the new `StayPolicy::extend()` method.
- Seeder idempotency verified: reseeding twice leaves exactly 3 `role_has_permissions` pivot rows for `stay.extend` (ADMIN/MANAGER/RECEPTION), no duplicates.

---

## 7. Locking and Transaction Design

```php
DB::transaction(function () {
    $lockedStay  = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
    $lockedRoom  = Room::whereKey($lockedStay->room_id)->lockForUpdate()->firstOrFail();
    $assignment  = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();
    // ... re-checked business rules, updates, event recording — all inside this one transaction
});
```

**Deliberate deviation from `checkIn()`/`checkOut()`'s Booking-first lock order (ADR-38), flagged explicitly:** those two methods lock `Booking` first because they write Booking-linked aggregate state (`finaliseBookingCheckout()`/`updateBookingStayStatus()`) and post to Folio. `extendStay()` does neither — it never writes to `Booking` and its conflict check is scoped to one `Room` — so `Room` is locked first instead, ahead of `Stay`, ahead of `RoomAssignment`. All business preconditions (status, date ordering, conflict) are checked strictly **after** all three locks are held, so no time-of-check/time-of-use gap exists. The Stay update, RoomAssignment update, and `StayEvent` creation all happen inside this one transaction and commit or roll back together — verified by the fact that every rejection path throws before any write occurs.

---

## 8. Conflict Checking

No new overlap logic was written. `extendStay()` calls the exact same method `BookingService::validateTimeChange()` already uses:

```php
$this->rules->findConflictForTimeChange(
    $lockedRoom->id,
    $assignment->start_at,       // unchanged — the Stay's actual/planned check-in
    $newPlannedCheckoutAt,       // the proposed new, later checkout
    $lockedStay->booking_id,     // excluded — a Stay's own booking can never conflict with itself
);
```

Because `findConflictForTimeChange()` excludes the Stay's own `booking_id`, extending a room's stay never conflicts with its own existing assignment. It correctly rejects extension when a *different* booking already holds the same room during the extended window — verified by `StayServiceExtendTest::test_rejects_extension_conflicting_with_another_booking`, which creates a second booking on the same room starting exactly at the first booking's *original* (pre-extension) checkout — no conflict at assignment-creation time, but a real conflict once extension is attempted past that point.

---

## 9. Event Metadata

`StayEventType::ExtendStay` recorded via the existing `StayEventService::record()` (no changes to that class):

```php
$this->stayEvents->record($lockedStay, StayEventType::ExtendStay, $actor, [
    'version'                 => 1,
    'old_planned_checkout_at' => $oldPlannedCheckoutAt?->toIso8601String(),
    'new_planned_checkout_at' => $newPlannedCheckoutAt->toIso8601String(),
]);
```

- `actor` is the authenticated user passed in from the controller (`$request->user()`) — never a fake/system actor, per instruction.
- Only the three required metadata fields are populated for this milestone; `source`, `reported_by`, and `note` are supported by `StayEventService`'s freeform `array $metadata` parameter (proven in Milestone 1's tests) but are optional and not populated here, since no operational scenario in this milestone needs them.
- Event ordering convention `occurred_at ASC, id ASC` is used wherever a Stay's event timeline is queried in tests (see `StayServiceExtendTest::test_extension_creates_an_extend_stay_event_with_correct_metadata_and_actor`) — no new database column was added for ordering, per instruction.

---

## 10. Tests Added

| File | Count | Covers |
|------|-------|--------|
| `tests/Unit/Services/StayServiceExtendTest.php` | 8 | Happy path (`planned_checkout_at` + `RoomAssignment.end_at` updated to the same value); event created with correct metadata/actor; rejects non-`CheckedIn`; rejects same date; rejects earlier date; rejects room conflict; no child Booking created; Folio/Payment untouched |
| `tests/Unit/Seeders/StayExtendPermissionSeederTest.php` | 7 | ADMIN/MANAGER/RECEPTION granted; SALES/HOUSEKEEPING/ACCOUNTANT not granted; seeder idempotent (exactly 3 pivot rows after double-reseed) |
| `tests/Feature/StayExtendControllerTest.php` | 5 | ADMIN/MANAGER/RECEPTION succeed via real HTTP request; unauthorized role (ACCOUNTANT) gets 403; same-or-earlier date returns a validation error |

**Total new: 20 tests** — matching every one of the 20 scenarios listed in the milestone instructions.

---

## 11. Targeted Test Results

| Suite | Result |
|-------|--------|
| `StayServiceExtendTest` (8) | ✅ all passed |
| `StayExtendPermissionSeederTest` (7) | ✅ all passed |
| `StayExtendControllerTest` (5) | ✅ all passed |
| Pre-existing `StayServiceHousekeepingHookTest` (2) | ✅ unchanged, passed |
| Pre-existing `CheckoutIntegrationTest` (13) | ✅ unchanged, passed |
| Milestone 1's `StayEventFoundationTest` (2), `StayEventTest` (6), `StayEventServiceTest` (4) | ✅ unchanged, all passed |

**Total targeted: 47 passed, 0 failed.**

(One test-expectation bug was found and fixed during this milestone — not an implementation bug: `StayServiceExtendTest`'s metadata assertion initially expected UTC-offset ISO-8601 strings; the app runs in `+07:00`, so the assertion was corrected to match. `StayService::extendStay()` itself was never changed for this.)

---

## 12. Full Regression Results

Run **twice** after the targeted pass, per project discipline:

| Run | Failed | Passed | Assertions |
|-----|--------|--------|-----------|
| 1 | 23 | 796 | 3526 |
| 2 | 23 | 796 | 3526 |

Both runs' failure sets are **byte-identical** (`diff` confirmed) and are exactly the established pre-existing baseline: 11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`, the same date-drift category documented at Phase 4.2 closure and re-confirmed fresh at Milestone 1. No new failing test name or class appears anywhere. Passed count rose from Milestone 1's 776 to 796 — exactly the 20 new tests added this milestone, with zero unrelated change.

**No flaky/date-sensitive failure needed separate identification this run** — unlike Milestone 1 (where one unreproduced flake appeared once), both Milestone 2 full-suite runs converged to the identical 23-failure set with no variance.

**Zero new deterministic regressions.**

---

## 13. Architecture Isolation

Verified via `git diff --stat` / `git status --porcelain` scope review of every file touched in this milestone:

- ✅ **Booking business logic unchanged** — no `Booking` model or `BookingService` file appears in the diff. `extendStay()` reads `$lockedStay->booking_id` only to pass to the existing conflict-check call; it never loads, locks, or writes a `Booking` row.
- ✅ **No child booking** — explicitly tested (`test_extension_creates_no_child_booking`): `Booking::count()` is identical before and after extension.
- ✅ **Folio unchanged** — no `FolioService`/`Folio`/`FolioEntry` file in the diff; explicitly tested (`test_extension_does_not_touch_folio_or_payments`) that `Folio`'s attributes and every `FolioEntry` row are byte-identical before/after.
- ✅ **Payment unchanged** — no payment-related file in the diff; the same test asserts every `BookingPayment` row is unchanged.
- ✅ **Night Audit unchanged** — no `NightAuditPipeline`/`NightAuditService` file in the diff. `extendStay()` requires no Night Audit awareness: Night Audit already selects every `Stay` with `status = CheckedIn` regardless of `planned_checkout_at` (confirmed at Phase 4.3 planning time and unmodified here).
- ✅ **Housekeeping unchanged** — no `HousekeepingService`/`HousekeepingController`/Housekeeping Vue file in the diff.
- ✅ **Revenue unchanged** — no `RevenueReportService`/Revenue controller/view file in the diff.
- ✅ The only new database object in this milestone is 0 (Milestone 2 adds no migration — `stay.extend` is a seeder-driven permission row, not a schema change). No `stays`/`room_assignments`/`bookings`/`folio_entries` column was added or altered.
- ✅ `StayService.php`'s diff is additive only: 1 new constructor dependency, a few new imports, and one new public method (`extendStay()`) — no existing method's body was touched.

---

## 14. Known Limitations

- `extendStay()` only ever moves `planned_checkout_at` **later** — attempting to shorten a stay is rejected by design (§4's date-ordering rule doubles as this guard). Shortening remains out of scope until the Adjustment Engine (Phase 4.3C/4.4) can correct any already-posted overcharge, per the approved plan.
- The conflict-check call passes the *entire* range from the assignment's original `start_at` to the new checkout, not just the newly-added tail — this mirrors `BookingService::validateTimeChange()`'s exact pattern and is safe only because `findConflictForTimeChange()` excludes the Stay's own booking; it was not reimplemented differently for a narrower range, consistent with the "reuse, don't reinvent" instruction.
- `ExtendStayRequest`'s validation is intentionally shallow (`required`, `date`) — the *business* rules (status/ordering/conflict) live entirely in `StayService::extendStay()`, per the instruction that the Form Request only validates shape and the Service remains the sole source of business rules.
- No UI change was made in this milestone (backend only, per the "IMPLEMENTATION ONLY" / backend-milestone framing of this task) — Reception cannot yet trigger this from the existing Booking detail screen; that is expected to be a thin frontend follow-up, not part of this milestone's scope.

---

## 15. Ready for ChatGPT Review

Milestone 2 — Stay Extension is complete: all 20 required test scenarios pass, two consecutive full-suite runs are stable and byte-identical at the pre-existing 23-failure baseline, and every architecture-isolation guarantee (no Booking/Folio/Payment/Night-Audit/Housekeeping/Revenue file touched, no child booking) is confirmed by diff and by dedicated test — not merely asserted.

**Stopping here as instructed.** No commit, no push, no tag. Awaiting ChatGPT review before Milestone 3 (Partial Checkout) begins.
