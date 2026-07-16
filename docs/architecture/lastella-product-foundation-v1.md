# LASTELLA Product Foundation v1.0

## 1. Purpose and Status

This is the official product baseline for Lastella PMS.

- **Version:** v1.0
- **Status:** LOCKED
- Applies to every future implementation — Sprint, Phase, or ad-hoc fix.
- May only be changed through an explicit Product Review that records a new decision and its rationale — never edited for convenience.

Read this document **before** planning or implementing any new feature. If a proposed feature conflicts with a principle here, the principle wins unless this document is formally revised first.

---

## 2. Product Scope

- **Small Hotel First** — every default workflow is judged against a real 10–100 room hotel's daily reality, not enterprise-chain complexity.
- **Target size:** roughly 10–100 rooms. Not designed by default for enterprise hotel chains, multi-property groups, or departmental-silo operations.
- **Reception-Centric Operations** — Reception is the operational command center where information from every department converges; the system is designed around this, not patched to "also allow" it.
- **Minimal Clicks** — any action performed many times a day (check-in, extend, checkout, room status) must be one obvious step, never a multi-screen wizard.
- **Operational Flexibility** — the person doing the real-world task and the person operating the system are not assumed to be the same person; recording on behalf of another department is fully supported, not a workaround.
- **Audit Over Workflow Rigidity** — a trusted, complete audit trail (actor, timestamp, action) is preferred over mandatory approval chains, unless the business explicitly needs stricter control.
- **Invisible Complexity** — the underlying domain model (Booking, Stay, RoomAssignment, StayEvent) exists to make the system correct; staff should never need to think in those terms to do their job.

---

## 3. Core Domain Definitions

### Booking

**Booking = Commercial Contract + Guest Journey Container.** It holds the commercial agreement (requirements, rates, dates) and groups everything that happens during a guest's journey.

### Stay

**Stay = Operational Reality.** It represents what is actually happening physically — which room, checked in or not, extended, partially or fully checked out — independent of how it was originally sold.

### Relationship

One Booking may contain many independent Stays (Split Stay). **No Child Booking is ever created.** A new Booking must never be created as a side effect of:

- Stay Extension
- Partial Checkout
- Room Move
- Cross-Type Room Move

These are all operational events against the existing Booking/Stay, never a new commercial contract.

---

## 4. Stay Operations

Current capabilities: **Check-in, Stay Extension, Partial Checkout, Final Checkout, Split Stay, Change Room.**

- A Stay keeps its identity throughout its entire lifecycle — extension, room change, partial checkout never replace it with a new row.
- **No Child Stay may be created merely to implement Stay Extension, Partial Checkout, or Change Room.**
- Every operational event is recorded in the append-only `StayEvent` audit log.

---

## 5. Change Room Capability

### Change Room Principle

There is exactly **one** capability: Change Room.

- Same Room Type → Room Move
- Different Room Type → Operational Cross-Type Move

These are two variants of one capability, never two separate business flows.

**Must Not:** create `UpgradeService`, `DowngradeService`, `RoomTypeChangeService`, `CrossTypeRoomMoveService`, `UpgradeRoom()`, `DowngradeRoom()`, or any parallel controller/action for a room change.

### Single Source of Truth

Only one flow may change the room of a Stay (currently `StayService::moveRoom()`, or its future equivalent if renamed). Locking, availability, conflict detection, `RoomAssignment` update, `Stay` update, Housekeeping hooks, and audit logging exist in exactly one place.

**Must Not:** duplicate, fork, or reimplement any part of this flow elsewhere.

### Assignment Principle

**RoomAssignment = Current Operational Assignment.** Not Occupancy History, not Audit History, not a Room Timeline, not a Child Assignment.

**Must Not:** create a second `RoomAssignment` row to represent a room change — it double-counts against the Booking's per-type assignment requirement. History belongs in the `StayEvent` audit log.

### Physical / Commercial Separation

- `Stay.room_id` / `RoomAssignment.room_id` = physical room currently occupied.
- `RoomAssignment.room_type_id` = commercial requirement slot the assignment fulfills.
- `BookingRequirement` = the sold room type and rate.
- Physical and commercial room type **may diverge** after a Change Room move — this is expected, not an error.

**Must Not:** write `RoomAssignment.room_type_id` or `BookingRequirement` as a side effect of a Change Room move.

### Pricing Independence

Room Change never decides a price. Any surcharge or discount is entered separately by Reception via existing Gói dịch vụ / Extra Charge / Discount / a future Adjustment flow.

**Must Not:** calculate an automatic upgrade fee, downgrade refund, or proration inside the Change Room flow.

---

## 6. Financial Principles

### Financial Separation Principle

**Projection ≠ Folio ≠ Payment ≠ Adjustment.** Each is a distinct, independently computed concept. None may silently stand in for another.

### Commercial Source Principle

Every financial rate calculation (Night Audit, Payment Projection, any future forecast/revenue calculation) must read from the **Commercial Agreement** (`BookingRequirement`, via the commercial requirement slot the assignment fulfills), never from the live Physical Assignment.

**Must Not:** resolve a rate by looking at the room a guest is currently physically occupying.

### Payment Projection

Projection is **read-only** — a forecast, not a ledger.

**Must Not:** create a `FolioEntry`, modify a `Payment`, modify a Night Audit posting, or create an `Adjustment`.

### Ledger History

Historical postings are never rewritten. Every correction is additive (a new entry), never an edit or deletion of a past one.

---

## 7. Payment Projection Semantics

Current fields:

- `projected_room_total`
- `posted_non_room_total`
- `expected_total`
- `expected_deposit`
- `recognized_paid_total`
- `expected_balance`

Rules:

- This is **not a final invoice**.
- Future recurring non-room charges (breakfast, extra bed, city tax, etc.) not yet posted are **not forecast** — only already-posted non-room amounts are counted.
- `expected_balance = expected_total − recognized_paid_total`.
- Projection's rate source is the same Commercial Source used by Night Audit (§6), so it never diverges from what will actually be posted.

---

## 8. Audit Principles

- Audit Over Workflow Rigidity: a simple, trusted audit record beats a rigid approval chain.
- The **system actor** who performs the action in LASTELLA must always be recorded accurately.
- The **real-world business actor** may differ from the system actor; this is valid and must not block the workflow (e.g., Reception recording a room status Housekeeping radioed in).
- Where operationally valuable, a note, reason, or optional attribution may record who reported or performed the real-world task.
- Recording a separate business actor must not be mandatory for ordinary Reception-centred operations.
- `StayEvent` is the append-only audit mechanism for all Stay-lifecycle events.
- `ROOM_MOVE` is one shared event type for both same-type and cross-type Change Room moves.

**Must Not:** create a new event type solely to reflect a pricing decision (e.g., `UPGRADE`, `DOWNGRADE`) — the room change itself is one event; any pricing action is a separate, existing financial flow with its own record.

---

## 9. Operational Exception Principle

Not every case needs automation. Rare or judgment-based decisions — complimentary stays, VIP treatment, goodwill gestures, compensation, surcharges, discounts — are decided by a **human**, not inferred by the system.

The system's role is to **support, record, audit, and project** — never to decide on the operator's behalf.

---

## 10. Product Capability Principle

A Product Capability may be delivered across multiple Sprints, but it must never be split into multiple business flows.

**Example:** Product Sprint 02 (Change Room, Phase 1 — same-type) and Product Sprint 03 (Change Room, Phase 2 — cross-type) are two delivery phases of exactly **one** Change Room capability, not two capabilities.

---

## 11. Operational Core Principle

Core operations currently defined: **Assign Room, Change Room, Extend Stay, Partial Checkout, Checkout, Cancel.**

Every future feature must:

- Extend an existing core operation where the underlying need is the same, or
- Be justified as a genuinely new core operation — not created as a parallel flow that duplicates an existing one's purpose.

**Must Not:** build a second flow that accomplishes what an existing core operation already does, just under a different name or entry point.

---

## 12. Current Approved Product Baseline

All items below are implemented, tested, reviewed, committed, pushed, tagged, and closed.

| Milestone | Commit | Tag |
|---|---|---|
| Phase 4.3A — Stay Operations Foundation | `a91f23949b70953ea8694c0b09c8199429d227fd` | `phase-4.3a` |
| Product Sprint 01 — Payment Projection M1 | `6e4c6248e3b9823df554d5d2aae1bf7ac7d70654` | `product-sprint-01-payment-projection-m1` |
| Product Sprint 02 — Change Room, Phase 1 (Room Move) | `765f0dbc49f9ac3b0280f96bede39ab60a97a059` | `product-sprint-02-room-move-m1` |
| Product Sprint 03 — Change Room, Phase 2 (Cross-Type Move) | `38cee8be64c0181baa658c2905b14d160172814c` | `product-sprint-03-change-room-phase-2` |

---

## 13. Known Accepted Limitations

| Limitation | Category |
|---|---|
| 23 pre-existing date-sensitive test failures (11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`) | Technical Debt |
| `PerStayAttributionTest` intermittent Faker unique-value collision | Technical Debt |
| `LateCheckoutFeeTest` wall-clock/time-of-day dependency | Technical Debt |
| No live browser automation verification performed to date | Accepted Scope Limitation |
| No Room Occupancy History (only current `RoomAssignment` + `StayEvent` audit trail exist) | Future Product Enhancement |
| Future recurring non-room charges (breakfast, extra bed, city tax) not forecast by Payment Projection | Future Product Enhancement |
| `expected_balance` has no dedicated wording for a negative (guest-in-credit) value | Future Product Enhancement |
| `VacantDirty` room status not yet rejected by the shared availability rule | Operational Product Gap |

None of the above are blockers for the current baseline.

---

## 14. Future Work Categories

Recorded as categories only — no implementation plan exists for any of these.

### Operational Improvement

- Room Readiness / Availability Rules Review (includes the `VacantDirty` decision)
- Housekeeping Simplification
- Overstay Warning

### Financial Improvement

- Adjustment Engine
- Revenue Improvements
- Reconciliation

### Management

- Operational Reports
- Dashboard
- Analytics

---

## 15. Mandatory Rules for Future AI/Developers

Before implementing any new feature:

- [ ] Read this document.
- [ ] Identify the real product problem — not just the requested implementation.
- [ ] Identify which existing core operation (§11) is being extended.
- [ ] Identify the single source of truth for the change.
- [ ] Identify which files must **not** be modified.
- [ ] Check Financial Separation (§6) is not violated.
- [ ] Check for Child Booking / Child Stay risk (§3, §4).
- [ ] Check for duplicate-business-flow risk (§10, §11).
- [ ] Write targeted tests.
- [ ] Run full regression.
- [ ] Produce an implementation report.
- [ ] Do not commit before explicit Product/ChatGPT approval.

---

## 16. Change Control

This document may only be changed when:

- A new Product Decision has been made.
- A clear Architecture Review has taken place.
- There is a real operational reason.
- The impact has been recorded.

**Must Not** be changed for: developer preference, refactor convenience, framework trends, or enterprise best practices that do not fit a 10–100 room hotel.
