# Lastella Operational Design Principles

**Date:** 2026-07-07
**Branch:** phase-3
**Type:** Design principles only — no code, no migration, no implementation
**Status:** DRAFT FOR CHATGPT REVIEW
**Gates:** Phase 4.3A Backend Milestone 1 (Stay Event Foundation) — implementation does not begin until this document is approved

---

## 1. Executive Summary

Lastella PMS has, so far, been built with correct architecture but no explicit product philosophy — every design decision (Housekeeping's inspection gate, the Stay Event model, permission granularity) was made on technical merit alone, without a stated view of *what kind of hotel* the system is for. This document supplies that missing philosophy: **Lastella is built first for small and medium hotels (roughly 10–100 rooms) where Reception is the operational command center and the person doing the physical work is frequently not the person who touches the system.** Every future feature — and, where it conflicts, some already-shipped behavior — should be measured against these ten principles before it is measured against anything else.

---

## 2. Why This Document Exists

Phase 4.2 (Housekeeping) and the Phase 4.3 series were designed correctly against their own stated requirements, but those requirements never asked the more fundamental question: *is this how a real 10–100 room hotel actually works day to day?* The user's clarification — that Housekeeping staff are often too busy to use the PMS directly, and instead report status by walkie-talkie or in person for Reception to record — exposed a gap no prior document addressed: **the system implicitly assumed the person doing the work is the person clicking the button.** That assumption is wrong for the hotel segment Lastella targets, and it must be corrected as a standing principle before any further Stay/Booking/Payment/Housekeeping work is planned, not patched in as an afterthought once discovered again in a later phase.

---

## 3. Target Hotel Profile

Lastella is designed, by default, for a hotel with:

- Approximately **10–100 rooms**.
- A **small staff roster** where the same few people often cover multiple functions across a shift.
- **Reception as the de facto command center** — not because other departments don't matter, but because Reception is where information from every department converges in practice (a guest calls the front desk, not the housekeeping office; a housekeeper radios the front desk, not a PMS terminal).
- **Limited or no dedicated back-office/IT/quality-assurance staff** — workflows that assume a supervisor is always available to approve, inspect, or countersign every action do not match daily reality for this segment.
- **A need to train new staff quickly** — a workflow that takes a paragraph to explain is a workflow that will actually be used; one that takes a training session will be worked around.

This is a deliberate, explicit choice: **Lastella is not designed by default for a 300-room chain property with dedicated departmental terminals, a full housekeeping supervisor hierarchy, and a quality-assurance team.** That segment's needs are not excluded forever — they are deferred to optional future extensions (§9), never baked into the default workflow.

---

## 4. Core Design Principles

### 1. Small Hotel First

Every default workflow is judged first against a 10–100 room hotel's daily reality. Large-chain PMS complexity (multi-level approval chains, mandatory departmental logins, rigid role silos) is never the starting point — it is, at most, an optional layer added later for the hotels that actually need it.

### 2. Operational Flexibility Principle

The person performing the real-world task and the person operating the system are not assumed to be the same person. **If a user holds the correct permission, they may record an action on behalf of another department.** Reception marking a room clean because Housekeeping just radioed it in is not a workaround or an edge case — it is a fully supported, expected way of using the system.

### 3. Business Process ≠ System Actor

The system must always clearly distinguish **who performed the real-world action** from **who recorded it in the system**. These may differ, and the system does not need to force them to match. Where useful, the system should also capture *how* the system actor learned about the event (radio, phone, in person, guest request) as an optional note — never as a second mandatory login or a second mandatory confirmation step.

### 4. Audit Over Workflow Rigidity

Prefer a clear, simple audit record — actor, timestamp, action, optional note — over a chain of mandatory approvals. Do not require supervisor sign-off, mandatory assignment, or a mandatory department-specific login unless the business has explicitly said it needs that specific control. An audit trail that is trusted and complete is more valuable to a small hotel than a workflow that is procedurally strict but slow.

### 5. Minimal Clicks

Every action a staff member performs many times a day must be fast. Housekeeping's core cycle is three steps — Chờ dọn (waiting) → Đang dọn (in progress) → Dọn xong (done) — not more. Stay extension and partial checkout must each be a single, obvious action, not a multi-screen wizard.

### 6. Reception-Centric Operations

Reception is not a special case to be worked around — it is the expected coordination point for check-in, check-out, payment, room assignment, housekeeping updates, and guest requests in a small hotel. The system should be designed *around* this reality, not designed around a departmental-silo assumption and then patched to "also allow" Reception to do everything.

### 7. Expandable, Not Mandatory

Advanced workflows — dedicated housekeeping assignment, supervisor inspection, room-quality approval, engineering/maintenance workflows, advanced revenue reconciliation, automated notifications — are optional extensions a hotel can grow into. **None of them are required for Go-live**, and none of them should ever be structured such that a small hotel is forced through steps it doesn't need just because a larger hotel might want them.

### 8. Operation-Based UX

Screens should be organized around what a staff member needs to do *right now*, in their current shift, not around the system's underlying data entities. A morning-shift screen should answer "who is arriving, are their rooms ready, do I need a deposit" — it should not require the user to think in terms of `Booking`, `RoomAssignment`, and `Stay` to get that answer.

### 9. Simple Housekeeping Default

The default Housekeeping workflow is exactly three steps: **Chờ dọn → Đang dọn → Dọn xong.** Maintenance locks, supervisor inspection, and quality control are **not** the default workflow — they belong to a future, optional Maintenance/Room Operations module a hotel can enable if it needs that level of process.

**Flagged tension with already-shipped work:** Phase 4.2's Housekeeping implementation, as it exists today (tagged `phase-4.2`), does **not** match this principle — it implements a **mandatory** inspection gate (`CLEANING → INSPECTED`, requiring a `room.inspect`-permission holder to Pass/Fail/Skip before the room returns to `VACANT_CLEAN`). This was architecturally correct against Phase 4.2's own requirements at the time, but it is the more complex, supervisor-inspection-first design this principle now says should be the *optional* path, not the *default* one. This is not corrected in this document (no implementation is authorized here) — it is recorded as an open item for a future Housekeeping refinement pass, so the mismatch is not silently repeated or forgotten. See §9 (Future Extension Boundary).

### 10. Source and Notes

Optional free-text or tagged context — "Reported by Housekeeping," "Requested by Guest," "Approved by Manager," "Updated by Reception" — should be available wherever it helps clarify an audit record. It should never be made mandatory unless a specific legal or operational requirement demands it.

---

## 5. Housekeeping Design Direction

- The three-step cycle (Chờ dọn → Đang dọn → Dọn xong) is the product's *intended* default, per Principle 9.
- Any staff member with the right permission — most commonly Reception — may advance a room's status on Housekeeping's behalf, per Principle 2. The system does not need to know or verify that the physical cleaning was reported by radio, phone, or in person; it only needs to record who touched the system and when, with an optional note if useful (Principle 3, Principle 10).
- Supervisor inspection, when a hotel wants it, is an **optional escalation**, not a mandatory gate every room must pass through by default.
- This direction does **not** change any Phase 4.2 code today. It is recorded here so that the next time Housekeeping is revisited (whether as part of a dedicated refinement phase or as a side effect of Phase 4.3B's room-transfer Housekeeping hook), the simple-default-vs-optional-inspection question is answered by this document rather than re-litigated from scratch.

---

## 6. Stay / Booking / Payment Design Direction

- **Stay Extension** and **Partial Checkout** (Phase 4.3A) are each a single, obvious Reception action — not a multi-step confirmation flow — consistent with Principle 5 (Minimal Clicks) and Principle 6 (Reception-Centric Operations).
- **Booking** remains the Guest Journey Container, but staff should never need to think about that framing to do their job — Reception experiences "extend the stay" or "check this room out," not "apply a Stay Event to the Guest Journey Container."
- **Payment** recording should stay a fast, single action for Reception; multi-step approval chains for a deposit or a routine payment are not the default (Principle 4, Principle 7) unless a hotel explicitly needs stricter financial controls in the future.
- **Folio correctness** (the Phase 4.3C Adjustment Engine, correction entries) exists precisely so that when something is recorded imperfectly in the fast, low-friction path this philosophy calls for, there is always a clear, auditable way to correct it — Audit Over Workflow Rigidity (Principle 4) is what makes minimal-friction operation *safe*, not reckless.

---

## 7. Operational Review Model

Operational Review (first introduced for Phase 4.3A in `phase-4.3a-stay-foundation-implementation-plan.md` §14) is redefined here to be **shift-based first, role-based second** — matching how a small hotel actually staffs its day, rather than reviewing the system department-by-department in the abstract.

### Morning Shift

- Arrivals — confirming today's expected check-ins.
- Room Assignment — confirming or adjusting which room each arrival gets.
- Check-in — the guest's actual arrival.
- Deposits — collecting/recording the agreed deposit.

### Afternoon Shift

- Stay Extension — a guest decides to stay longer.
- Partial Checkout — one room in a multi-room booking finishes while others continue.
- Checkout — a room's final departure.
- Room Status Updates — Reception recording Housekeeping's radioed-in progress, per the Operational Flexibility Principle.

### Night Shift

- Night Audit — the nightly posting run.
- Outstanding Balance — reviewing any booking with money still owed.
- Housekeeping Follow-up — confirming every room vacated today has completed its cleaning cycle.
- Room Status Verification — confirming tomorrow's arrivals have rooms that will genuinely be ready.

Every future Operational Review (Phase 4.3B, 4.3C, and beyond) should be structured this way: **by shift, then by the roles present in that shift** — not as a flat checklist of system modules disconnected from when and by whom they are actually used.

---

## 8. Impact on Phase 4.3A

- **Stay Extension** stays a single, simple action for Reception — no additional approval step is introduced by this document.
- **Partial Checkout** stays a single, simple action for Reception — same as above.
- **Reception can perform both actions directly**, consistent with Principle 6 — no requirement that a different role or department must be the one to trigger them.
- **`StayEvent` rows (from the Phase 4.3A plan) already record `actor_id` and a `metadata` JSON field** — this document confirms that design was the right call and should be used, where useful, to carry an optional source/note (e.g., `{"note": "Reported by Housekeeping via radio"}`), without ever making that note a required field.
- **The Operational Review for Phase 4.3A (`phase-4.3a-stay-foundation-implementation-plan.md` §14) should be re-read through the shift-based lens from §7 of this document** — its Reception workflow already maps naturally onto the Morning/Afternoon/Night structure and needs no rewrite, only that framing acknowledged when it is actually performed.

No change to the Phase 4.3A implementation plan's scope, milestones, or files-expected-to-change is introduced by this document — it confirms the plan is already aligned with these principles and clarifies how its Operational Review should be run.

---

## 9. Future Extension Boundary

The following remain explicitly optional, future extensions — never required for Go-live, never the default workflow:

- Dedicated Housekeeping assignment/dispatch complexity beyond the simple three-step cycle.
- Supervisor inspection / room-quality approval as a **mandatory** gate (see the flagged tension in §4, Principle 9 — Phase 4.2's current mandatory inspection step is a candidate for becoming optional/configurable in a future Housekeeping refinement, not decided or implemented here).
- Engineering / maintenance workflow as its own module, separate from simple Out-of-Order marking.
- Advanced revenue reconciliation dashboards (already deferred to Phase 5.x per the Go-live Core Scope Review).
- Automated notifications, automation generally (already deferred to Phase 5.x).
- Mandatory department-specific logins or approval chains for any action this document's principles cover.

---

## 10. Summary

Lastella is built for the hotel that runs on a small, versatile staff and a Reception desk that hears about most things before anyone types them into a computer. The system's job is to make recording what already happened fast and trustworthy — not to insist that whoever did the work must also be the one who clicks the button, and not to bury a three-step cleaning cycle or a one-click stay extension under process designed for a much larger property. Every principle in this document exists to keep that true as Lastella grows, including — where it already hasn't — in work already shipped.
