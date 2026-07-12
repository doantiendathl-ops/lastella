# Phase 4.3A — Stay Foundation — Implementation Plan

**Date:** 2026-07-07
**Branch:** phase-3
**Architecture Reference:** `docs/architecture/phase-4.3-core-architecture.md` (approved, frozen for this phase)
**Sequencing Reference:** `docs/implementation-plans/phase-4.3-restructured-master-plan.md` (approved)
**Baseline Tag:** phase-4.2
**Status:** READY FOR CHATGPT REVIEW — implementation not yet authorized

---

## 1. Executive Summary

Phase 4.3A implements the minimum Stay-lifecycle capability required before any operational pilot: an audited Stay Event log, Stay Extension, formalized Partial Checkout, and verification that one Booking already correctly supports multiple independent per-room Stay timelines with no child booking. All five milestones are additive to the existing schema and code — no existing method's business logic changes except two small, explicit hook points inside `StayService::checkOut()`. Night Audit, Folio, and Housekeeping are untouched in every milestone; this phase's entire financial risk surface is "does anything here accidentally post or alter a Folio entry," and the answer designed into every milestone is no.

---

## 2. Objectives

By the end of Phase 4.3A, the system must support:

- ✅ Stay Extension — a `CheckedIn` Stay's planned checkout date can move later, recorded as an audited event, with zero Folio interaction.
- ✅ Partial Checkout — a room in a multi-room booking can check out while others remain active, formalized as a distinct, audited event from a final Checkout.
- ✅ One Booking with multiple independent Stay timelines — already true today at the ground-truth level; this phase adds verification, not new mechanism.
- ✅ One room extending while others check out normally — no interference between independent Stays under the same Booking.
- ✅ No child bookings — under any circumstance in this phase's scope.
- ✅ Existing Night Audit continues working correctly — unmodified, and proven unmodified by test.
- ✅ Existing Folio logic remains unchanged — unmodified, and proven unmodified by test.

---

## 3. Scope

Exactly five milestones, matching `phase-4.3-restructured-master-plan.md` §5:

1. Stay Event Foundation
2. Stay Extension
3. Partial Checkout
4. Split Stay Verification
5. Integration & Verification

---

## 4. Out of Scope

Explicitly deferred to later phases — no file touched in this plan may implement any of the following:

| Item | Deferred to |
|------|-------------|
| Room Move / `TransferRoom` | Phase 4.3B |
| Upgrade / Downgrade | Phase 4.3B |
| Expected Total Projection | Phase 4.3C |
| Deposit Recalculation | Phase 4.3C |
| Booking Amendment Flow (post-arrival routing rule) | Phase 4.3C |
| Adjustment Engine / Correction Entries | Phase 4.3C |
| Revenue Projection / Reconciliation | Phase 4.3C |
| Dashboards, Reports, Automation | Phase 5.x |

**Stay shortening is also explicitly out of scope for this phase** (not listed above because it was never in scope to begin with): `extendStay()` as designed in Milestone 2 only accepts a *later* planned checkout date. Shortening a stay after nights are already posted requires the Adjustment Engine (Phase 4.3C) to correct any resulting overcharge, and is therefore intentionally not enabled here.

---

## 5. Architecture References

| Document | Role |
|----------|------|
| `docs/architecture/phase-4.3-core-architecture.md` | Canonical layer model, Stay Event vocabulary (§4), Operational Flow (§5) — authoritative for this plan |
| `docs/implementation-plans/phase-4.3-restructured-master-plan.md` | Phase sequencing, dependencies, exit criteria this plan implements against |
| `docs/reports/revenue-folio-gap-analysis.md` | Original evidence that Night Audit already bills correctly independent of planned dates — the fact this phase's low Folio-risk design relies on |
| `docs/architecture/phase-4.2-architecture-baseline.md` | Precedent for the audited-log pattern (`NightAuditBookingLog`) this phase's Stay Event log follows |

No new ADR is created in this phase — every design decision below is a direct application of the already-approved architecture, not a new architectural choice.

---

## 6. Milestone Overview

| # | Milestone | Primary risk | New table? |
|---|-----------|---------------|------------|
| 1 | Stay Event Foundation | Getting the audit-log shape right before anything is built on it | Yes — `stay_events` |
| 2 | Stay Extension | Lock-ordering / conflict-checking correctness | No |
| 3 | Partial Checkout | Accidentally changing existing checkout behavior | No |
| 4 | Split Stay Verification | False confidence (asserting a capability without truly exercising cross-Stay interference) | No |
| 5 | Integration & Verification | Full-suite regression across everything above | No |

---

## 7. Milestone 1 — Stay Event Foundation

### Purpose

Establish the single audited event log every subsequent Stay Event (in this phase and all future phases) writes to — mirroring the proven `NightAuditBookingLog` pattern already in production use.

### Scope

**Event model.** A new `StayEventType` PHP backed enum with exactly four cases for this phase's scope:

```php
enum StayEventType: string
{
    case CheckIn         = 'CHECK_IN';
    case ExtendStay      = 'EXTEND_STAY';
    case PartialCheckout = 'PARTIAL_CHECKOUT';
    case Checkout        = 'CHECKOUT';

    public function label(): string { /* Vietnamese labels, matching every other enum in this codebase */ }
}
```

`TransferRoom`, `UpgradeRoom`, `DowngradeRoom` (Phase 4.3B) and `Adjustment` (Phase 4.3C, Folio-layer not Stay-layer) are **not** added now — the underlying column is a plain `VARCHAR`, so adding enum cases later requires no migration, only a new PHP case. Scoping the enum to exactly what this phase uses keeps the diff honest about what Phase 4.3A actually does.

**Event storage.** New migration, new model:

```sql
CREATE TABLE stay_events (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stay_id       BIGINT UNSIGNED NOT NULL,
  event_type    VARCHAR(30) NOT NULL,
  actor_id      BIGINT UNSIGNED NULL,          -- null for system-triggered events (none in this phase, but the column must allow it since CheckIn already has some non-user-actor code paths in tests)
  occurred_at   TIMESTAMP NOT NULL,
  metadata      JSON NULL,                      -- event-specific context, e.g. {"old_planned_checkout_at": "...", "new_planned_checkout_at": "..."}
  created_at    TIMESTAMP NULL,
  updated_at    TIMESTAMP NULL,

  CONSTRAINT fk_se_stay  FOREIGN KEY (stay_id)  REFERENCES stays(id)  ON DELETE RESTRICT,
  CONSTRAINT fk_se_actor FOREIGN KEY (actor_id) REFERENCES users(id)  ON DELETE SET NULL,

  INDEX idx_se_stay        (stay_id),
  INDEX idx_se_event_type  (event_type),
  INDEX idx_se_stay_type   (stay_id, event_type)
);
```

New `StayEvent` Eloquent model: `fillable = [stay_id, event_type, actor_id, occurred_at, metadata]`; casts `event_type => StayEventType::class`, `occurred_at => datetime`, `metadata => array`; `belongsTo(Stay::class)`, `belongsTo(User::class, 'actor_id')`.

**Audit.** `AuditObserver::class` is **not** attached to `StayEvent` — `StayEvent` rows are themselves an audit trail (append-only, never updated), so a generic CRUD-diff observer on top of it would be redundant. This mirrors `NightAuditBookingLog`, which also has no `AuditObserver` registration.

**Service responsibilities.** A private helper method added to `StayService` (not a new service class — Stay Events are tightly coupled to the state transitions `StayService` already owns; a new class is not warranted for a single recording call, consistent with this codebase's YAGNI convention):

```php
private function recordStayEvent(
    Stay $stay,
    StayEventType $type,
    ?User $actor,
    array $metadata = [],
): void {
    StayEvent::create([
        'stay_id'     => $stay->id,
        'event_type'  => $type,
        'actor_id'    => $actor?->id,
        'occurred_at' => now(),
        'metadata'    => $metadata,
    ]);
}
```

Called from inside the same `DB::transaction()` each triggering method already opens (`checkIn()`, `checkOut()`, and the new `extendStay()`) — never in its own transaction, so a failure to log never partially commits a state change without its audit record, and vice versa.

**Authorization.** None new — `StayEvent` rows are written internally by `StayService`, never directly by a controller. No new permission, no new policy.

**Retrofit note:** this milestone also adds a `recordStayEvent(..., StayEventType::CheckIn, ...)` call inside the **existing** `StayService::checkIn()` method — a one-line addition, no behavior change — so the foundation is proven against a real, already-tested event before Milestone 2 builds a brand new one on top of it.

### Files Expected to Change

| File | Change |
|------|--------|
| `database/migrations/2026_07_07_000000_create_stay_events_table.php` | New migration |
| `app/Enums/StayEventType.php` | New enum |
| `app/Models/StayEvent.php` | New model |
| `database/factories/StayEventFactory.php` | New factory |
| `app/Services/StayService.php` | Add `recordStayEvent()` private helper; add one call inside existing `checkIn()` |

### Business Rules

- `StayEvent` rows are append-only — no update endpoint, no controller route, ever (matches `FolioEntry`'s immutability precedent, applied here for the same reason: an audit trail must not be editable).
- `occurred_at` is always `now()` at the moment of the triggering transaction, never client-supplied.
- `metadata` is a plain associative array, cast to JSON — no schema enforced beyond "valid JSON," since each event type's shape differs and this is a log, not a queried business table.

### Dependencies

None — this is the first milestone of Phase 4.3A and the first of the whole of Phase 4.3.

### Regression Risks

- **LOW.** The only existing-code touch is one additive line inside `checkIn()`. No existing behavior changes; no existing test's assertions should need updating.

### Testing Strategy

- **Unit:** `StayEventTest` (model) — factory produces valid rows; casts work; `belongsTo` relationships resolve.
- **Feature:** none required standalone (no new HTTP surface) — covered by the check-in retrofit test below.
- **Integration:** extend the existing check-in feature test coverage with one assertion: after `StayService::checkIn()`, a `StayEvent` row exists with `event_type = CheckIn` and the correct `stay_id`.
- **Regression:** full suite must show zero new failures — this milestone changes no existing behavior, only adds.

### Deliverables

- `docs/reports/phase-4.3a-backend-m1-report.md`

### Stop Conditions

Do not proceed to Milestone 2 until: migration runs/rolls back/re-runs cleanly; `StayEvent` unit tests pass; the check-in retrofit assertion passes; full suite shows zero new regressions.

### Review Criteria

- `stay_events` table matches the DDL above exactly (or a reviewed, reported deviation).
- No `AuditObserver` attached to `StayEvent`.
- `recordStayEvent()` is called inside the same transaction as its triggering state change, never outside it.

---

## 8. Milestone 2 — Stay Extension

### Purpose

Let a `CheckedIn` Stay's planned checkout move later, as an audited event, with zero Folio interaction — the first genuinely new capability in Phase 4.3A.

### Scope

**Business rules.**
- Only a `Stay` with `status = CheckedIn` can be extended (a `Reserved` or `CheckedOut` Stay has no "current occupancy" to extend).
- The new planned checkout must be **strictly later** than the current `planned_checkout_at` — this method never shortens a stay (see §4).
- The room must remain conflict-free for the extended range — reuse the exact conflict-check pattern already established in `BookingService::validateTimeChange()` (`RoomAvailabilityRuleService::findConflictForTimeChange()`), so a room already promised to another booking starting before the new, later checkout is correctly rejected.

**Validation.** New `ValidationException` cases, matching the Vietnamese-message convention used throughout `StayService`/`BookingService`:
- Stay not `CheckedIn` → "Chỉ có thể gia hạn lưu trú đang nhận phòng."
- New date not later than current → "Ngày trả phòng mới phải sau ngày trả phòng hiện tại."
- Room conflict in the extended range → the same structured-conflict message pattern `BookingService::validateTimeChange()` already uses.

**Authorization.** A new granular permission, `stay.extend`, added to `RolePermissionSeeder::PERMISSIONS` and granted to ADMIN (automatically, via the existing `syncPermissions(self::PERMISSIONS)` call), MANAGER, and RECEPTION — the same three roles that already hold `stay.checkin`/`stay.checkout`. This is a data change via the existing seeder mechanism (`Permission::findOrCreate`), not a schema migration.

**Stay update.** `Stay.planned_checkout_at` → new date. `RoomAssignment.end_at` → same new date (RoomAssignment tracks the current plan, per its "Planning + Physical Allocation" role).

**Event creation.** `recordStayEvent($stay, StayEventType::ExtendStay, $actor, ['old_planned_checkout_at' => ..., 'new_planned_checkout_at' => ...])`.

**Night Audit interaction.** **None required.** `NightAuditPipeline::run()` already selects every `Stay` with `status = CheckedIn` regardless of `planned_checkout_at` — confirmed by direct code inspection in the Gap Analysis. This milestone changes no Night Audit file.

**Lock pattern.** Matches the canonical order already established in `BookingService::validateTimeChange()` and `StayService::checkIn()`/`checkOut()`: `Room` → `Stay` → `RoomAssignment`, each via `lockForUpdate()` inside one `DB::transaction()`.

**Proposed method signature** (`StayService`):

```php
public function extendStay(Stay $stay, CarbonInterface|string $newPlannedCheckoutAt, User $actor): Stay
```

### Files Expected to Change

| File | Change |
|------|--------|
| `app/Services/StayService.php` | New `extendStay()` public method |
| `database/seeders/RolePermissionSeeder.php` | Add `stay.extend` permission; grant to ADMIN/MANAGER/RECEPTION |
| `app/Http/Controllers/Admin/Booking/StayController.php` | New `extend()` action (thin — delegates to `StayService::extendStay()`) |
| `app/Http/Requests/Stay/ExtendStayRequest.php` | New Form Request: validates `new_planned_checkout_at` is present and parseable; authorization via `stay.extend` |
| `routes/web.php` | One new route: `PATCH bookings/{booking}/stays/{stay}/extend` |

### Business Rules

Summarized above — the two hard invariants are **"only forward, never backward"** and **"never touches Folio."**

### Dependencies

Requires Milestone 1's `recordStayEvent()` helper and `StayEventType::ExtendStay`.

### Regression Risks

- **LOW–MEDIUM.** The conflict-check reuse (`findConflictForTimeChange`) is already proven by `BookingService`'s existing tests; the risk is confined to correctly wiring the same call for a single-`Stay` context rather than a whole-`Booking` context.
- No existing method's behavior changes — `extendStay()` is entirely new.

### Testing Strategy

- **Unit (`StayServiceTest` or new `StayServiceExtendTest`):** happy path extends correctly; throws on non-`CheckedIn` Stay; throws on a same-or-earlier date; throws on a room conflict in the extended range; `RoomAssignment.end_at` updates in lockstep; `StayEvent` row created with correct metadata.
- **Feature (`StayExtendControllerTest`):** 403 for a role without `stay.extend`; 422 for a same-or-earlier date; 200 + correct response shape for a valid extension.
- **Integration:** full chain — Booking → Assignment → CheckIn → ExtendStay → (manually trigger) Night Audit → Checkout, asserting the correct number of nights bill, with zero Folio-file changes in the diff.
- **Regression:** full suite twice, zero new deterministic failures.
- **Manual:** Reception extends a real stay in a browser; confirm the new planned checkout date displays correctly; confirm Night Audit still posts correctly that night.

### Deliverables

`docs/reports/phase-4.3a-backend-m2-report.md`

### Stop Conditions

Do not proceed to Milestone 3 until all Milestone 2 tests pass, the permission seeder change is verified idempotent (re-run twice, same result), and the full suite shows zero new regressions.

### Review Criteria

- `extendStay()` never shortens a stay (test explicitly asserts this is rejected, not just "not tested").
- No `FolioService` or `Posting\*Job` file appears in this milestone's diff.
- Lock order matches the established `Room → Stay → RoomAssignment` pattern exactly.

---

## 9. Milestone 3 — Partial Checkout

### Purpose

Make the already-correct per-Stay checkout behavior explicit and auditable, distinguishing "one room of several checked out" from "the whole booking's last stay checked out," without changing any existing checkout logic.

### Scope

**Business workflow.** No new method. Inside the **existing** `StayService::checkOut()`, at the exact point where `$remainingActive` is already computed (the count of other `Reserved`/`CheckedIn` stays on the booking), add one branch:

```php
if ($remainingActive === 0) {
    $this->bookings->finaliseBookingCheckout($lockedBooking);
    $this->recordStayEvent($lockedStay, StayEventType::Checkout, $actingUser);
} else {
    $this->bookings->updateBookingStayStatus($lockedBooking);
    $this->recordStayEvent($lockedStay, StayEventType::PartialCheckout, $actingUser);
}
```

**Booking aggregation.** Unchanged — `BookingService::updateBookingStayStatus()` already correctly computes `PartiallyCheckedOut` when some but not all stays have checked out. This milestone does not touch that method.

**Stay status.** Unchanged — a partially-checked-out Stay and a finally-checked-out Stay both simply become `StayStatus::CheckedOut`. **The distinction lives entirely in the new `StayEvent` log, not in `Stay.status`** — this is an important, deliberate design point: `PartialCheckout` vs `Checkout` is about the *booking's* aggregate completeness at that moment, not a new state for the *Stay* itself.

**Validation.** None new — the existing preconditions in `checkOut()` (assignment status, actual check-in already happened, not already checked out) are unchanged and already correct.

**Event creation.** As shown above — the only production-code change in this milestone.

### Files Expected to Change

| File | Change |
|------|--------|
| `app/Services/StayService.php` | Two-line addition inside the existing `checkOut()` method (the `if`/`else` branch already exists; only the `recordStayEvent()` calls are new) |

### Business Rules

- The event type (`PartialCheckout` vs `Checkout`) is determined by the **same** `$remainingActive` computation `checkOut()` already uses to decide between `finaliseBookingCheckout()` and `updateBookingStayStatus()` — no new business rule, no new query, purely observing an existing decision point.

### Dependencies

Requires Milestone 1's `recordStayEvent()` helper. Independent of Milestone 2 (both build on M1, neither depends on the other).

### Regression Risks

- **LOW.** This is the smallest production-code change in the entire phase — two lines inside a method whose surrounding logic is completely unchanged and already extensively tested (`CheckoutIntegrationTest`, `LateCheckoutFeeTest`, and others).

### Testing Strategy

- **Unit/Feature:** extend existing `CheckoutIntegrationTest` assertions — after a partial checkout (other stays remain active), assert a `StayEvent` row with `event_type = PartialCheckout` exists; after the final checkout, assert `event_type = Checkout`.
- **Integration:** the 3-room scenario already partially covered by Milestone 4 doubles as this milestone's integration proof.
- **Regression:** full suite twice — this is the milestone most likely to reveal an unintended behavior change if one exists, precisely because it touches a method as central and well-tested as `checkOut()`; any new failure here must be treated as a real regression, not dismissed.
- **Manual:** Reception checks out one room of a three-room booking; confirm the other two remain unaffected and the booking shows partially-checked-out.

### Deliverables

`docs/reports/phase-4.3a-backend-m3-report.md`

### Stop Conditions

Do not proceed to Milestone 4 until the full existing checkout test suite (`CheckoutIntegrationTest` and all related files) passes unchanged in every pre-existing assertion, plus the new `StayEvent` assertions pass.

### Review Criteria

- The diff to `StayService::checkOut()` is exactly the two `recordStayEvent()` calls — no other line in the method changes.
- No existing `CheckoutIntegrationTest`/`LateCheckoutFeeTest` assertion needed modification (if one did, that is a signal something unintended changed).

---

## 10. Milestone 4 — Split Stay Verification

### Purpose

Prove — not build — that one Booking already correctly supports multiple independent per-room Stay timelines, with one room extending while others check out on schedule, and with no child booking ever created.

### Scope

**This milestone writes zero production code.** It is a dedicated verification test suite exercising Milestones 1–3's capability across multiple rooms at once, since every test in Milestones 2 and 3 exercises a single-`Stay` scenario in isolation.

**Verification approach:**

1. Create one `Booking` with 3 room requirements/assignments (matching the existing `HousekeepingWorkflowIntegrationTest`/`CheckoutIntegrationTest` multi-room setup convention already used in this codebase).
2. Check in all three rooms.
3. Extend Room C's stay via `extendStay()`.
4. Check out Room A and Room B on their original schedule (`PartialCheckout` events expected).
5. Assert throughout: `Booking::count()` never exceeds 1 (no child booking at any step); Room A/B's `StayEvent` logs show `PartialCheckout`; Room C's log shows `ExtendStay` with no interference from A/B's events; Room C's `planned_checkout_at` is independently later than A/B's, and A/B's dates are completely unaffected by C's extension.
6. Finally check out Room C — assert its event is `Checkout` (the last one), and the Booking finalizes correctly.
7. Run Night Audit across the whole scenario — assert Room C accrues exactly the correct number of extra nights, and A/B accrue exactly their original, shorter count.

### Files Expected to Change

| File | Change |
|------|--------|
| `tests/Feature/SplitStayVerificationTest.php` | New file — the entire deliverable of this milestone |

### Business Rules

None new — this milestone verifies existing rules from Milestones 1–3 hold correctly in combination.

### Dependencies

Requires Milestones 1, 2, and 3 complete.

### Regression Risks

**NONE** — test-only milestone, no production code touched.

### Testing Strategy

- **Integration only**, as described above — this milestone *is* the integration test for the "Split Stay" capability named in the phase's business objectives.
- **Manual:** a human walks through the identical 3-room scenario in a browser as part of this milestone's sign-off, not deferred entirely to Milestone 5's Operational Review — since this is explicitly the capability the business objectives call out by name ("one room extending while other rooms checkout normally").

### Deliverables

`docs/reports/phase-4.3a-backend-m4-report.md`

### Stop Conditions

Do not proceed to Milestone 5 until every assertion in `SplitStayVerificationTest` passes and the manual 3-room walkthrough is confirmed by a human.

### Review Criteria

- The test suite actually exercises **interference** (asserting room A/B/C's data remains independent), not just that each capability works in isolation for a single room — a shallow test that never checks for cross-Stay leakage would not satisfy this milestone's purpose.

---

## 11. Milestone 5 — Integration & Verification

### Purpose

Close out Phase 4.3A: full regression, full end-to-end integration across every event introduced, and explicit verification that the phase's architectural promises (no Folio interaction, no Housekeeping interaction, no Booking-schema change) held throughout.

### Scope

- **Regression:** full `php artisan test` suite, run at least twice, compared against the pre-Phase-4.3A baseline (re-verified fresh, not assumed from the last Phase 4.2 measurement, since real dates have advanced).
- **Integration:** re-run `SplitStayVerificationTest`, the Milestone 2/3 test suites, and the full existing `StayService`/`CheckoutIntegrationTest`/Housekeeping suites together in one pass, confirming no interaction effects between this phase's new code and existing Phase 3/4.2 code.
- **Operational verification:** the Operational Review process (§14) is performed as part of this milestone's closure, not deferred past it.
- **Architecture verification:** an explicit, reported confirmation (via `git diff` scope review, not just test results) that:
  - No `FolioService`, `Posting\*Job`, or `NightAuditPipeline` file appears anywhere in the Phase 4.3A diff.
  - No `HousekeepingService`, `HousekeepingController`, or Housekeeping Vue file appears in the diff.
  - `Booking`'s schema and `BookingService`'s methods are completely untouched.
  - The only schema change in the entire phase is the new `stay_events` table — `stays`, `room_assignments`, `bookings`, `folio_entries` all remain schema-unchanged.

### Files Expected to Change

None beyond what Milestones 1–4 already produced — this milestone is verification and reporting, not new code.

### Dependencies

Requires Milestones 1–4 complete.

### Regression Risks

The cumulative risk of the whole phase, assessed together for the first time — expected **LOW**, given each milestone's individually-assessed risk was LOW or LOW–MEDIUM and no milestone touched a shared piece of state another milestone also touched.

### Testing Strategy

- **Full regression suite** (twice, per this project's established discipline).
- **Full integration** as described above.
- **Manual:** the complete Reception workflow from §14, run once end-to-end by a human, immediately before the formal Operational Review sign-off.

### Deliverables

- `docs/reports/phase-4.3a-backend-m5-report.md` (the phase's Integration & Verification report)
- `docs/reports/phase-4.3a-operational-review.md`
- `docs/reports/phase-4.3a-user-acceptance-review.md`

### Stop Conditions

Do not declare Phase 4.3A complete until all of §16's Exit Criteria are met.

### Review Criteria

All architecture-verification points above must be explicitly confirmed **in the report**, not merely true in fact — the report itself is the audit artifact ChatGPT reviews to authorize Phase 4.3B.

---

## 12. Regression Strategy

**Current regression baseline:** the previously-documented 23 pre-existing, date-drift-based failures (11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`) from Phase 4.2, **re-verified fresh at the start of Milestone 1** rather than assumed — real dates have advanced since it was last measured, and the exact count/identity may have shifted (though the *category* — hardcoded-date drift, unrelated to this phase — should not have).

**Financial isolation:** Phase 4.3A's design guarantees zero `FolioService`/`Posting\*Job`/`FolioEntry` interaction in every one of its five milestones. This is verified explicitly (not just assumed) at Milestone 5 via `git diff` scope review.

**Housekeeping isolation:** no milestone in this phase touches `Room.status`, `HousekeepingService`, or any Housekeeping file. Extension, Partial Checkout, and the verification milestone all operate purely on `Stay`/`RoomAssignment`/the new `StayEvent` log.

**Booking isolation:** `BookingService` is not modified anywhere in this phase — `updateBookingStayStatus()` and `finaliseBookingCheckout()` are called (as they already are today) but never edited. `Booking`'s schema is untouched.

**Night Audit isolation:** `NightAuditPipeline`/`NightAuditService` are not modified anywhere in this phase — confirmed by design (Extension needs no Night Audit awareness) and re-confirmed by Milestone 5's explicit diff check.

**Expected regression risks:**
- A conflict-check bug in `extendStay()` incorrectly allowing or disallowing an extension — mitigated by reusing the already-proven `findConflictForTimeChange()` logic rather than writing new conflict detection.
- An unintended change to `checkOut()`'s existing behavior while adding the two `recordStayEvent()` calls — mitigated by the explicit review criterion that the diff to that method must be *exactly* those two calls, nothing else.
- The new `stay_events` table's foreign keys interacting badly with existing cascade/restrict rules on `stays`/`users` — mitigated by using `ON DELETE RESTRICT`/`SET NULL` exactly matching the established pattern from `housekeeping_assignments`/`cleaning_records` (Phase 4.2 precedent).

**Mitigation summary:** every milestone reuses an already-proven pattern from this codebase (audited log from `NightAuditBookingLog`; conflict-check from `BookingService`; lock order from `StayService`/`BookingService`) rather than inventing a new one — the single biggest regression-risk reducer available for this phase.

---

## 13. Testing Strategy

Consolidated view across all milestones (per-milestone detail in §7–§11):

| Test type | Coverage |
|-----------|---------|
| **Unit** | `StayEvent` model; `StayService::extendStay()` (happy path + every precondition violation) |
| **Feature** | New `extend` HTTP endpoint (403/422/200); `StayEvent` assertions layered onto existing checkout feature tests |
| **Integration** | `SplitStayVerificationTest` (Milestone 4); full-chain Extend→NightAudit→Checkout; full-chain 3-room Partial Checkout |
| **Regression** | Full suite, twice per milestone, per this project's established discipline |
| **Manual** | Reception extends a stay (M2); Reception performs a 3-room partial-checkout/extension scenario (M4); the complete Reception workflow from §14 (M5, immediately pre-Operational-Review) |

---

## 14. Operational Review

Performed as part of Milestone 5's closure, per `phase-4.3-restructured-master-plan.md` §5.

**Reception workflow tested, live in a browser:**

```
Booking → Assignment → Check-in → Stay Extension → Partial Checkout → Checkout → Night Audit
```

Concretely: create a 2–3 room booking, assign rooms, check in all guests, extend one room's stay, check out the non-extended room(s) (a `PartialCheckout`), manually run Night Audit, then check out the extended room (the final `Checkout`), and confirm the Folio's total matches the actual nights each room was occupied.

**Success criteria:**
- Every step completes with no error state visible to the user.
- The extended room's new planned checkout date is visible and correct on screen immediately after the extension.
- The partially-checked-out booking correctly shows its aggregate status (some rooms done, one still active).
- After Night Audit, the Folio's total exactly matches manual hand-calculation of (nights × rate) per room, including the extended room's extra night(s).
- No child booking appears anywhere in the system at any point.

**Blocking issues (any one of these halts Phase 4.3A sign-off):**
- An extension that silently fails or produces an incorrect planned date.
- A partial checkout that incorrectly finalizes the whole booking, or a final checkout that fails to finalize it.
- Any discrepancy between actual nights stayed and nights billed after Night Audit.
- Any code path that creates a second `Booking` row for any reason.

**Acceptable workarounds:** none for this phase — every item in the tested workflow is core, already-scoped capability with no known limitation requiring a workaround.

---

## 15. User Acceptance Review

Per `phase-4.3-restructured-master-plan.md` §5 — Reception and Manager only; Housekeeping and Accounting are not relevant to this phase's scope.

### Reception

**Acceptance criteria:**
- Can extend a guest's stay in a few clicks, with no need to understand "Stay Events" as a concept — it should feel like "change the checkout date," full stop.
- Can check out one room of a multi-room booking without any confusing prompt implying the whole booking is closing.
- Never encounters a "child booking" or any UI element suggesting a second booking was created.

**Expected workflow:** Booking → Assignment → Check-in → (optional) Extend → Checkout (partial or final), entirely within the existing Booking detail screen, no new screen required for this phase.

**Expected results:** the guest's actual stay length, however it changed, is billed correctly with zero manual recalculation required by Reception.

### Manager

**Acceptance criteria:**
- Can see, from the Booking's existing detail view, that a Stay was extended and by how much, and that a partial checkout occurred, with correct timestamps.
- Can trust that Night Audit's nightly totals are correct for every room independently, including an extended one, without needing to manually verify the math.

**Expected workflow:** review a multi-room booking's history after a mix of extensions and partial checkouts, confirming the audit trail (from the new `StayEvent` log, surfaced however the Booking detail view already displays historical information) is legible and complete.

**Expected results:** full confidence that the Stay Event log is a trustworthy, complete record of what happened to every room in the booking — this is the foundation Manager-level trust in Phase 4.3B/4.3C's more complex events (transfer, upgrade, adjustment) will be built on.

---

## 16. Risks

| Risk | Severity | Mitigation |
|------|----------|-----------|
| `stay_events` schema designed wrong, forcing a breaking change once Phase 4.3B needs `TransferRoom` metadata | MEDIUM | `metadata` is unstructured JSON specifically so future event types don't require a schema change — only a new enum case |
| `extendStay()`'s conflict check has a subtle bug not caught by tests | MEDIUM | Reuses `findConflictForTimeChange()` verbatim rather than reimplementing conflict detection |
| The `checkOut()` retrofit (Milestone 3) introduces a regression despite being "just two lines" | LOW–MEDIUM | Explicit review criterion requiring the diff be exactly those two calls; full existing checkout test suite must pass unchanged |
| Split Stay Verification (Milestone 4) is written shallow, missing real cross-Stay interference bugs | MEDIUM | Review criterion explicitly requires interference-testing, not just per-room isolation testing |
| New `stay.extend` permission mis-seeded, blocking Reception in practice | LOW | Same `Permission::findOrCreate`/`syncPermissions` mechanism already proven correct and idempotent across Phase 4.2's 5 new permissions |

---

## 17. Exit Criteria

- [ ] Milestones 1–5 each complete with an approved report.
- [ ] Full regression suite stable across at least two runs, zero new deterministic failures.
- [ ] `git diff` scope review confirms zero Folio/Night-Audit/Housekeeping file touched anywhere in the phase.
- [ ] `SplitStayVerificationTest` passes, including genuine cross-Stay interference assertions.
- [ ] Operational Review (§14) passed with zero blocking issues.
- [ ] User Acceptance Review (§15) signed off by Reception and Manager.
- [ ] → **Pilot Candidate 1** reached, per `phase-4.3-restructured-master-plan.md` §5.

---

## 18. Ready for Backend Implementation

This plan is submitted for ChatGPT Architecture Review. **Backend Milestone 1 (Stay Event Foundation) may begin only after this plan is explicitly approved.** No code, migration, or test in this plan has been written yet — this document is planning only, matching the depth and structure of `docs/implementation-plans/phase-4.2-housekeeping-workflow-implementation-plan.md`.
