# Phase 4.3 Core Architecture — Stay Lifecycle Evolution & Financial Core Completion

**Date:** 2026-07-06
**Branch:** phase-3
**Type:** Architecture & Planning only — no code, no migration, no implementation
**Status:** DRAFT FOR CHATGPT ARCHITECTURE REVIEW
**Builds on:** `docs/reports/revenue-folio-gap-analysis.md`, `docs/architecture/stay-lifecycle-architecture.md`, `docs/roadmaps/go-live-core-scope-review.md` (all approved)

---

## 1. Executive Summary

This document completes the architectural picture started by the Gap Analysis and the Stay Lifecycle Architecture, incorporating the roadmap adjustment: instead of freezing the system immediately after Phase 4.2, the project will first **complete all core operational hotel workflows** — Room, Booking, Stay, Folio, Payment — across two focused phases (4.3 Stay Lifecycle Evolution, 4.3B Financial Core Completion), then run a formal **Operational Readiness Review**, then Pilot, then a properly-scoped **Production Freeze**, then Official Production.

**One important reconciliation with prior documents:** the earlier Go-live Core Scope Review named the immediate next phase "Phase 4.2.5 – Production Freeze" with a confirm-and-bugfix scope. That confirmation purpose is **absorbed into the Operational Readiness Review** defined in §9 of this document — it is not skipped, only renamed and repositioned to its correct place in the sequence, immediately before Pilot rather than immediately after Phase 4.2. **"Production Freeze" is redefined in §12** to mean what it should mean in a real production system: a strict architecture/schema/workflow freeze gate *after* Pilot, not a testing activity. This document supersedes the phase-naming (not the content or intent) of the earlier review on this one point.

---

## 2. Architecture Principles

Six layers, each with one owner and one responsibility:

| Layer | Represents | Owns |
|-------|-----------|------|
| **Booking** | Commercial Contract + Guest Journey Container | Reservation intent, customer/sales data, the aggregate of the guest's entire visit across every room and every Stay |
| **RoomAssignment** | Planning + Physical Allocation | The current binding of one physical room to one Booking, for a date range and a resolved rate — active as a *plan* pre-arrival and as the *live physical allocation* record from check-in until superseded by a transfer |
| **Stay** | Operational Reality | What actually happened in one room, for one occupancy episode: actual timestamps, status, the sequence of Stay Events applied to it |
| **Folio** | Financial Ledger | Posted financial entries only, fed exclusively by Stay Events (via Night Audit or event-triggered postings) or explicit adjustments |
| **Payment** | Settlement | Money actually received or refunded against a Booking's aggregate balance |
| **Revenue** | Reporting Layer | Read-only aggregation over Folio (actuals) and, from Phase 4.3B, a read-only projection over active RoomAssignments/Stays (expected) — never a write path |

**Core flow, strictly one-directional:**

```
Booking → RoomAssignment → Stay → Stay Events → Night Audit → Folio → Payment → Revenue
```

**The rule that governs every design decision in this document:** no downstream layer may modify upstream data. `Folio` never writes to `Stay`. `Stay` never writes to `Booking`. `Payment` never writes to `Folio` entries directly (it settles a balance; it does not alter what was charged). `Revenue` never writes anything, anywhere.

---

## 3. Booking / Stay / Folio Relationships

```
Booking (Guest Journey Container)
  │  owns: customer, requirements, overall status, aggregate of everything below
  │
  ├── RoomAssignment #1 ──── Stay #1 (Room 101, the whole episode in that room)
  │        │  planning + physical allocation        │  operational reality: CheckIn → ExtendStay* → Checkout
  │        │  superseded on TransferRoom             │  or → Transferred (if the guest moves rooms)
  │        │
  ├── RoomAssignment #2 ──── Stay #2 (Room 205, if the guest transferred here)
  │        (created by a TransferRoom event, linked to Stay #1 via transfer references)
  │
  └── RoomAssignment #3 ──── Stay #3 (Room 310, an independent room in a multi-room booking)
           (own independent planned dates, own independent actual checkout — Split Stay, §7.3)

Folio (1:1 with Booking) ◄── fed by every Stay's Events, never by Booking directly
Payment (N:1 with Booking) ◄── settles the Folio's aggregate balance, independent of which Stay generated which charge
```

A `Booking` with 3 rooms is **one Guest Journey Container** holding 3 independent `RoomAssignment`/`Stay` timelines, one shared `Folio`, and one shared pool of `Payment` rows. **No child booking is ever created for any scenario in this architecture** — multi-room divergence, room transfer, and upgrade/downgrade are all modeled as multiple `RoomAssignment`/`Stay` records under the same `Booking`, exactly per the explicit product direction.

---

## 4. Stay Event Model

Seven canonical events, each a **first-class, audited record** (not an implicit side effect of a field update):

| Event | Preconditions | RoomAssignment effect | Stay effect | Folio effect |
|-------|---------------|--------------------------|-------------|--------------|
| **CheckIn** | `RoomAssignment.status = Assigned`; room available | → `CheckedIn` | Created/→ `CheckedIn`, `actual_checkin_at` set | First-night `ROOM` entry posts; `EARLY_CHECKIN` entry posts if applicable (unchanged from today) |
| **ExtendStay** | `Stay.status = CheckedIn` | `end_at` moves forward | `planned_checkout_at` moves forward (event-logged, not a silent overwrite) | **None** — Night Audit simply keeps billing as long as `Stay.status` stays `CheckedIn`, exactly as it already does today |
| **PartialCheckout** | `Stay.status = CheckedIn`; other Stays on the Booking remain active | → `CheckedOut` | → `CheckedOut`, `actual_checkout_at` set | `LATE_CHECKOUT` entry posts if applicable; **Booking does not finalize** (other Stays still open) |
| **TransferRoom** | `Stay.status = CheckedIn`; new room available (`VACANT_CLEAN`/`INSPECTED`) | Old assignment → new `Transferred` status (distinct from `CheckedOut`); new `RoomAssignment` created, `CheckedIn` | Old Stay → new `Transferred` status; new `Stay` created, `CheckedIn`, linked via `transferred_from_stay_id`/`transferred_to_stay_id` | **None directly** — old room's last partial night bills as usual via the existing per-night mechanism; new room begins its own night-by-night billing under its own resolved rate; vacated room triggers the existing Housekeeping checkout hook |
| **UpgradeRoom** | Same as TransferRoom | Same as TransferRoom, new rate resolved higher | Same as TransferRoom | Same as TransferRoom — pricing difference is handled by Phase 4.3B's Deposit Recalculation / Adjustment flow, not by this event itself |
| **DowngradeRoom** | Same as TransferRoom | Same as TransferRoom, new rate resolved lower | Same as TransferRoom | Same as TransferRoom — any refund/credit is a Phase 4.3B concern |
| **Checkout** (final) | `Stay.status = CheckedIn`; this is the last active Stay on the Booking | → `CheckedOut` | → `CheckedOut`, `actual_checkout_at` set | `LATE_CHECKOUT` entry posts if applicable; balance verified = 0; Folio auto-closes; **Booking finalizes** (unchanged from today) |

**`Adjustment` is deliberately not in this list.** It is a **Folio-layer** event (Phase 4.3B, §8), not a Stay-layer event — it corrects money, not operational state, and therefore belongs to the financial completion phase, not the stay lifecycle phase.

**TransferRoom design note (refines the earlier Stay Lifecycle Architecture in light of the clarified RoomAssignment role):** a `Stay` still represents exactly one room-occupancy episode — it does not span rooms. `RoomAssignment`'s elevated "physical allocation" role means it remains the live, meaningful record for as long as it is current, not disposable pre-arrival scaffolding — but a transfer still supersedes it with a new one, because a room assignment is inherently time-bound to one room. `Booking`, as the Guest Journey Container, is what ties the old and new `Stay`/`RoomAssignment` pairs into one continuous guest visit.

---

## 5. Operational Flow

```
1. Booking created / amended (pre-arrival only, freely editable — Layer 1)
2. RoomAssignment created per room (Planning phase — editable independently per room, §7.3)
3. CheckIn → RoomAssignment enters Physical Allocation phase; Stay begins (Operational Reality)
4. Zero or more of: ExtendStay, TransferRoom, UpgradeRoom, DowngradeRoom, PartialCheckout
5. Checkout (final, per Stay's room) → Housekeeping takes over the vacated room
6. Night Audit reads current Stay state each business date → posts Folio entries
7. Payment settles the Folio's balance, at any point, independent of which Stay/event generated which charge
8. Revenue reports on posted Folio entries (actuals) and, from 4.3B, projects expected totals from active RoomAssignments/Stays
```

Housekeeping sits entirely inside step 5 — a vacated room (from ordinary Checkout **or** from a TransferRoom's vacated side) enters the same `VACANT_DIRTY → CLEANING → INSPECTED → VACANT_CLEAN` cycle already built in Phase 4.2, with zero Folio interaction at any point, unchanged.

---

## 6. Financial Flow

```
Stay Event happens
        │
        ▼
Does this event itself trigger a fee job?  (CheckIn → early-checkin; Checkout/PartialCheckout → late-checkout)
        │                                              │
       yes ──────────────────────────────────────────▶ post that fee entry now
        │
       no  (ExtendStay, TransferRoom, UpgradeRoom, DowngradeRoom)
        │
        ▼
State change only — Stay/RoomAssignment fields update, event logged
        │
        ▼
Next Night Audit run reads current Stay state → posts the night's ROOM charge
   at whichever RoomAssignment/Stay's resolved rate is current for that Stay
        │
        ▼
Folio accumulates          Payment settles balance          Revenue reports actuals (+ Phase 4.3B: projects expected)
```

**Every charge is still generated by Night Audit or an event-triggered fee job — never by a Stay Event directly rewriting Folio.** This preserves the single most important rule from the Gap Analysis and Stay Lifecycle Architecture without exception, including for the new events introduced in this phase.

---

## 7. Phase 4.3 Architecture (Stay Lifecycle Evolution)

### 7.1 Stay Extension

An `ExtendStay` event moves `Stay.planned_checkout_at` (and the corresponding `RoomAssignment.end_at`) forward, logged as a discrete event rather than a field overwrite. No Folio effect — Night Audit already bills correctly for as long as `Stay.status` remains `CheckedIn`, independent of the planned date. This is the lowest-risk item in Phase 4.3: it formalizes and audits a capability that is already financially correct today.

### 7.2 Partial Checkout

Formalizes the existing per-Stay checkout behavior (already correct — `BookingService::updateBookingStayStatus()` already computes `PartiallyCheckedOut` correctly) as an explicit, logged `PartialCheckout` event distinct from a `Checkout` event, so the event history clearly shows *which* room checked out and *when*, without waiting for the whole Booking to finalize.

### 7.3 Split Stay (one Booking, multiple independent Stay timelines)

Not a new entity — the *capability* that falls out once `RoomAssignment` gains an independent, per-assignment update method (dates, room, rate) usable pre-arrival, and `ExtendStay`/`TransferRoom` are available post-arrival. A 3-room booking where each room has its own arrival, extension, and departure timeline is simply three independent `RoomAssignment`/`Stay` pairs under one `Booking` — exactly the model already described in §3.

### 7.4 Room Move (TransferRoom)

See §4's event table and design note. Key architectural requirements:

- The vacated room must trigger the same Housekeeping hook as an ordinary checkout (the room is now dirty and needs cleaning — Housekeeping does not need to know *why* the room became vacant).
- The destination room must pass the same availability gate as a fresh check-in (`VACANT_CLEAN` or `INSPECTED`) — a transfer can never move a guest into a room that isn't actually ready.
- The old `RoomAssignment`/`Stay` pair needs a status distinguishable from ordinary `CheckedOut`/`Checkout` (proposed: `Transferred`) so reporting can tell "guest left the hotel" apart from "guest moved to another room" — this is a genuine enum addition requiring its own ADR before implementation (carried forward from the Stay Lifecycle Architecture's Open Questions).
- The link between old and new `Stay` (`transferred_from_stay_id`/`transferred_to_stay_id`) must be bidirectional and queryable, so the Booking's full guest-journey history can be reconstructed from `Stay` records alone.

### 7.5 Upgrade

A `TransferRoom` where the new `RoomAssignment`'s resolved rate (from the `RoomRate` table, by `room_type_id` and date — **not** the frozen `BookingRequirement.room_price`) is higher than the old. Structurally identical to a room move; the pricing consequence (additional deposit expected) is a Phase 4.3B concern, not a Phase 4.3 structural one.

### 7.6 Downgrade

Same mechanism as Upgrade, new rate lower. Any refund/credit consequence is likewise a Phase 4.3B concern.

### 7.7 Stay Event Model (foundation)

A first-class, audited event log — conceptually similar to the existing `NightAuditBookingLog` pattern already proven in this codebase — recording every event in §4 against its `Stay`, with actor, timestamp, and event-specific metadata. This is the **prerequisite foundation** for every other item in this section: without it, `ExtendStay`/`TransferRoom`/etc. would just be silent field updates, repeating the exact anti-pattern the Gap Analysis flagged for `Booking` edits. **This should be the first milestone of Phase 4.3** (see the companion Master Implementation Plan).

---

## 8. Phase 4.3B Architecture (Financial Core Completion)

### 8.1 Expected Total Projection

A read-only, computed (never posted) view: `SUM` over every **current** (non-superseded) `RoomAssignment`'s resolved rate × its planned night count, plus already-posted `Folio` actuals for nights already billed. Must be presented to staff as clearly distinct from the actual ledger — a projection is a forecast, never a charge.

### 8.2 Booking Amendment Flow

A **new, explicit workflow** replacing the current freeform `updateBooking()` date-edit path for anything touching an already-checked-in room:

- **Pre-arrival amendments** (no Stay has started yet): remain a direct `Booking`/`RoomAssignment` edit, exactly as today, with **no** Folio interaction — correctly zero-risk.
- **Post-arrival amendments** (a room has already checked in): **must** go through a Stay Event (`ExtendStay`, `TransferRoom`, etc.) — a `Booking`-level edit must no longer be able to silently touch an active `Stay`. This closes the Gap Analysis's "booking-wide cascade" risk structurally, not just operationally.
- Every amendment (pre- or post-arrival) computes the **old** Expected Total Projection, applies the change, computes the **new** projection, and surfaces the delta to staff.

### 8.3 Deposit Recalculation

A **staff-facing prompt**, triggered by the Booking Amendment Flow's computed delta — never an automatic charge, never an automatic Folio posting. It surfaces "expected additional deposit: X" (or "expected refund: X" for a downgrade/shortening) and lets staff record a new `Payment` row if money is actually collected or returned. Staff remain firmly in the loop; the system never moves money on its own.

### 8.4 Folio Adjustment Engine

A new `ADJUSTMENT` `ChargeType` plus a `reversal_of_entry_id` (nullable, self-referencing) column on `FolioEntry`. A new posting method that creates an **additive, linked** entry referencing the original — the original entry is never edited, voided, or deleted, fully preserving ADR-50's immutability guarantee while finally giving the business a correction path.

### 8.5 Correction Entries

The specific, named use case for the Adjustment Engine: correcting a wrongly-priced or wrongly-timed system entry discovered after posting (a shortened stay's extra-night overcharge; a downgrade's rate difference; any operational error). Same mechanism as §8.4 — this subsection exists to make the use case explicit for staff-facing documentation and training, not to introduce a second mechanism.

### 8.6 Revenue Projection

`RevenueReportService` gains a new, clearly-separate read-only method computing forward-looking expected revenue across all active `RoomAssignment`/`Stay` records (the aggregate, management-reporting counterpart to §8.1's per-booking projection). Existing methods (`dailySummary`, `periodSummary`, `revenueBySource`, `exportRows`) remain untouched and actuals-only — projection is additive, never blended into the actuals view.

---

## 9. Operational Readiness Review

A **process**, run once Phase 4.3 and 4.3B are code-complete and automated-test-verified, before Pilot Go-live is authorized. This is where Phase 4.2.5's original "confirm in a real browser" purpose lives now.

**Process design:**

1. **Scope:** every module in the checklist below, walked through live in a browser by a real person — not inferred from automated test output.
2. **Roles:** one technical reviewer (confirms no error states, no console errors, no broken navigation) plus one operational reviewer (an actual front-desk/housekeeping staff member, or someone role-playing that function) who judges whether the workflow is usable in practice, not just functional in principle.
3. **Checklist (minimum):** Booking, Room Assignment, Check-in, Stay (including Extend/Transfer/Upgrade/Downgrade/Partial Checkout), Payment, Checkout, Housekeeping, Night Audit, Folio.
4. **Exit gate:** every checklist item reaches a **PASS** or an explicitly accepted, documented workaround (mirroring the two named Pilot-time workarounds from the Go-live Core Scope Review, re-evaluated now that Phase 4.3/4.3B should have removed most of them). Any **HIGH**-severity finding blocks Pilot authorization until resolved.
5. **Output:** a signed-off Operational Readiness Report, the direct gating artifact for Pilot Go-live (§11).

This process, not a phase number, is what "confirm the system actually works" means going forward.

---

## 10. Daily Operation Checklist

A realistic hotel day, mapped to system modules — for staff training material and for the Operational Readiness Review's live-walkthrough script.

### Morning Shift

| Activity | System module | Why it matters |
|----------|---------------|-----------------|
| **Arrivals review** | Booking, Room Availability | Front desk confirms today's expected arrivals and room readiness before guests appear |
| **Check-in** | Stay (CheckIn event) | The moment operational reality begins for that room — triggers room status, first-night charge, early-check-in fee if applicable |
| **Deposits** | Payment | Collecting/recording the agreed deposit against the Booking's balance |
| **Room Assignment** | RoomAssignment | Confirming or adjusting which physical room a guest occupies, still in the Planning phase for not-yet-arrived guests |

### Afternoon Shift

| Activity | System module | Why it matters |
|----------|---------------|-----------------|
| **Stay Extension** | Stay (ExtendStay event) | A guest decides to stay longer — recorded as an event, billed automatically by the next Night Audit, no manual recalculation needed |
| **Room Move** | Stay (TransferRoom event) | A guest needs to change rooms mid-visit — old room releases to Housekeeping, new room's availability is verified, billing continues seamlessly under the new room's rate |
| **Partial Checkout** | Stay (PartialCheckout event) | One room in a multi-room booking finishes its visit while others continue — Booking correctly reflects partial completion |
| **Upgrade** | Stay (UpgradeRoom event) | Same mechanism as Room Move, with the pricing difference flagged for Deposit Recalculation |
| **Checkout** | Stay (Checkout event) | Final departure for a room — balance verified, late fee applied if applicable, room released to Housekeeping |

### Night Shift

| Activity | System module | Why it matters |
|----------|---------------|-----------------|
| **Housekeeping Follow-up** | Housekeeping | Confirming every vacated room from the day has completed its cleaning cycle before tomorrow's arrivals |
| **Night Audit** | Night Audit | The nightly batch that turns today's operational reality (every still-`CheckedIn` Stay) into posted Folio charges — must run every business date without exception |
| **Outstanding Balance** | Folio, Payment | Reviewing any booking with a non-zero balance ahead of tomorrow's expected checkouts |
| **Room Status Verification** | Room Availability, Housekeeping | Confirming tomorrow's arrivals have rooms that will genuinely be ready |

---

## 11. Pilot Go-live Criteria

**When can Pilot begin:**
- Phase 4.3 and Phase 4.3B are complete, automated-test-verified, with zero new deterministic regressions against the established baseline.
- The Operational Readiness Review (§9) has been performed and signed off, with zero unresolved HIGH-severity findings.

**What must already work (no manual workaround acceptable):**
- The full core loop: Booking → Assignment → Check-in → Checkout, with correct Folio/Payment balances.
- Stay Extension, Partial Checkout, Room Move, Upgrade, Downgrade — all functioning in-system, confirmed live in a browser.
- Night Audit posting correctly for every active Stay, every business date.
- Housekeeping's core cycle following every checkout and every room transfer.
- The Folio Adjustment Engine, so any pricing mistake discovered during Pilot has an in-system correction path.

**Acceptable manual workarounds during Pilot:**
- Deposit Recalculation remains a staff-prompted, staff-executed action (this is the *designed* behavior, not a gap — the system will never auto-charge).
- Advanced Revenue Projection/Reconciliation views may still be data-sparse and rough around the edges early in Pilot — acceptable, since these are reporting-layer, not operational-layer.

**Not acceptable under any circumstance:**
- Any core operational event (Check-in, Checkout, Extend, Transfer, Upgrade, Downgrade, Partial Checkout) requiring a workaround outside the system.
- Any posted room charge that cannot be corrected in-system.
- Any permission/menu-visibility bug on a core module.

---

## 12. Production Freeze Definition

**Production Freeze means:**
- **Architecture frozen** — no further changes to the Booking/RoomAssignment/Stay/Folio/Payment/Revenue layer model or the Stay Event vocabulary.
- **Database schema frozen** — no further migrations to any core table.
- **Core workflow frozen** — no further changes to any Stay Event's behavior, Night Audit's posting logic, or the Adjustment Engine.
- **Only bug fixes allowed**, and only against defects found during Pilot — not new capability, not refactoring, not "small improvements."

**Production Freeze is explicitly NOT:**
- Browser testing (that is the Operational Readiness Review, §9 — it happens *before* Pilot, not as part of the freeze).
- Feature completion (all core features must already be complete and Pilot-proven *before* the freeze begins).
- Architecture redesign (if Pilot reveals a genuine architectural gap, that is a Pilot failure requiring the freeze to be lifted and re-planned — not something to be quietly redesigned "during" a freeze).

This redefinition corrects the earlier informal use of "Production Freeze" as a synonym for "the next thing we do after Phase 4.2" — it is a **later, stricter gate**, positioned after Pilot proves the system, not before.

---

## 13. Final Roadmap

```
Phase 4.3   — Stay Lifecycle Evolution        (Stay Event foundation, Extension, Partial Checkout,
                                                 Split Stay, Room Move, Upgrade, Downgrade)
        │
        ▼
Phase 4.3B  — Financial Core Completion       (Expected Total Projection, Booking Amendment Flow,
                                                 Deposit Recalculation, Adjustment Engine,
                                                 Correction Entries, Revenue Projection)
        │
        ▼
Operational Readiness Review                  (live browser walkthrough, exit gate, §9)
        │
        ▼
Pilot Go-live                                  (supervised, real operations, §11)
        │
        ▼
Production Freeze                              (architecture/schema/workflow frozen, §12)
        │
        ▼
Official Production                            (full cutover)
        │
        ▼
Phase 5.x — Expansion Modules                  (dashboards, forecasting, automation,
                                                 notifications, overstay conflict scanner)
```

---

## Analysis Scope Note

This document is **architecture and planning only**. No source file, migration, enum, or test was modified. Financial and rate-resolution claims are grounded in direct inspection of `RoomRate` (which already supports date-aware, room-type-keyed pricing via `overnight_price`/`valid_from`/`valid_to`) as it exists on `phase-3` at the time of writing.
