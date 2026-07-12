# Phase 4.3A — Backend Milestone 1 — Stay Event Foundation — Report

**Date:** 2026-07-10
**Branch:** phase-3
**Type:** Implementation (Milestone 1 of 5 — Stay Event Foundation only)
**Reference:** `docs/implementation-plans/phase-4.3a-stay-foundation-implementation-plan.md` §7, revised per ChatGPT's Milestone 1 refinements (dedicated `StayEventService`, single-enum-case YAGNI, metadata-never-null, actor-nullable)
**Status:** COMPLETE — not committed, not pushed. Awaiting ChatGPT review before Milestone 2.

---

## 1. Executive Summary

Milestone 1 adds the append-only Stay Event audit log this phase and all later Stay-lifecycle work will write to: a new `stay_events` table, `StayEvent` model, `StayEventType` enum, and a dedicated `StayEventService` responsible only for persisting events. The existing `StayService::checkIn()` was retrofitted with a single call to log a `CheckIn` event, proving the foundation against a real, already-tested event before any new event type is built on top of it. No business logic, Folio, Night Audit, Housekeeping, or Booking code was touched.

---

## 2. Files Changed

### New files

| File | Purpose |
|------|---------|
| `database/migrations/2026_07_10_000000_create_stay_events_table.php` | `stay_events` table |
| `app/Enums/StayEventType.php` | Enum — `CheckIn` only (see §3) |
| `app/Models/StayEvent.php` | Model — fillable, casts, `belongsTo(Stay)`, `belongsTo(User, 'actor_id')`, metadata defaults to `[]` |
| `app/Services/StayEventService.php` | Dedicated persistence-only service (see §4) |
| `database/factories/StayEventFactory.php` | Factory for tests |
| `tests/Unit/Models/StayEventTest.php` | Model unit tests |
| `tests/Unit/Services/StayEventServiceTest.php` | Service unit tests |
| `tests/Feature/StayEventFoundationTest.php` | Feature: check-in retrofit + regression-isolation test |

### Modified files

| File | Change |
|------|--------|
| `app/Models/Stay.php` | +1 relationship: `stayEvents(): HasMany` |
| `app/Services/StayService.php` | +1 constructor dependency (`StayEventService $stayEvents`), +1 import, +1 call: `$this->stayEvents->record($lockedStay, StayEventType::CheckIn, Auth::user());` inside the existing `checkIn()` transaction, immediately before `return $lockedStay->refresh();` |

The full diff to both modified files is 9 inserted lines, 0 removed, across 2 files — verified via `git diff --stat`.

---

## 3. Architecture Decisions

- **Table shape matches the approved plan's DDL** (`stay_id` FK restrict-on-delete, `event_type` varchar(30), `actor_id` FK nullable/null-on-delete, `occurred_at` timestamp, `metadata` json nullable, timestamps), with indexes on `event_type` and `(stay_id, event_type)`. A plain index on `stay_id` alone is not separately declared because MySQL's `constrained()` foreign key already creates one.
- **No `AuditObserver`** is attached to `StayEvent` — it is itself an audit trail (append-only), mirroring the existing `NightAuditBookingLog` precedent.
- **`occurred_at` is always `now()`** at the moment of persistence, never client-supplied — enforced inside `StayEventService::record()`, the only write path.
- **No update endpoint, no controller route** exists for `StayEvent` — rows are written internally only, matching the plan's immutability requirement.

---

## 4. Why the Enum Contains Only One Active Case

Per ChatGPT's revised instruction, `StayEventType` currently has exactly one case: `CheckIn`. `ExtendStay`, `PartialCheckout`, `TransferRoom`, `UpgradeRoom`, `DowngradeRoom` are deliberately **not** pre-created. Reasoning:

- The underlying `event_type` column is a plain `VARCHAR(30)` — adding a future case requires zero migration, only a new PHP enum case, so there is no cost to waiting.
- Milestone 1's stated scope is the CheckIn retrofit only; Checkout logging was explicitly excluded from this milestone's instructions (it belongs to Milestone 3 — Partial Checkout), so `Checkout` was not added either.
- This keeps the diff an honest reflection of what Milestone 1 actually does (YAGNI), and avoids ChatGPT's earlier-flagged risk of "false confidence" from pre-declared-but-unused vocabulary.

---

## 5. StayEventService Design

Per ChatGPT's revised instruction, event recording is **not** placed inside `Stay`, a controller, or `StayService` — it lives in its own dedicated class:

```php
class StayEventService
{
    public function record(Stay $stay, StayEventType $type, ?User $actor, array $metadata = []): StayEvent
    {
        return StayEvent::create([
            'stay_id'     => $stay->id,
            'event_type'  => $type,
            'actor_id'    => $actor?->id,
            'occurred_at' => now(),
            'metadata'    => $metadata,
        ]);
    }
}
```

- **Single responsibility:** persistence only. It contains no validation, no business rule, no side effect beyond the `INSERT` — callers (currently only `StayService::checkIn()`) are responsible for calling it inside their own existing `DB::transaction()`, so a logging failure and a state-change failure always commit or roll back together. `StayEventService` itself opens no transaction.
- **`actor` is nullable** by parameter type (`?User $actor`) and by column (`actor_id` nullable, `nullOnDelete`) — deliberately, so future system-triggered events (Night Audit, cron, queue jobs) can log with no actor, per the approved design.
- **`metadata` defaults to `[]`, never `null`** — the parameter default is `array $metadata = []`, and the `StayEvent` model additionally declares `protected $attributes = ['metadata' => '[]']` so even a `new StayEvent()` constructed outside the service starts from an empty array, never `null`. Both paths were tested explicitly (§7).
- **Audit-direction note (per Operational Design Principles):** `actor` records who touched the system; `metadata` can optionally carry a `reported_by` field for cases where the physical worker differs from the system actor (e.g. Reception marks a room clean because Housekeeping reported it verbally). Milestone 1 does not populate this field for `CheckIn` (no such distinction applies to check-in), but the shape supports it for future event types without any schema change — exercised directly in `StayEventServiceTest::test_record_persists_a_stay_event_with_actor_and_metadata`.

---

## 6. Check-In Retrofit

`StayService::checkIn()`'s existing transaction is unchanged except for one added line, placed after the existing `autoMarkOccupied()` call and before the method's `return`:

```php
$this->stayEvents->record($lockedStay, StayEventType::CheckIn, Auth::user());
```

No existing line in `checkIn()` was modified, reordered, or removed.

---

## 7. Targeted Tests

| Suite | Tests | Result |
|-------|-------|--------|
| `Tests\Unit\Models\StayEventTest` | 6 — factory validity, cast decoding (`event_type`/`occurred_at`/`metadata`), metadata-defaults-to-`[]`-on-a-bare-instance, `belongsTo(Stay)`, `belongsTo(User)`, actor-nullable | ✅ 6 passed |
| `Tests\Unit\Services\StayEventServiceTest` | 4 — persists with actor+metadata, metadata defaults to `[]` when omitted, `null` actor accepted, `occurred_at` set to `now()` | ✅ 4 passed |
| `Tests\Feature\StayEventFoundationTest` | 2 — check-in creates exactly one `StayEvent` row with the correct actor; a directly-recorded extra `StayEvent` does **not** mutate `Stay`, `Booking`, `Folio`, or `BookingPayment` rows (byte-for-byte attribute comparison before/after) | ✅ 2 passed |
| `Tests\Feature\StayServiceHousekeepingHookTest` (pre-existing, exercises the retrofitted `checkIn()`) | 2 | ✅ 2 passed, unchanged assertions |

**Total targeted: 14 passed, 0 failed.**

---

## 8. Full Regression Results

Full suite run **twice**, per project discipline, after the targeted pass:

| Run | Failed | Passed | Assertions | Failing classes |
|-----|--------|--------|-----------|------------------|
| 1 | 24 | 775 | 3490 | 11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest` + 1 unreproduced flake |
| 2 | 23 | 776 | 3492 | 11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest` |
| 3 (extra, to confirm) | 23 | 776 | 3492 | Identical to run 2 |

- Runs 2 and 3 are stable and identical: 23 failures, all in the two pre-existing, date-drift-based classes documented at Phase 4.2 closure and reaffirmed as the expected baseline in the Phase 4.3A plan (§12 — "must be re-verified fresh... though the category should not have shifted"). No new failure class or test name appears.
- Run 1's 24th failure did not reproduce in runs 2 or 3 and is consistent with previously-documented test-infrastructure flakiness in this project (intermittent Faker unique-value collision / a wall-clock-dependent existing test), not a regression from this milestone's code.
- **Zero new deterministic failures introduced by Milestone 1.**

---

## 9. Architecture Verification (Confirmed, Not Just Asserted)

Verified via `git diff --stat` / `git status --porcelain` scope review of every file this milestone touched:

- ✅ **Booking unchanged** — no `Booking` model, `BookingService`, or `BookingController` file appears in the diff.
- ✅ **Stay behaviour unchanged** — `Stay.php`'s only change is the additive `stayEvents()` relationship; every existing method, cast, and field is untouched.
- ✅ **Folio unchanged** — no `FolioService`, `Folio`/`FolioEntry` model, or Folio controller file appears in the diff.
- ✅ **Payment unchanged** — no `BookingPaymentService` or payment model/controller file appears in the diff.
- ✅ **Night Audit unchanged** — no `NightAuditPipeline`, `NightAuditService`, or related file appears in the diff.
- ✅ **Housekeeping unchanged** — no `HousekeepingService`, `HousekeepingController`, or Housekeeping Vue file appears in the diff.
- ✅ The only schema change in this milestone is the new `stay_events` table — `stays`, `room_assignments`, `bookings`, `folio_entries`, `booking_payments` all remain schema-unchanged (confirmed: no other migration file was added).
- ✅ `StayService.php`'s diff is exactly 4 lines (1 import, 1 constructor parameter, 1 recording call, plus the blank line already shown above) — no other line in the file changed.

---

## 10. Known Limitations

- `StayEventFoundationTest`'s regression-isolation test exercises `StayEventService::record()` called directly (a second, independent event), not a second real business action — this is intentional and suffices to isolate "does creating a StayEvent row have side effects," which is the actual invariant Milestone 1 needs to prove. It does not (and is not meant to) re-prove `checkIn()`'s own existing behavior, which is already covered by `StayServiceHousekeepingHookTest`.
- No `TransferRoom`/`UpgradeRoom`/`DowngradeRoom`/`Adjustment` enum cases exist yet — by design (§4), not an oversight; each will be added as a one-line enum case with zero migration in its respective future milestone.
- `metadata`'s JSON column is nullable at the database level (matching this codebase's existing `Resource.metadata` precedent) — the "never null" invariant is enforced at the application layer (service parameter default + model attribute default), not by a `NOT NULL` DB constraint, since the only write path is `StayEventService`.

---

## 11. Ready for ChatGPT Review

Milestone 1 — Stay Event Foundation is complete: migration verified up/down/up-clean, all targeted tests pass, two consecutive full-suite runs are stable at the pre-existing 23-failure baseline with no new failing class, and the architecture-isolation guarantees (no Folio/Night-Audit/Housekeeping/Booking file touched) are confirmed by diff, not just asserted.

**Stopping here as instructed.** No commit, no push, no tag. Awaiting ChatGPT review before Milestone 2 (Stay Extension) begins.
