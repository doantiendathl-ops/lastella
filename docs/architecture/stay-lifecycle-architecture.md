# Stay Lifecycle Architecture (SLA)

**Date:** 2026-07-06
**Branch:** phase-3
**Type:** Architecture design only — no code, no migration, no implementation plan
**Status:** DRAFT FOR CHATGPT ARCHITECTURE REVIEW
**Builds on:** `docs/reports/revenue-folio-gap-analysis.md` (approved)

---

## 1. Executive Summary

This document defines the long-term architectural foundation for everything touching Booking, Stay, Room Assignment, Folio, Payment, Night Audit, and Revenue in Lastella PMS. It formalizes a principle the Gap Analysis found **already true in practice but never made explicit**: `Booking` is a commercial contract, `Stay` is operational ground truth, and `Folio` is a financial ledger fed exclusively by operational events — never directly by the contract. Every future capability (stay extension, room move, upgrade/downgrade, partial checkout, split stay, revenue reconciliation) is designed as a natural consequence of strengthening the boundaries between these three layers, not as a one-off feature bolted onto any single layer.

This document does not change any code. It is the reference every future Phase 4.3.x Architecture Review, Implementation Plan, and Milestone report should cite when justifying which layer owns a change.

---

## 2. Core Principles

### Layer 1 — Commercial Contract (`Booking`)

`Booking` represents the **agreement**, not what is physically happening in the building: a reservation, a customer commitment, a sales/OTA record. It owns *intent* — requested dates, requested room types, quoted price, customer identity, cancellation terms. **`Booking` must never be read by anything that generates a financial posting.** This is already true in the current system for room charges (Night Audit never reads `Booking.checkin_at`/`checkout_at`) and this SLA makes it a permanent, explicit rule rather than an accident of the current code path.

### Layer 2 — Operational Reality (`Stay`)

`Stay` represents **what actually happened**: actual occupancy, actual room usage, actual timing of every event in a guest's physical visit to a specific room. `Stay` is the **operational source of truth** — the only entity permitted to answer "is this room occupied right now," "how many nights has this guest actually been here," and "what should Night Audit bill for tonight." Where `Booking` can be edited freely (it's just an agreement), `Stay` state changes are **event-sourced** — each change is a discrete, named, audited event (Check-In, Extension, Transfer, Checkout), not a free-form field edit.

### Layer 3 — Financial Ledger (`Folio`)

`Folio` records **financial events only**, and is generated **exclusively from Layer 2 (operational) events** — never directly from Layer 1. A `Booking` edit, by itself, must never create, alter, or remove a `FolioEntry`. Only something that happened operationally (a night passed with the guest still checked in; a guest checked out late; a guest's stay was corrected after the fact) may produce a ledger entry. This is the single most important boundary in this document, because it is the one boundary the current codebase already respects for room charges and violates nowhere — the goal is to keep it that way as new capabilities are added, not to weaken it for convenience.

```
┌─────────────────────┐        ┌──────────────────────┐        ┌─────────────────────┐
│   Layer 1            │        │   Layer 2              │        │   Layer 3             │
│   Booking            │───────▶│   Stay                 │───────▶│   Folio               │
│   (commercial intent)│  plans │   (operational truth)  │  drives│   (financial ledger)  │
└─────────────────────┘        └──────────────────────┘        └─────────────────────┘
        ▲                                 │                                │
        │                                 ▼                                ▼
   Sales / OTA                      RoomAssignment                   Night Audit
   Reception edits                  Housekeeping                    Payment / Revenue
```

---

## 3. Domain Responsibilities

| Domain | Responsibility | Must never do |
|--------|----------------|----------------|
| **Booking** | Hold commercial intent: requested dates, room-type requirements, quoted price, customer/sales data, cancellation lifecycle | Generate a `FolioEntry`; be read by Night Audit; be the operative source for "how many nights to bill" |
| **RoomAssignment** | Bind one physical room to one booking for a planned date range and rate, **while still pre-arrival** | Continue to be the thing Night Audit reads after check-in; hold post-arrival ground truth |
| **Stay** | Hold operational ground truth from check-in onward: actual timestamps, status, extensions, transfers, checkout | Be edited retroactively without an event record; be silently overwritten by a `Booking` edit |
| **Folio** | Accumulate financial entries, track balance, own Open/Closed/Voided lifecycle | Be written to by anything other than a posting job, a manual charge, or an adjustment — never by a `Booking` edit directly |
| **Payment** | Record money actually received/refunded against a `Booking`'s aggregate balance | Be tied to an individual `Stay` — a guest settles one bill for the whole booking, not per room |
| **Night Audit** | Translate "which Stays were operationally active on business date X" into posted `FolioEntry` rows, once per date, idempotently | Make judgment calls about guest exceptions (late checkout, early check-in) — those are event-triggered at check-in/checkout, not date-driven |
| **Revenue** | Read-only aggregation over posted `FolioEntry` rows | Ever be a primary source of anything; ever be written to |
| **Housekeeping** | Own `Room.status` (cleanliness/maintenance) and cleaning assignments, triggered by `Stay` checkout/transfer events | Own any part of `Stay` or `Folio` data; gate financial postings |
| **Availability** | Answer "can this room be assigned/is this room bookable" from `RoomAssignment` (planning conflicts) and `Room.status` (operational blocks: CLEANING/OUT_OF_ORDER) | Be a single-sourced concept — planning availability and operational availability are legitimately different questions and should stay dual-sourced |

---

## 4. Source of Truth

| Business object | Owner (source of truth) | Notes |
|-------------------|--------------------------|-------|
| Reservation dates | `Booking` | Commercial intent only; editable any time pre-arrival with no financial side effect |
| Planned stay (per room, pre-arrival) | `RoomAssignment` | Independently editable per room — closes the current "booking-wide cascade" gap |
| Actual stay | `Stay` | Ground truth from check-in onward; immutable history, event-sourced changes only |
| Room occupancy | `Stay.status` + actual timestamps | The only true answer to "is someone in this room right now" |
| Room movement (transfer) | `Stay` (via a transfer link to a new `Stay`/`RoomAssignment` pair) | A transfer is a *recorded event*, not a silent field update |
| Room upgrade / downgrade | `RoomAssignment`/`Stay` rate snapshot | Each assignment/stay carries its own resolved rate, not a shared booking-level price |
| Room charges | `Folio` (`FolioEntry`), generated by Night Audit / check-in / check-out postings | Never generated directly by `Booking` |
| Night Audit | Night Audit itself, reading `Stay` | Sole authority for translating operational state into ledger entries |
| Revenue | Derived, read-only, over `Folio` | Never a source; always a report |
| Payment | `BookingPayment`, tied to `Booking` | Aggregate-level by design — one guest bill, potentially many rooms |
| Housekeeping | `Room.status` + `HousekeepingAssignment`/`CleaningRecord` | Triggered by `Stay` checkout/transfer; owns nothing upstream |
| Availability | `RoomAssignment` (planning) + `Room.status` (operational) | Intentionally dual-sourced — see Domain Responsibilities |

---

## 5. Business Event Timeline

Each event below states **who owns it**, **what it does**, and **what state changes result**. Events marked **(new)** do not exist in the current codebase and are proposed by this SLA.

| # | Event | Owner | Effect | Resulting state change |
|---|-------|-------|--------|--------------------------|
| 1 | **Booking Created** | Booking | Sales/reception records intent; a `Folio` container is opened (empty) | `Booking.status = Draft/PendingAssignment`; `Folio.status = Open`, zero entries |
| 2 | **Booking Modified** | Booking | Dates/customer/requirement edits, pre-arrival only | `Booking` fields change; **no** `Folio` effect, ever |
| 3 | **Room Assigned** | RoomAssignment | A specific room + date range + resolved rate bound to the booking | `RoomAssignment.status = Assigned` |
| 4 | **Room Assignment Modified (new)** | RoomAssignment | Pre-arrival date/room/rate edit, independent per assignment | `RoomAssignment` fields change; still no `Folio` effect — this is still Layer 1→pre-Layer-2 planning |
| 5 | **Check-In** | Stay | Guest physically arrives; operational truth begins | `Stay.status = CheckedIn`, `actual_checkin_at` set; `RoomAssignment.status = CheckedIn`; `Room.status = OCCUPIED`; **first night's `ROOM` FolioEntry posts**; `EARLY_CHECKIN` entry posts if applicable |
| 6 | **Stay Extended** | Stay | Guest decides to stay longer, still in the same room | No status change (`Stay` stays `CheckedIn`); `planned_checkout_at` moves forward, recorded as an event, not a silent overwrite; **no immediate `Folio` effect** — future nights bill exactly as before, night by night, via Night Audit |
| 7 | **Room Changed / Transferred (new)** | Stay | Guest moves to a different physical room mid-visit | Old `Stay` → `Transferred` (new terminal-for-that-room state, distinct from `CheckedOut`); new `RoomAssignment` + new `Stay` created, linked via `transferred_from_stay_id`/`transferred_to_stay_id`; **no Folio effect from the transfer itself** — billing simply continues under the new `Stay`'s own rate |
| 8 | **Room Upgraded / Downgraded (new)** | Stay/RoomAssignment | A transfer where the new room's rate differs from the old | Same mechanics as event 7; the new `Stay`'s rate snapshot reflects the new room type/rate going forward; **prior nights already posted under the old room are never touched** |
| 9 | **Partial Checkout** | Stay (per room), Booking (aggregate) | One room's guest leaves while others in the same booking remain | That room's `Stay.status = CheckedOut`; `Booking.status` recalculates to `PartiallyCheckedOut` (already exists) — no new mechanism needed, this is Scenario 8 from the Gap Analysis, already correctly per-`Stay` |
| 10 | **Final Checkout** | Stay, Booking | Last active `Stay` on the booking ends | `Stay.status = CheckedOut`; `LATE_CHECKOUT` entry posts if applicable; balance verified = 0; `Folio` auto-closes; `Booking.status = CheckedOut` |
| 11 | **Housekeeping** | Room | Vacated room re-enters the cleaning cycle | `Room.status` cycles `VACANT_DIRTY → CLEANING → INSPECTED → VACANT_CLEAN`; **zero interaction with `Folio` at any point** (already true today) |
| 12 | **Night Audit** | Night Audit | Once per business date, for every `Stay` still `CheckedIn` | Posts one night's recurring charges (`ROOM`, and other recurring types) per active `Stay` |
| 13 | **Folio Posting** | Folio | The mechanical act of writing a `FolioEntry`, from any of the above triggers | New row in `folio_entries`, `posting_source` reflects the trigger |
| 14 | **Payment** | Payment | Guest settles some/all of the balance, at any time, in any number of installments | New `BookingPayment` row; balance recalculated |
| 15 | **Invoice** | Folio (read) | A point-in-time, read-only rendering of the closed `Folio`'s entries | No state change — a report, not an event |

---

## 6. Stay State Diagram

**Stay-level states** (existing states unchanged; `Transferred` is new):

```
                    ┌───────────┐
                    │  Reserved │  (RoomAssignment created, guest not yet arrived)
                    └─────┬─────┘
              ┌───────────┼───────────────┐
              ▼           ▼               ▼
        ┌───────────┐ ┌─────────┐   ┌──────────┐
        │ Cancelled │ │ No Show │   │ CheckedIn │◄────────────┐
        └───────────┘ └─────────┘   └─────┬────┘             │
                                           │                  │ Stay Extended
                                           │                  │ (no state change,
                              ┌────────────┼────────────┐     │  event only)
                              ▼            ▼            ▼     │
                       ┌────────────┐┌───────────┐ ┌──────────┴─┐
                       │ CheckedOut ││Transferred││  (loops back to
                       │  (normal)  ││   (new)    │  CheckedIn via
                       └────────────┘└─────┬──────┘  Extension event)
                                            │
                                            ▼
                                   new linked Stay,
                                   status = CheckedIn,
                                   new Room/RoomAssignment
```

**Cross-domain interactions** (not Stay states — shown separately because `Room.status` and `Booking.status` are independent state machines that *react to* Stay events):

```
Stay event            →   Room.status reaction              →   Booking.status reaction (aggregate)
────────────────────────────────────────────────────────────────────────────────────────────────
Check-In               →   VACANT_CLEAN/INSPECTED → OCCUPIED →  PendingAssignment/... → CheckedIn
Checkout (this room)   →   OCCUPIED → VACANT_DIRTY             →  → PartiallyCheckedOut or CheckedOut
Transfer-out (this room)→  OCCUPIED → VACANT_DIRTY             →  (booking stays CheckedIn — guest hasn't left)
Transfer-in (new room)  →  VACANT_CLEAN/INSPECTED → OCCUPIED   →  (no change — same booking, same guest)
Housekeeping cycle      →  VACANT_DIRTY → CLEANING → INSPECTED → VACANT_CLEAN   (no Stay/Booking interaction)
```

**Out Of Order interaction (existing gap, flagged not fixed — see §12 Open Questions):** today, `HousekeepingService::markOutOfOrder()` only refuses if `Room.status === CLEANING`; it does **not** check for an active `CheckedIn` Stay. This SLA recommends that a future ADR require `markOutOfOrder()` to also refuse (or force a mandatory transfer) when a `Stay` is currently `CheckedIn` in that room — a guest must never be operationally "in" a room the system simultaneously considers out of order.

---

## 7. Booking vs Stay vs Folio

| Question | Answered by | Why |
|----------|--------------|-----|
| "What did the guest agree to?" | `Booking` | Commercial record, editable at will pre-arrival |
| "Is the guest actually here right now?" | `Stay` | Ground truth; only `Stay.status`/`actual_*_at` can answer this |
| "How many nights should we bill?" | `Stay` (via Night Audit) | Never `Booking` dates — already true today, formalized here |
| "What was actually charged?" | `Folio` | Immutable ledger; the only place money is recorded |
| "What do we expect to charge in total?" | A **projection** computed from `RoomAssignment`/`Stay` rate × planned nights (new, read-only, never posts) | Distinct from "what was charged" — closes the Gap Analysis's Scenario 1/3 UX gap without blurring Folio's role as actuals-only |

**The rule that must never be broken:** editing `Booking` can change what is *planned*; it can never, by itself, change what is *billed*. Only a `Stay` event (or an explicit, human-triggered adjustment) may touch `Folio`.

---

## 8. Room Assignment Responsibilities

**`RoomAssignment`'s future role is planning-only, strictly pre-arrival.** It is neither purely operational nor financial once a `Stay` exists for it:

- **Before check-in:** `RoomAssignment` is the sole editable planning unit — dates, room, and rate can all change independently per assignment (closing the Gap Analysis's Scenario 2). It has no financial effect at this stage; nothing has been billed yet.
- **At check-in:** `RoomAssignment` hands off operational authority to the newly-created `Stay`. From this moment, `RoomAssignment.status` mirrors `Stay.status` for display/reporting convenience (as it already does today: `Assigned → CheckedIn → CheckedOut`), but `Stay` — not `RoomAssignment` — is what Night Audit and all financial logic reads.
- **After check-in:** `RoomAssignment` becomes **read-mostly history** — a record of which room/rate applied for which period, useful for reporting and for the transfer mechanism (a transfer creates a *new* `RoomAssignment`, it never mutates the old one after check-in).

**Conclusion: `RoomAssignment` is planning-only in its future role — never the operational or financial source of truth once a `Stay` begins.** This is a narrowing of scope relative to today's implicit "it's just whatever holds room+dates," made explicit so future code never mistakenly reads `RoomAssignment` for financial decisions post-arrival.

---

## 9. Folio Relationship

### Events that generate Folio entries

- Check-In → first night `ROOM` entry, `EARLY_CHECKIN` entry if applicable
- Night Audit (per business date, per active `Stay`) → recurring charges: `ROOM` and other per-night charge types
- Final Checkout → `LATE_CHECKOUT` entry if applicable
- Manual staff-entered charges (services, minibar, damage, etc.) — unchanged from today
- **Adjustment (new)** → a correction, always additive, always referencing the entry it corrects

### Events that must never generate Folio entries

- Booking Created (only opens an empty `Folio` container)
- Booking Modified (any field, any time)
- Room Assigned / Room Assignment Modified (pre-arrival planning)
- Room Transfer itself (the transfer is a `Stay` event with no direct charge; billing continues via the new `Stay`'s own Night-Audit-driven postings)
- Housekeeping events (assign, start, complete, inspect, out-of-order, release)
- Availability checks
- Cancellation of a booking with an empty folio (existing `voidFolioOnCancellation` behavior — void, not post)

### Events that should generate adjustment entries

- Stay shortened after some nights were already posted (Gap Analysis Scenario 4)
- Room downgrade discovered/decided after charges already posted at the prior (higher) rate
- Any correction of a wrongly-priced or wrongly-dated system entry discovered after the fact
- Reversal required due to an operational error (e.g., a night posted for a `Stay` that should have already been transferred out)

**Guiding rule:** adjustments are always **new, additive, referencing** entries (`reversal_of_entry_id` or equivalent) — the original posted entry is never edited or deleted. This preserves ADR-50's immutability guarantee while finally giving the business a way to correct the customer-facing balance.

---

## 10. Night Audit

**Responsibilities:** the sole mechanism that translates "which `Stay` rows were operationally active (`CheckedIn`) on business date X" into posted `FolioEntry` rows, for every recurring charge type, exactly once per `Stay` per date (idempotent via `posting_key`), with a full per-stay audit log.

**Limitations (by design, not by oversight):**
- Cannot correct historical postings — it only ever posts forward, for the current business date.
- Cannot re-price past nights when a rate changes today — each night is priced at posting time, using whatever rate was resolved for that `Stay`/`RoomAssignment` at that moment.
- Does not make guest-facing exceptions — late-checkout and early-check-in fees are **event-triggered** at checkout/check-in respectively, not decided by Night Audit's date-driven pass.
- Requires an explicit trigger (manual today; a scheduled trigger is a legitimate future addition but is an operational/infrastructure concern, not a change to Night Audit's architectural responsibility).

**Inputs:**
- The current business date
- Every `Stay` with `status = CheckedIn`
- Each such `Stay`'s resolved rate (its own snapshot going forward, once the SLA's rate-aware `Stay`/`RoomAssignment` change lands — see Gap Analysis §10)
- The set of already-posted entries for idempotency

**Outputs:**
- New `FolioEntry` rows (`posting_source = NIGHT_AUDIT`)
- `NightAuditBookingLog` rows recording the outcome (posted/skipped/already-posted/failed) for every `Stay` × job combination, for audit purposes

---

## 11. Future Capability Matrix

| Capability | How this SLA supports it |
|------------|----------------------------|
| **Stay Extension** | `planned_checkout_at` changes as a recorded event on the `Stay` itself (event 6); no `RoomAssignment`/`Booking`-wide cascade needed; Night Audit keeps billing correctly with zero special-casing, exactly as it already does today |
| **Partial Checkout** | Already fully supported — each room is its own `Stay`, checked out independently; `Booking.status` aggregates via the existing `PartiallyCheckedOut` status |
| **Split Stay** | A "split" is just independent `RoomAssignment`/`Stay` date ranges per room, enabled once `RoomAssignment` becomes independently editable pre-arrival (§8) |
| **Room Move** | Modeled explicitly as event 7 (Transfer): old `Stay` → `Transferred`, new linked `Stay` in the new room — a first-class, auditable event instead of an unsupported workaround |
| **Upgrade** | Same mechanism as Room Move, with the new `Stay`/`RoomAssignment` carrying a higher rate snapshot going forward |
| **Downgrade** | Same mechanism, lower rate snapshot going forward; any refund/credit for the difference is an **adjustment entry** (§9), never an edit to already-posted nights |
| **Multiple checkout dates (same booking)** | Already fully supported at the ground-truth level (per-`Stay` independence); this SLA additionally makes the *planned* side independent too, via per-`RoomAssignment` pre-arrival editing |
| **Future revenue reconciliation** | The projected-total view (§7) gives Revenue reporting something to reconcile *against* — actual `Folio` postings vs. expected `RoomAssignment`/`Stay`-rate-based projection — closing the Gap Analysis's "no reconciliation" risk |

---

## 12. Recommended Future Roadmap

Logical order only — no implementation detail, no timeline commitment. Mirrors the Gap Analysis's proposed roadmap, reframed as flowing from this SLA:

1. **Phase 4.3.1 — Adjustment Entries.** Lowest risk, unblocks the single sharpest gap (uncorrectable postings) immediately. No dependency on any other phase.
2. **Phase 4.3.2 — Per-Assignment Pre-Arrival Planning.** Makes `RoomAssignment` independently editable per room, formalizing its Layer-1-adjacent planning-only role (§8).
3. **Phase 4.3.3 — Rate-Aware Stay.** Gives each `Stay`/`RoomAssignment` its own resolved rate snapshot, prerequisite for correct upgrade/downgrade pricing.
4. **Phase 4.3.4 — Stay Transfer (Room Move).** Introduces the `Transferred` state and the linked-`Stay` mechanism; scheduled after 4.3.3 because upgrade/downgrade transfers need rate-awareness already in place; scheduled last among the structural changes because it has the highest interaction risk with checkout finalization.
5. **Phase 4.3.5 — Expected-Total Projection & Revenue Reconciliation.** Read-only, additive, depends on 4.3.2/4.3.3 existing so the projection has real per-assignment rate data to compute from.

Each phase should follow the project's established pattern: Architecture Review → Implementation Plan → Milestone-by-milestone delivery → Integration & Final Verification → Closure — exactly as Phase 4.1 and Phase 4.2 did.

---

## 13. Open Questions

These are explicitly **not decided** by this document and require an explicit answer (likely a dedicated ADR each) before the corresponding roadmap phase begins implementation:

1. **Does a Transfer always create a new `RoomAssignment`, or can a `Stay` change rooms while keeping the same `RoomAssignment`?** This document recommends *always a new `RoomAssignment`* (since a `RoomAssignment` already means "one room, one range, one rate") but this is a real design decision, not a foregone conclusion.
2. **Should the old `RoomAssignment`/`Stay` in a transfer get a distinct `Transferred` status, or reuse `CheckedOut`?** This document recommends a distinct status so reporting can tell "guest left the hotel" apart from "guest moved to another room" — but this touches an existing enum and needs explicit sign-off.
3. **Should `markOutOfOrder()` be blocked (or force a mandatory transfer) when a `Stay` is currently `CheckedIn` in that room?** Flagged as a latent gap in the current Housekeeping/Room interaction (§6); not fixed by Phase 4.2, not decided here.
4. **Should the expected-total projection (§7, §11) be a mandatory UI element for every booking, or an opt-in reporting view?** Product/UX decision, out of scope for architecture.
5. **Should Night Audit gain a scheduled/cron trigger, or remain a manual, staff-initiated action?** Operational/infrastructure decision; the Gap Analysis flagged the current manual-only trigger as a risk, but this SLA does not mandate a specific resolution.
6. **Does Payment ever need to be tracked per-`Stay` rather than per-`Booking`** (e.g., for corporate/group bookings where different rooms are billed to different payers)? Not required by any of the 8 Gap Analysis scenarios, but worth surfacing before this architecture is considered final for all future phases.

---

## Analysis Scope Note

This document is **architecture design only**. No source file, migration, enum, or test was modified in the course of producing it. It builds directly on the verified facts in `docs/reports/revenue-folio-gap-analysis.md` and the current enum/state definitions (`BookingStatus`, `StayStatus`, `AssignmentStatus`, `RoomStatus`) as they exist on `phase-3` at the `phase-4.2` tag.
