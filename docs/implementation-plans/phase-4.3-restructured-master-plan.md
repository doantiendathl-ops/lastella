# Phase 4.3 Restructured Master Plan — Stay Foundation, Room Transition, Financial Completion

**Date:** 2026-07-07
**Branch:** phase-3
**Type:** Planning only — no code, no migration, no implementation
**Status:** DRAFT FOR CHATGPT REVIEW
**Supersedes:** `docs/implementation-plans/phase-4.3-master-implementation-plan.md` (this document replaces its sequencing; the architecture it was built on — `docs/architecture/phase-4.3-core-architecture.md` — remains unchanged and authoritative)

---

## 1. Executive Summary

The previously-drafted Phase 4.3 Master Implementation Plan was architecturally correct but scoped as one continuous 13-milestone phase spanning Stay lifecycle events, room transitions, and full financial completion. This is restructured here into **three smaller, operationally meaningful phases** — 4.3A Stay Foundation, 4.3B Room Transition, 4.3C Financial Completion — each independently deliverable, independently regression-checked, and each ending in its own Operational Review and User Acceptance Review before the next phase begins. This reduces implementation risk, produces a working, reviewable system after each phase rather than only at the end of a large body of work, and creates three graduated "Pilot Candidate" checkpoints instead of one all-or-nothing gate.

No architecture changes. The six-layer model, the one-directional core flow, and the seven-event Stay Event vocabulary from the approved architecture document are unchanged — only the delivery sequencing is restructured.

---

## 2. Reason for Restructure

The original Phase 4.3 bundled 11 distinct capabilities (Stay Event Foundation, Extension, Partial Checkout, Split Stay, Room Move, Upgrade, Downgrade, Adjustment Engine, Expected Total Projection, Booking Amendment Flow, Deposit Recalculation, Revenue Projection) into a single phase before any Integration & Verification checkpoint. This meant:

- No opportunity to expose any of it to real operational feedback until the entire body of work was done.
- A single large regression surface, increasing the cost of finding and isolating any issue.
- No natural point to decide "the most essential stay workflows are done — can we start a limited pilot now?" without waiting for room-transition and full financial-correction capability that a pilot may not need on day one.

Splitting along the natural fault lines already present in the architecture — Stay lifecycle basics, then room transitions, then financial correctness — produces three independently valuable increments, each ending in a real review gate, matching this project's proven Phase 4.1/4.2 discipline of shipping in reviewable, working slices.

---

## 3. Approved Architecture Summary

Unchanged from `docs/architecture/phase-4.3-core-architecture.md`:

| Layer | Role |
|-------|------|
| Booking | Commercial Contract + Guest Journey Container |
| RoomAssignment | Planning + Physical Allocation |
| Stay | Operational Reality |
| Stay Events | Audited operational changes |
| Folio | Financial Ledger |
| Payment | Settlement |
| Revenue | Reporting Layer |

**Core flow (one-directional, unchanged):**

```
Booking → RoomAssignment → Stay → Stay Events → Night Audit / Posting → Folio → Payment → Revenue
```

**Seven canonical Stay Events (unchanged):** CheckIn, ExtendStay, PartialCheckout, TransferRoom, UpgradeRoom, DowngradeRoom, Checkout. `Adjustment` remains a Folio-layer event, not a Stay event.

This document only restructures *when* each piece of capability is delivered — it does not alter what any of them mean.

---

## 4. New Phase Structure

```
Phase 4.3A — Stay Foundation        (essential Stay lifecycle: Extension, Partial Checkout, Split Stay)
Phase 4.3B — Room Transition        (Room Move, Upgrade, Downgrade)
Phase 4.3C — Financial Completion   (Adjustment Engine, Projections, Amendment Flow, Reconciliation foundation)
```

Each phase is a complete, independently-reviewable unit: scope → milestones → dependencies → testing → regression → Operational Review → User Acceptance Review → exit criteria → a graduated Pilot Candidate checkpoint.

---

## 5. Phase 4.3A — Stay Foundation

### Scope

1. Stay Event Foundation (audited event log — the prerequisite for every event in every subsequent phase)
2. Stay Extension (`ExtendStay`)
3. Partial Checkout (`PartialCheckout`)
4. Basic Split Stay support — verifying that independent `RoomAssignment`/`Stay` pairs under one `Booking` correctly support divergent extension/checkout timing per room, with no child booking

**Explicitly excluded from 4.3A:** Room Move, Upgrade, Downgrade, Adjustment Engine, Expected Total Projection, Revenue Projection.

### Milestones

| # | Milestone | Description |
|---|-----------|-------------|
| 4.3A-M1 | Stay Event Foundation | Audited event log (mirrors the existing `NightAuditBookingLog` pattern) recording event type, actor, timestamp, `Stay` reference, and event-specific metadata. Blocks every other milestone in this phase and in 4.3B/4.3C. |
| 4.3A-M2 | Stay Extension | `ExtendStay` event: moves `planned_checkout_at` forward on a `CheckedIn` Stay, logged via 4.3A-M1, zero Folio interaction — Night Audit continues billing correctly with no special-casing. |
| 4.3A-M3 | Partial Checkout (formalized) | `PartialCheckout` event: makes the already-correct per-Stay checkout behavior explicit and auditable, distinct from final `Checkout`. |
| 4.3A-M4 | Split Stay Verification | Confirms (via dedicated end-to-end tests, not new mechanism) that multiple independent `RoomAssignment`/`Stay` pairs under one `Booking` correctly support one room extending while others check out on schedule — no cross-Stay interference, no child booking. |
| 4.3A-M5 | Integration & Verification | Full regression suite (twice), end-to-end chains across every 4.3A event, phase closure report. |

### Dependencies

```
4.3A-M1 ──▶ 4.3A-M2 ──┐
        └─▶ 4.3A-M3 ──┼──▶ 4.3A-M4 ──▶ 4.3A-M5
```

M2 and M3 are mutually independent once M1 exists; M4 depends on both existing since it verifies their interaction across multiple Stays.

### Testing Strategy

- Unit tests per new service method (happy path, precondition violations) — mirrors `HousekeepingServiceTest`'s established pattern.
- Feature tests per new HTTP flow (403/422/200 coverage) — mirrors `HousekeepingControllerTest`'s established pattern.
- Dedicated end-to-end chain: Booking → Assignment → CheckIn → ExtendStay → Checkout, asserting correct nightly billing throughout (no Folio effect from the extension itself; Night Audit bills every night the Stay remains `CheckedIn`).
- Dedicated end-to-end chain: 3-room Booking, one room `ExtendStay`s while the other two `PartialCheckout` on schedule, asserting `Booking.status` aggregates correctly (`PartiallyCheckedOut` → eventually `CheckedOut`) with zero child bookings created at any point.

### Regression Strategy

- Capture the current full-suite baseline before 4.3A-M1 begins (do not assume the previously-documented 23-failure baseline still holds exactly — re-verify, since real dates have advanced since it was last measured).
- Every milestone runs the full suite **twice** before its report is written; a milestone is not complete until both runs decompose to the known baseline or an explicitly root-caused, already-documented flake.
- Confirm via `git status`/`git diff` scope review that no Financial Foundation file (`FolioService`, `BookingPaymentService`, `NightAuditPipeline`, `RevenueReportService`) is touched in 4.3A — this phase should have zero financial-layer risk.

### Operational Review

- **Who tests:** Reception staff (primary), one Manager (secondary oversight of the Extension/Partial Checkout flow from a supervisory view).
- **Workflow tested:** Booking → Check-in → Stay Extension → Partial Checkout (for a multi-room booking) → Final Checkout, run live in a browser.
- **What must pass:** every step completes without an error state; the correct room status and booking status is visible on screen at every step; Night Audit (run manually as part of the review) posts the correct number of nights for the extended room.
- **Acceptable manual workaround:** none for this phase — these are the most basic operations and must work without exception.
- **Blocker definition:** any failure to extend a stay, any failure to partially check out one room while others remain active, or any incorrect nightly charge count is a blocker.

### User Acceptance Review

Only Reception and Manager roles are relevant to this phase's scope.

- **Reception acceptance criteria:** can create a booking, check in a guest, extend a stay without any workaround, and check out one room of a multi-room booking while others remain active — all without needing to understand or touch any underlying technical concept (Stay Events are invisible plumbing to Reception).
- **Manager acceptance criteria:** can see, in the existing UI, that a Stay was extended and that a partial checkout occurred, with correct timestamps and correct aggregate booking status.

### Exit Criteria

- [ ] 4.3A-M1 through M5 complete, each with an approved milestone report.
- [ ] Full regression suite stable, zero new deterministic failures.
- [ ] Operational Review passed with zero blockers.
- [ ] User Acceptance Review signed off by Reception and Manager.
- [ ] → **Pilot Candidate 1** reached: the system is eligible for a narrowly-scoped limited pilot covering only basic booking/check-in/extension/partial-checkout/checkout, pending an explicit go/no-go business decision — this is a candidacy checkpoint, not an automatic pilot start.

---

## 6. Phase 4.3B — Room Transition

### Scope

1. Room Move
2. `TransferRoom` event
3. Upgrade
4. Downgrade
5. Rate resolution for transferred/upgraded/downgraded stays
6. Housekeeping hook for the vacated room

**Explicitly excluded from 4.3B:** Adjustment Engine, Revenue Projection, full financial reconciliation.

### Milestones

| # | Milestone | Description |
|---|-----------|-------------|
| 4.3B-M1 | TransferRoom Event Mechanics | New `Transferred` status on `Stay`/`RoomAssignment` (distinct from `CheckedOut` — requires its own small ADR); new linked `RoomAssignment`/`Stay` pair in the destination room via `transferred_from_stay_id`/`transferred_to_stay_id`; availability gate on the destination room identical to a fresh check-in (`VACANT_CLEAN`/`INSPECTED` required). |
| 4.3B-M2 | Housekeeping Hook for Vacated Room | The room being transferred out of triggers the same `autoMarkDirtyOnCheckout()`-style hook as an ordinary checkout — Housekeeping does not need to know *why* the room became vacant, only that it now needs cleaning. Reuses the existing Phase 4.2 Housekeeping cycle unmodified. |
| 4.3B-M3 | Rate Resolution + Upgrade/Downgrade | Rate resolved for the new `RoomAssignment` from `RoomRate.overnight_price` (date- and room-type-aware) rather than the frozen `BookingRequirement.room_price`; Upgrade/Downgrade are the same `TransferRoom` mechanism with the new rate simply higher or lower than the old. |
| 4.3B-M4 | Integration & Verification | Full regression suite (twice); end-to-end chains for move/upgrade/downgrade; a genuine concurrent-transfer-request test (two simultaneous `TransferRoom` attempts on the same Stay, confirming `SELECT FOR UPDATE` correctly serializes them, per the ADR-87 lock pattern precedent); phase closure report. |

### Dependencies

```
4.3A complete (needs the Stay Event Foundation and audited-event pattern from 4.3A-M1)
        │
        ▼
4.3B-M1 (Transfer mechanics) ──▶ 4.3B-M2 (Housekeeping hook) ──▶ 4.3B-M3 (Rate/Upgrade/Downgrade) ──▶ 4.3B-M4 (Integration)
```

4.3B-M1 strictly depends on 4.3A-M1 (the event-log foundation) but not on 4.3A-M2/M3/M4 — Room Transition does not require Extension or Partial Checkout to be in active use, only the event-logging mechanism they also use.

### Testing Strategy

- Unit tests for `TransferRoom`/Upgrade/Downgrade service logic — happy path, availability-gate violations, ownership/permission edge cases.
- Feature tests for the HTTP-level transfer flow.
- Dedicated end-to-end chain: Booking → Assignment → CheckIn → TransferRoom → Checkout (in the new room), asserting: the vacated room's Housekeeping hook fires and the room enters the normal cleaning cycle; the destination room's availability gate is enforced (attempt a transfer into a `CLEANING`/`OUT_OF_ORDER` room and confirm it is rejected); billing continues correctly under the new room's resolved rate.
- Dedicated end-to-end chain: Upgrade and Downgrade variants, asserting correct `RoomRate`-based resolution (not the stale `BookingRequirement.room_price`).
- Concurrency test: two simultaneous `TransferRoom` calls against the same `Stay`, confirming only one succeeds and the other receives a correct, safe rejection — not a race condition or double-transfer.

### Regression Strategy

- Same twice-per-milestone full-suite discipline as 4.3A.
- **Housekeeping regression check is mandatory for every milestone in this phase** — re-run the full 124+ test Housekeeping suite from Phase 4.2 after every milestone, since 4.3B is the first phase to trigger the Housekeeping checkout hook from a code path other than `StayService::checkOut()`.
- Confirm `RoomAvailabilityRuleService`/`RoomAvailabilityCheckerService` are not modified in a way that changes CLEANING/OUT_OF_ORDER handling established in Phase 4.2 — 4.3B only *calls* the existing availability gate, it must not need to change it.

### Operational Review

- **Who tests:** Reception (executes the move/upgrade/downgrade), Housekeeping staff (confirms the vacated room correctly enters their workflow), Manager (approves upgrades, as would be typical hotel practice).
- **Workflow tested:** live in a browser — check in a guest, move them to a different room mid-stay, confirm the old room shows up correctly on the Housekeeping Board as dirty, confirm the new room shows occupied, repeat for an Upgrade and a Downgrade.
- **What must pass:** the transfer completes without error; the old room's Housekeeping card appears correctly; the new room cannot be selected as a transfer destination while it is still `CLEANING` or `OUT_OF_ORDER`; billing after the transfer reflects the new room's rate.
- **Acceptable manual workaround:** none for the transfer mechanics themselves — this is the phase's entire purpose. Pricing-delta handling (extra deposit for an upgrade, refund for a downgrade) may still be a manual, off-system conversation with the guest at this stage, since the Deposit Recalculation prompt does not exist until Phase 4.3C.
- **Blocker definition:** any transfer that leaves the system in an inconsistent state (e.g., both rooms showing occupied, or neither), any transfer into a room that isn't actually ready, or any failure of the vacated room to enter Housekeeping's cycle.

### User Acceptance Review

Reception, Housekeeping, and Manager are relevant; Accounting is not yet involved (financial correction/projection is Phase 4.3C).

- **Reception acceptance criteria:** can move a guest to a new room, or upgrade/downgrade them, entirely within the existing booking — no child booking, no confusing workaround.
- **Housekeeping acceptance criteria:** a room vacated by a transfer appears on the Housekeeping Board exactly like a room vacated by a normal checkout, with no special training needed to recognize it.
- **Manager acceptance criteria:** can see, from the Booking's history, that a transfer/upgrade/downgrade occurred, which room the guest moved from and to, and when.

### Exit Criteria

- [ ] 4.3B-M1 through M4 complete, each with an approved milestone report.
- [ ] Full regression suite stable, including the Housekeeping-specific regression check, zero new deterministic failures.
- [ ] Operational Review passed with zero blockers.
- [ ] User Acceptance Review signed off by Reception, Housekeeping, and Manager.
- [ ] → **Pilot Candidate 2** reached: the system is eligible for a wider limited pilot including room moves/upgrades/downgrades, still pending explicit go/no-go decision, still without in-system financial correction.

---

## 7. Phase 4.3C — Financial Completion

### Scope

1. Booking Amendment Flow
2. Expected Total Projection
3. Deposit Recalculation
4. Folio Adjustment Engine
5. Correction Entries
6. Revenue Projection
7. Revenue Reconciliation foundation

### Milestones

| # | Milestone | Description |
|---|-----------|-------------|
| 4.3C-M1 | Booking Amendment Flow — routing rule | A pre-arrival `Booking` edit remains direct, as today, with no Folio interaction. A post-arrival edit attempt (any room already checked in) is structurally blocked/redirected to the appropriate Stay Event instead — closing the "booking-wide cascade" risk at the code level, not just operationally. This milestone does not yet require Projection to exist — it is a validation/routing change only. |
| 4.3C-M2 | Expected Total Projection | Read-only, per-Booking projection: `SUM` over every current `RoomAssignment`'s resolved rate × planned nights, plus already-posted actuals for nights already billed. Clearly presented as a forecast, never a charge. |
| 4.3C-M3 | Deposit Recalculation | Builds on M1+M2: the Booking Amendment Flow now also computes the old and new projection and surfaces the delta to staff as "expected additional deposit: X" or "expected refund: X" — never an automatic charge; staff record a new `Payment` only if money actually changes hands. |
| 4.3C-M4 | Folio Adjustment Engine | New `ADJUSTMENT` `ChargeType`; new `reversal_of_entry_id` (nullable, self-referencing) column on `FolioEntry`; new additive-only posting method. The original entry is never edited, voided, or deleted — ADR-50 is fully preserved. |
| 4.3C-M5 | Correction Entries | The named, staff-facing use case built on M4: correcting a wrongly-priced or wrongly-timed system entry discovered after posting (e.g., a shortened stay's extra-night overcharge, a downgrade's rate difference). Same mechanism as M4 — this milestone is the staff-facing workflow around it, not a second engine. |
| 4.3C-M6 | Revenue Projection | New `RevenueReportService` method computing forward-looking expected revenue across all active `RoomAssignment`/`Stay` records — the aggregate, management-reporting counterpart to M2's per-booking projection. Existing actuals-only methods (`dailySummary`, `periodSummary`, `revenueBySource`, `exportRows`) remain untouched. |
| 4.3C-M7 | Revenue Reconciliation Foundation | A read-only comparison of M6's projection against actual posted `Folio` entries, surfacing variance. Explicitly a **foundation** only — not a full reconciliation dashboard, consistent with the Go-live Core Scope Review's deferral of advanced dashboards to Phase 5.x. |
| 4.3C-M8 | Integration & Verification | Full regression suite (twice); end-to-end chains for amendment/projection/adjustment/reconciliation; phase closure report. |

### Dependencies

```
4.3A + 4.3B complete
        │
        ▼
4.3C-M1 (Amendment routing) ──▶ 4.3C-M2 (Projection) ──▶ 4.3C-M3 (Deposit Recalculation)
        │
        ▼
4.3C-M4 (Adjustment Engine) ──▶ 4.3C-M5 (Correction Entries)
        │
        ▼
4.3C-M6 (Revenue Projection) ──▶ 4.3C-M7 (Reconciliation Foundation) ──▶ 4.3C-M8 (Integration)
```

M1 (routing rule) has no dependency on M2 and can ship first, exactly as scoped; its full delta-surfacing capability (M3) only activates once M2 exists — both land within this same phase, so this is an intra-phase sequencing detail, not a cross-phase blocker. M4/M5 (Adjustment Engine) has no technical dependency on M1/M2/M3 and could in principle be pulled earlier, but is sequenced here to respect this phase's given scope ordering. M6/M7 depend on M2's projection logic being proven first (Revenue Projection is the aggregate version of the same computation).

### Testing Strategy

- Unit tests for every new service method (Amendment routing, Projection computation, Adjustment posting).
- Feature tests for every new HTTP flow.
- Dedicated end-to-end chain: a post-arrival Booking edit attempt, asserting it is correctly rejected/redirected rather than silently cascading to an active Stay (closing the Gap Analysis's original Scenario 1 finding).
- Dedicated end-to-end chain: an Extension or Upgrade that changes the Expected Total, asserting the Deposit Recalculation prompt surfaces the correct delta.
- Dedicated end-to-end chain: an Adjustment Engine correction, asserting the original entry is byte-for-byte untouched and the new entry correctly references it via `reversal_of_entry_id`.
- Dedicated end-to-end chain: Revenue Projection vs. actual Folio totals for a booking with a known, deliberately-introduced variance, asserting the Reconciliation Foundation surfaces it correctly.

### Regression Strategy

- Same twice-per-milestone full-suite discipline as prior phases.
- **This phase carries the highest financial-integrity risk of the three** — every milestone's report must explicitly confirm, via code review of the diff (not just test pass/fail), that ADR-50 (system entries never voidable/editable) is not weakened anywhere, since the entire point of this phase is to add a correction path *without* touching that guarantee.
- Confirm the existing actuals-only Revenue methods (`dailySummary`, `periodSummary`, `revenueBySource`, `exportRows`) are untouched — Projection/Reconciliation must be strictly additive new methods, never a modification of the actuals path.

### Operational Review

- **Who tests:** Reception (booking amendments), Accounting (adjustment entries, revenue projection/reconciliation), Manager (deposit recalculation approval, oversight of corrections).
- **Workflow tested:** attempt a post-arrival booking edit and confirm it correctly redirects; extend/upgrade a stay and confirm the deposit delta prompt appears with the right number; deliberately create a pricing error and correct it via the Adjustment Engine; review the Revenue Projection vs. actual for a test booking.
- **What must pass:** every projection number is correct and clearly labeled as a projection; every adjustment is visible in the Folio as a new, linked entry, never a silent edit; the original mis-posted entry remains visible and unmodified.
- **Acceptable manual workaround:** Revenue Reconciliation Foundation may still require manual review/judgment to act on a surfaced variance — full automated reconciliation workflow is Phase 5.x scope, not this phase's.
- **Blocker definition:** any adjustment that edits or removes an original entry (a direct ADR-50 violation) is an automatic, non-negotiable blocker; any projection number that is wrong or indistinguishable from an actual charge is a blocker.

### User Acceptance Review

All four roles are relevant to this phase.

- **Reception acceptance criteria:** attempting to edit a post-arrival booking date is clearly and helpfully redirected to the correct Stay Event action, not a confusing dead end.
- **Housekeeping acceptance criteria:** none specific to this phase (no housekeeping-facing change).
- **Accounting acceptance criteria:** can view and act on a corrected/adjusted entry with full confidence the original audit trail is intact; can distinguish projected from actual revenue at a glance.
- **Manager acceptance criteria:** can see and approve a deposit-recalculation delta before it becomes a staff action; can see a reconciliation variance and understand what it means without additional training.

### Exit Criteria

- [ ] 4.3C-M1 through M8 complete, each with an approved milestone report.
- [ ] Full regression suite stable, zero new deterministic failures.
- [ ] Explicit code-review confirmation that ADR-50 is unweakened.
- [ ] Operational Review passed with zero blockers.
- [ ] User Acceptance Review signed off by Reception, Accounting, and Manager.
- [ ] → **Pilot Candidate 3** reached: the system is eligible for a full-scope pilot covering the entire core operational and financial loop, pending explicit go/no-go decision.

---

## 8. Updated Roadmap

```
Phase 4.3A — Stay Foundation
        │
        ▼
   Operational Review
        │
        ▼
   User Acceptance Review
        │
        ▼
   Pilot Candidate 1
        │
        ▼
Phase 4.3B — Room Transition
        │
        ▼
   Operational Review
        │
        ▼
   User Acceptance Review
        │
        ▼
   Pilot Candidate 2
        │
        ▼
Phase 4.3C — Financial Completion
        │
        ▼
   Operational Review
        │
        ▼
   User Acceptance Review
        │
        ▼
   Pilot Candidate 3
        │
        ▼
Production Freeze          (architecture/schema/workflow frozen, bug fixes only — per
        │                    docs/architecture/phase-4.3-core-architecture.md §12)
        ▼
Official Production
        │
        ▼
Phase 5.x — Expansion Modules (dashboards, forecasting, automation, notifications,
                                overstay conflict scanner)
```

**Each "Pilot Candidate" checkpoint is a candidacy, not an automatic pilot start** — whether to actually begin a limited pilot at Candidate 1 or 2, versus waiting for full Candidate 3 readiness, remains an explicit business decision at each checkpoint, informed by that phase's Operational Review and User Acceptance Review outcomes.

---

## 9. Risk Assessment

| Risk | Phase | Severity | Mitigation |
|------|-------|----------|-----------|
| Stay Event Foundation designed incorrectly, forcing rework across all later phases | 4.3A | HIGH | 4.3A-M1 is deliberately the very first milestone, reviewed in isolation before any event is built on top of it |
| Room transfer leaves the system in an inconsistent state under concurrency | 4.3B | HIGH | Dedicated concurrency test in 4.3B-M4, reusing the proven ADR-87 lock pattern |
| Housekeeping regression from a new trigger path (transfer, not just checkout) | 4.3B | MEDIUM | Mandatory full Housekeeping suite re-run after every 4.3B milestone |
| Adjustment Engine accidentally weakens ADR-50 immutability | 4.3C | HIGH | Explicit code-review gate (not just test-pass) required in every 4.3C milestone report |
| Projection numbers confused with actual charges by staff or by code | 4.3C | MEDIUM | UI/data clearly labeled; dedicated tests asserting projection and actuals are never blended in a single computation |
| Phase 4.3B or 4.3C scope creeps back toward the original monolithic Phase 4.3 | All | MEDIUM | Each phase's "Explicitly excluded" list is treated as a hard boundary, re-confirmed at that phase's Integration & Verification milestone |
| Pilot Candidate checkpoints create pressure to start a pilot before a later phase is actually ready | 4.3A/4.3B | LOW–MEDIUM | Explicit framing that a Candidate checkpoint is eligibility, not an automatic trigger — the go/no-go decision is separate and explicit each time |

---

## 10. Regression Baseline Strategy

- **One shared baseline across all three phases** — the full-suite failure count and identity (currently the established 23 pre-existing date-drift failures, re-verified at the start of 4.3A since real time has advanced) is the single reference point every phase's regression checks compare against.
- **No phase is considered complete while introducing any new deterministic failure**, regardless of which phase it originated in — a regression introduced in 4.3B, for example, is 4.3B's responsibility to fix before its own Integration & Verification milestone closes, not deferred to 4.3C.
- **Known non-deterministic flakes** (`PerStayAttributionTest`'s Faker collision, `LateCheckoutFeeTest`'s wall-clock-hour bug) remain documented and excluded from the "new regression" count, exactly as established in Phase 4.2 — every phase's report should note if either was observed, without treating it as a new-phase-caused failure.
- **Cross-phase regression discipline:** 4.3B's Integration & Verification must re-confirm 4.3A's end-to-end chains still pass; 4.3C's must re-confirm both 4.3A's and 4.3B's — later phases never assume earlier phases stay correct without re-verification.

---

## 11. Deliverables Per Phase

| Phase | Deliverables |
|-------|-------------|
| 4.3A | `docs/reports/phase-4.3a-backend-m{1..5}-report.md`, `docs/reports/phase-4.3a-operational-review.md`, `docs/reports/phase-4.3a-user-acceptance-review.md`, updated `docs/architecture/phase-4.3-core-architecture.md` if any detail is refined during delivery |
| 4.3B | `docs/reports/phase-4.3b-backend-m{1..4}-report.md`, `docs/reports/phase-4.3b-operational-review.md`, `docs/reports/phase-4.3b-user-acceptance-review.md`, the required `Transferred`-status ADR |
| 4.3C | `docs/reports/phase-4.3c-backend-m{1..8}-report.md`, `docs/reports/phase-4.3c-operational-review.md`, `docs/reports/phase-4.3c-user-acceptance-review.md`, `docs/architecture/phase-4.3-final-baseline.md` consolidating all three phases as the closing architecture-baseline artifact for the whole of Phase 4.3 |

Each milestone report follows the established Phase 4.1/4.2 format (Objective, Files Changed, Tests, Regression, Ready for Review) — not repeated here since that convention is already proven and unchanged.

---

## 12. Stop Conditions

This document is a **planning artifact only**. Per the instructions governing this task:

- No code was written, no source file modified, no migration created, no database changed.
- No test was written or modified.
- No commit, no push.
- After this document is created, work **stops** and awaits ChatGPT review of this restructured plan.
- **Phase 4.3A implementation planning (the detailed, code-level implementation plan for 4.3A specifically, matching the depth of `phase-4.2-housekeeping-workflow-implementation-plan.md`) begins only after this restructured master plan is explicitly approved.**
- No phase in this document (4.3A, 4.3B, or 4.3C) begins implementation until its immediately-preceding phase's Exit Criteria are met and its Operational Review + User Acceptance Review are signed off, per §5–§7.
