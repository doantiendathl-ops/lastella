# Phase 4.3A — Backend Milestone 4 — Split Stay Verification — Report

**Date:** 2026-07-12
**Branch:** phase-3
**Type:** Verification only — no production code changed
**Reference:** `docs/implementation-plans/phase-4.3a-stay-foundation-implementation-plan.md` §10
**Status:** COMPLETE — not committed, not pushed. Awaiting ChatGPT review before Milestone 5.

---

## 1. Executive Summary

Milestone 4 writes zero production code. It proves — via one comprehensive integration test plus targeted re-verification of every dependent test suite — that a single Booking already correctly supports three fully independent per-room Stay timelines: one room extended by a night, two rooms checked out on schedule, Night Audit run for both nights, and the extended room's final checkout, all under one Booking, with no cross-Stay interference and no child Booking at any point. Every capability exercised here (Stay Extension, Partial Checkout, Night Audit, Checkout) was built and regression-tested in Milestones 1–3; this milestone is the first place all four are exercised **together, on a 3-room scenario**, which no prior milestone's tests did.

---

## 2. Verification Scope

Per the approved plan and this task's explicit constraints, this milestone verifies — and does not build:

- One Booking → 3 RoomAssignments → 3 Stays, no child Booking, ever.
- Extending one Stay has zero effect on sibling Stays/RoomAssignments.
- Checking one Stay out (partial) has zero effect on siblings still active.
- The Booking aggregate status path (`CheckedIn` → `PartiallyCheckedOut` → `CheckedOut`) using only existing statuses — no new status invented.
- `StayEvent` timelines, in `occurred_at ASC, id ASC` order, are exactly what each Stay's actual history implies.
- Night Audit bills each room correctly for its actual nights, idempotently, with zero double- or under-posting — using the existing pipeline, unmodified.
- Isolation: no duplicate Stay/RoomAssignment/Booking, no incidental Payment row, Folio governed entirely by existing checkout/Night-Audit behavior.

No `SplitStayService`, controller, request, policy, route, permission, migration, or model was created, per explicit instruction.

---

## 3. Scenario Setup

One Booking, 3 TWIN rooms (Room A, Room B, Room C), each with an identical 1-night `RoomAssignment`/`Stay` (`2026-07-12 14:00` → `2026-07-13 12:00`, room price 800,000/night):

1. All 3 rooms checked in at `2026-07-12 14:00:00`.
2. Full deposit added (3,200,000 — the eventual grand total for A: 1 night + B: 1 night + C: 2 nights).
3. Room C extended to `2026-07-14 12:00:00` via `StayService::extendStay()` (Milestone 2).
4. Night Audit run for `2026-07-12` (the check-in night) — idempotency check: already posted directly by `checkIn()`, so this run must add nothing.
5. Room A checked out at `2026-07-13 12:00:00` (partial — B and C still active).
6. Room B checked out at `2026-07-13 12:00:00` (partial — C still active).
7. Night Audit run for `2026-07-13` (the extended night) — only Room C is `CheckedIn`, so only Room C is billed.
8. Night Audit for `2026-07-13` re-run a second time — idempotency check: must add nothing.
9. Room C checked out at `2026-07-14 12:00:00`, `confirmed: true` (final — the last active Stay).

All timestamps were chosen to fall inside the existing 30-minute early-check-in/late-checkout grace windows, so no incidental fee complicates the billing math — the scenario isolates exactly the capability under test.

---

## 4. Booking Aggregate Status Verification

Using only existing `BookingStatus` values (no new status introduced):

| Point in scenario | Asserted status |
|---|---|
| After all 3 rooms checked in | `CheckedIn` |
| After Room A checks out (partial) | `PartiallyCheckedOut` (not `CheckedOut`) |
| After Room B checks out (partial, C still active) | `PartiallyCheckedOut` (not `CheckedOut`) |
| After Room C's final checkout | `CheckedOut` |

The test explicitly asserts `assertNotSame(BookingStatus::CheckedOut, ...)` at both intermediate partial-checkout points, directly proving the Booking is never prematurely finalized while Room C remains active — and finalizes only once, on Room C's checkout.

---

## 5. Stay Independence Verification

- **Exactly 3 Bookings? No — exactly 1.** `Booking::count() === 1` asserted at both the start and the end of the scenario.
- **Exactly 3 independent `RoomAssignment`/`Stay` rows**, asserted by count, scoped to the one Booking.
- **Extending Room C changes only Room C:** `Stay.planned_checkout_at` and `RoomAssignment.end_at` for Room C move to `2026-07-14 12:00:00`; full-attribute snapshots of Room A's and Room B's `Stay` and `RoomAssignment` rows, taken immediately before the extension, are asserted byte-identical immediately after (`assertSame` on `getAttributes()`).
- **Checking out Room A does not change Room B or Room C:** full-attribute snapshots of both siblings, taken immediately before Room A's checkout, are asserted byte-identical immediately after.
- **Checking out Room B does not change Room C:** same technique, immediately before/after Room B's checkout.
- **Room C remains `CheckedIn`** after both A and B have checked out — asserted directly.

No assertion in this milestone merely checks "the capability works in isolation" — every interference check snapshots the *other* Stays' full attribute sets and asserts exact equality, which is the review criterion this milestone's plan called out explicitly (a shallow per-room-only test would not satisfy it).

---

## 6. StayEvent Timeline Verification

Queried with the established ordering convention `occurred_at ASC, id ASC` (no new database column added for ordering):

| Stay | Asserted event sequence |
|------|--------------------------|
| Room A | `[CheckIn, PartialCheckout]` |
| Room B | `[CheckIn, PartialCheckout]` |
| Room C | `[CheckIn, ExtendStay, Checkout]` |

Each sequence is asserted as an exact ordered array (`assertSame`), not merely "these event types exist somewhere." Metadata cross-checks confirm the event type observed the same decision point `checkOut()` already computes:

- Room A's `PartialCheckout` event: `remaining_active_stays === 2` (B and C still active at that moment).
- Room B's `PartialCheckout` event: `remaining_active_stays === 1` (C still active).
- Room C's `Checkout` event: `remaining_active_stays === 0` (the final checkout).

---

## 7. Night Audit and Billing Verification

Using the existing, unmodified `NightAuditService`/`NightAuditPipeline`/`RoomChargePostingJob`:

- **Idempotency at the check-in night:** re-running `NightAuditService::runForDate('2026-07-12')` after all 3 rooms are already billed for that night (via `checkIn()`'s own first-night post) creates **zero** new `FolioEntry` rows — `FolioEntry` count before and after is identical (3).
- **Correct billing for the extended night:** running `NightAuditService::runForDate('2026-07-13')` after A and B have checked out (and are therefore no longer `CheckedIn`) posts exactly **one** new entry, for Room C only — `FolioEntry` count goes from 3 to 4.
- **Idempotency at the extended night:** re-running the same date a second time adds **zero** further entries.
- **No double-posting, no under-posting, exact manual-calculation match:**

  | Room | Nights actually stayed | `FolioEntry` rows (charge_type=ROOM) | Total billed |
  |------|------------------------|----------------------------------------|--------------|
  | A | 1 | 1 | 800,000 |
  | B | 1 | 1 | 800,000 |
  | C | 2 (1 original + 1 extended) | 2 | 1,600,000 |

  Grand total billed = 3,200,000, asserted equal to the manual calculation (`ROOM_PRICE + ROOM_PRICE + ROOM_PRICE*2`) and to the exact deposit amount paid up front.
- **Posting-key uniqueness:** every `FolioEntry.posting_key` for this Booking's room charges is asserted distinct (`$postingKeys->count() === $postingKeys->unique()->count()`) — the idempotency guarantee `RoomChargePostingJob` already provides (`ROOM_NIGHT_{stay_id}_{businessDate}`) held throughout a multi-Stay, multi-date, multi-checkout scenario.

No Folio or posting logic was modified to make any of this true — it was already correct; this milestone is the first test to exercise it across 3 rooms with divergent actual checkout dates at once.

---

## 8. No-Child-Booking Verification

`Booking::count()` is asserted `=== 1` at the very start of the scenario (immediately after the single `createBooking()` call) and again at the very end (after Room C's final checkout) — proving no code path anywhere in Stay Extension, Partial Checkout, Night Audit, or final Checkout ever created a second `Booking` row, across the full lifecycle.

---

## 9. Files Added / Modified

| File | Type |
|------|------|
| `tests/Feature/SplitStayVerificationTest.php` | **Added** — the entire deliverable of this milestone (1 test method, 44 assertions) |
| `docs/reports/phase-4.3a-backend-m4-report.md` | **Added** — this report |

**No other file was touched.** Confirmed via `find … -newer docs/reports/phase-4.3a-backend-m3-report.md` across `app/`, `database/migrations/`, `database/seeders/`, and `routes/` — zero matches.

---

## 10. Targeted Test Results

Run before the full suite, covering every dependent area named in the milestone instructions:

| Suite | Tests | Result |
|-------|-------|--------|
| `SplitStayVerificationTest` (new) | 1 (44 assertions) | ✅ passed |
| `StayServiceExtendTest` / `StayExtendControllerTest` / `StayExtendPermissionSeederTest` (M2) | 20 | ✅ all passed |
| `CheckoutIntegrationTest` (13, 2 with M3's added assertions) | 13 | ✅ all passed |
| `PartialCheckoutStayEventTest` (M3) | 8 | ✅ all passed |
| `NightAuditPipelineFeatureTest` | 11 | ✅ all passed |
| `NightAuditOperationsTest` | 27 | ✅ all passed |
| `StayEventFoundationTest` / `StayEventTest` / `StayEventServiceTest` (M1) | 12 | ✅ all passed |
| `StayServiceHousekeepingHookTest` | 2 | ✅ all passed |

**Total targeted: 94 passed, 0 failed** (single combined run; see §11 for full-suite context on the two unrelated pre-existing flakes below).

---

## 11. Full Regression Results

Run **three times** (two were required; a third was run specifically to distinguish genuine regressions from pre-existing time/data-dependent flakes, since runs 1 and 2 disagreed on which extra tests failed):

| Run | Failed | Passed | Total | Notes |
|-----|--------|--------|-------|-------|
| 1 | 24 | 804 | 828 | 23-test baseline + 1 `PerStayAttributionTest` flake (method: `add charge stores stay id when provided`) |
| 2 | 25 | 803 | 828 | 23-test baseline + 1 `PerStayAttributionTest` flake (different method: `http store with valid stay id stores attribution`) + `LateCheckoutFeeTest > check out triggers late checkout fee via stay service` |
| 3 | 25 | 803 | 828 | 23-test baseline + 1 `PerStayAttributionTest` flake (method: `add charge stores stay id when provided`, same as run 1) + `LateCheckoutFeeTest` (same method as run 2) |

**The 23-test baseline (11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`, pre-existing date-drift) is byte-identical across all three runs.** Total test count is stable at 828 in every run — this milestone added exactly 1 new test.

**Both extra failures are pre-existing and root-caused, not new regressions:**

- **`Tests\Feature\PerStayAttributionTest`** (`UniqueConstraintViolationException`, a different method each run): this is the previously-documented intermittent Faker `resources.code` unique-value collision, already observed on multiple different test methods across Phase 4.2/4.3A's history. It is test-infrastructure fragility (a Faker seed collision), unrelated to any file this milestone touched (it touched none).
- **`Tests\Feature\LateCheckoutFeeTest::test_check_out_triggers_late_checkout_fee_via_stay_service`**: inspected directly — the test computes `$plannedCheckout = now()->subHours(2)->setTime(12, 0, 0)`. `Carbon::setTime()` overwrites the hour/minute/second entirely, silently discarding the preceding `subHours(2)`, so `$plannedCheckout` is always "today at 12:00:00" regardless of the real time the suite runs. The current real wall-clock time when runs 2 and 3 executed was ~02:00–02:15 AM — before noon — so "planned checkout" (12:00 noon) was in the *future* relative to the actual checkout the test performs, meaning no late fee should legitimately trigger, which is exactly why the test's own assertion fails. This is a **pre-existing bug in the test itself** (not in `StayService` or any file this milestone touched — this milestone touched zero production code), previously identified during Phase 4.2 Milestone 5 ("only passes after local noon"). It is deterministic on time-of-day, not random, which is why it failed identically in both runs 2 and 3 (both run before noon) and did not fail in run 1 (timing coincidence).

**Zero new deterministic regressions from this milestone.** No new test failure class, name, or root cause appears anywhere in this milestone's diff — because this milestone's diff is one new test file and nothing else.

---

## 12. Production-Code Diff Confirmation

Explicitly confirmed, not merely asserted:

- ✅ **Zero production code changed** — `find app/ database/migrations database/seeders routes/ -newer <M3 report>` returns no matches; the only file newer than the Milestone 3 report is `tests/Feature/SplitStayVerificationTest.php`.
- ✅ **No route added** — `routes/web.php` was not touched in this milestone (last touched in Milestone 2, for `extend`).
- ✅ **No permission added** — `database/seeders/RolePermissionSeeder.php` was not touched in this milestone (last touched in Milestone 2, for `stay.extend`).
- ✅ **No migration added** — `database/migrations/` has no file newer than Milestone 1's `create_stay_events_table.php`.
- ✅ **No model added** — no new file under `app/Models/` in this milestone.
- ✅ **No service added** — no new file under `app/Services/` in this milestone (`StayEventService` and `StayService::extendStay()` already existed from Milestones 1–2).
- ✅ **No controller added** — `app/Http/Controllers/` untouched in this milestone.
- ✅ **No Folio logic changed** — no `FolioService`/`Folio`/`FolioEntry` file touched; confirmed additionally by this milestone's own test, which exercises real Folio behavior (open → stays open through partial checkouts → closes on final checkout) purely by observing existing code.
- ✅ **No Payment logic changed** — no payment-related file touched; the scenario's single deposit row is asserted to remain the only `BookingPayment` row throughout (`assertSame(1, $booking->bookingPayments()->count())` at the very end).
- ✅ **No Night Audit logic changed** — no `NightAuditPipeline`/`NightAuditService`/posting-job file touched; Night Audit was invoked exactly as any other caller would (`NightAuditService::runForDate()`), never modified.
- ✅ **No Housekeeping logic changed** — no `HousekeepingService`/`HousekeepingController`/Housekeeping Vue file touched; the existing `StayServiceHousekeepingHookTest` was re-run unchanged and passes.

---

## 13. Known Limitations

- This milestone deliberately did not exercise a *conflicting* extension or a *failed* Night Audit mid-scenario — those failure paths are already covered by Milestone 2's (`StayServiceExtendTest`) and the existing Night Audit suite's own dedicated tests; re-testing them again inside this integration scenario would have added scenario complexity without new verification value.
- The scenario uses 3 rooms of the same room type (TWIN) at the same price — it does not exercise divergent room types/prices across the 3 rooms. Nothing in Stay Extension, Partial Checkout, or Night Audit is room-type-aware in a way that this would matter (billing is per-`Stay`, sourced from each `Stay`'s own room's `room_type_id` independent of its siblings), so this was judged not to reduce verification value.
- As documented in §11, two pre-existing, already-root-caused test issues remain open in the wider suite (unrelated to this milestone, unrelated to each other, and unrelated to Phase 4.3A's own code): the `LateCheckoutFeeTest` wall-clock bug and the `PerStayAttributionTest` Faker collision. Neither was fixed here, per this milestone's explicit test-only, zero-production-code-change scope — fixing either would itself be a (small) production/test change outside this milestone's authorization.

---

## 14. Ready for ChatGPT Review

Milestone 4 — Split Stay Verification is complete: the required 3-room scenario (extend one room, partial-checkout two, run Night Audit twice including one idempotency re-run, final-checkout the extended room) is proven end-to-end with 44 assertions covering every required check — Booking/Stay/RoomAssignment counts, cross-Stay non-interference via full attribute snapshots, the Booking aggregate status path, ordered `StayEvent` timelines, and Night Audit billing correctness/idempotency. Zero production code was changed, confirmed by file-timestamp comparison against the Milestone 3 report. Three full-suite runs converge to the identical 23-test pre-existing baseline; the two additional flakes observed are pre-existing, root-caused by inspection, and explicitly identified by exact test name as instructed.

**Stopping here as instructed.** No commit, no push, no tag. Awaiting ChatGPT review before Milestone 5 (Integration & Verification).
