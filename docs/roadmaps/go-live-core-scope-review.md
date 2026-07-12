# Go-live Core Scope & Roadmap Review

**Date:** 2026-07-06
**Branch:** phase-3
**Type:** Architecture / Roadmap Review only — no code changed, no migration created
**Status:** DRAFT FOR CHATGPT REVIEW
**Builds on:** `docs/reports/revenue-folio-gap-analysis.md`, `docs/architecture/stay-lifecycle-architecture.md` (approved with strategic changes below)

---

## 1. Executive Summary

Lastella PMS, after Phase 4.2, has a solid **transactional core** (Booking, Room Assignment, Check-in/Check-out, Folio, Payment, Housekeeping) with automated test coverage across every layer, and a correct — if not yet fully editable — financial posting model (Night Audit already bills per-`Stay`, per-night, independent of `Booking` dates). It also has a set of **operational/reporting modules** (Night Audit UI, Reconciliation, Revenue) that are architecturally sound and permission-correct but have not been confirmed working end-to-end in a browser, and one just-shipped module (Housekeeping) that is fully wired and automated-test-covered but has real, known UX gaps that will matter the moment real staff use it daily.

**The system is close to Pilot Go-live readiness for its core daily-operations loop, but not yet there.** This document defines the minimum core scope needed to run a **partial, supervised pilot** safely, separates that from everything that can wait, and re-confirms the four-phase roadmap (4.2.5 → 4.3 → 4.4 → 5.x) with concrete scope per phase.

---

## 2. Current System Status After Phase 4.2

| Module | Built | Automated test coverage | Browser-confirmed this session |
|--------|:---:|:---:|:---:|
| Booking (create/edit/cancel) | ✅ Phase 2–3 | ✅ Extensive | ❌ Not verified this session |
| Room Management | ✅ Phase 2 | ✅ | ❌ |
| Room Assignment | ✅ Phase 3 | ✅ | ❌ |
| Check-in / Check-out | ✅ Phase 3 | ✅ Extensive | ❌ |
| Stay | ✅ Phase 3 | ✅ | ❌ |
| Folio | ✅ Phase 3.1–3.2 | ✅ | ❌ |
| Payment | ✅ Phase 3.1 | ✅ | ❌ |
| Night Audit | ✅ Phase 3.2–3.3 | ✅ (posting logic) | ❌ (UI/workflow not confirmed) |
| Housekeeping | ✅ Phase 4.2 (M1–M5.1) | ✅ Extensive (124+ tests) | ❌ (built this session, never clicked through in a real browser) |
| Special Requests | ✅ Phase 4.1 | ✅ | ❌ |
| Room Availability | ✅ Phase 3, extended Phase 4.2 | ✅ | ❌ |
| Revenue Reports | ✅ Phase 3.3 | ✅ (aggregation logic) | ❌ |
| Reconciliation | ✅ Phase 3.3 | ✅ | ❌ |
| Dashboard | ✅ (basic metrics only) | Minimal | ❌ |
| Navigation / Permissions | ✅, matrix verified per role | ✅ Extensive | ❌ |

**Critical honesty note:** every module in this table has strong automated (HTTP-level, database-verified) test coverage — this project has never skipped that discipline. What is genuinely unverified across the **entire system**, not just Housekeeping, is real browser rendering, click-through workflow feel, and console cleanliness, because no browser-automation tool has been available in any session to date. This is the single largest unknown blocking Pilot Go-live and is addressed head-on in Phase 4.2.5 (§11).

---

## 3. Go-live Strategy

Two-stage go-live, not one:

1. **Pilot Go-live** — a small, supervised subset of real operations (e.g., a handful of rooms, one shift, staff working alongside the existing manual process as a fallback) to surface real-world friction before committing fully. Entry criteria in §14.
2. **Production Go-live** — full cutover, manual process retired. Entry criteria in §15.

This staged approach directly serves the business goal: "start operating the system partially and safely as soon as possible" without waiting for Phase 4.3/4.4's deeper architectural work.

---

## 4. Proposed Layered Architecture Summary

Per the architectural direction approved for this roadmap (refining the previously-approved Stay Lifecycle Architecture):

- **Booking = Commercial Contract + Guest Journey Container.** Booking is still the commercial agreement (Layer 1), but it is now explicitly also the **aggregate container for the guest's entire visit** — every room, every stay, every transfer, every payment across the whole journey belongs to one `Booking`. This directly satisfies the requirement that a 3-room booking with divergent checkout dates, or a mid-stay room move, must **never** require creating a second/child booking (§ user concern 5–6). All future Stay Events (CheckIn, ExtendStay, PartialCheckout, TransferRoom, UpgradeRoom, DowngradeRoom, Checkout, Adjustment) happen *within* one Booking's journey.
- **Stay = Operational Reality.** Unchanged from the SLA — the sole source of truth for what actually happened, event-sourced, and the sole input to Night Audit.
- **RoomAssignment = Planning + Physical Allocation** (revised from the SLA's "planning-only" stance). RoomAssignment is not demoted to pre-arrival-only history — it continues to represent the actual physical room-to-guest binding for as long as that binding is current, including after check-in. A `TransferRoom` event ends the old `RoomAssignment`'s physical-allocation role and creates a new one, while the guest's `Stay`/journey continues under the same `Booking`. This is a more faithful model of hotel operations than treating `RoomAssignment` as disposable planning scaffolding.
- **Folio = Financial Ledger**, fed exclusively by Stay Events (CheckIn, Night Audit, PartialCheckout, Checkout, Adjustment) or explicit manual/adjustment postings — **never** directly by a Booking edit. This rule is unchanged and remains the single most important boundary in the system.

```
Booking (Commercial Contract + Guest Journey Container)
  │
  ▼
Stay / Stay Events: CheckIn → ExtendStay → PartialCheckout → TransferRoom → UpgradeRoom → DowngradeRoom → Checkout → Adjustment
  │
  ▼
Night Audit / Posting
  │
  ▼
Folio
  │
  ▼
Payment / Revenue
```

One-directional flow, exactly as specified: nothing downstream ever writes back upstream (Folio never edits Stay; Payment never edits Folio entries directly; Revenue never edits anything).

---

## 5. Current Usable Capabilities

Confirmed usable **today**, by code inspection and automated test evidence, for the core daily loop:

- **Booking creation** with requirements, pricing, customer data.
- **Room Assignment** (single and multi-room), with conflict detection.
- **Check-in**, including early-check-in fee posting and automatic `OCCUPIED` room status.
- **Check-out**, including outstanding-balance enforcement, late-checkout fee posting, automatic `VACANT_DIRTY` + housekeeping assignment creation, and folio auto-close.
- **Basic Payment recording** — deposits, additional deposits, room/service payments, refunds, adjustments — with correct balance math, including split/multi-installment payments (confirmed as a **non-gap** in the prior Gap Analysis).
- **Folio integrity** for the straightforward path — charges accumulate correctly, closed folios reject new charges, void protects system entries.
- **Special Requests** — full lifecycle (create/acknowledge/fulfill/cancel), including the HOUSEKEEPING-restricted standalone view.
- **Room Availability board** — correctly reflects OUT_OF_ORDER, CLEANING, RESERVED, OCCUPIED/overstay states.
- **Housekeeping backend workflow** — assign (including the M5.1 auto-claim fix), start, complete, pass/fail/skip inspection, out-of-order/release — all verified via 124+ automated tests spanning policy, controller, and full end-to-end chains.
- **Permission matrix** — verified correct for ADMIN/MANAGER/HOUSEKEEPING/RECEPTION/ACCOUNTANT/SALES across every module touched in Phase 4.x.

---

## 6. Current Non-Usable / Partially Usable Capabilities

Addressing the specific concern directly: **Housekeeping, Night Audit, Reconciliation, Revenue are visible in the menu but not confirmed actually usable.**

| Module | Assessment | Why |
|--------|-----------|-----|
| **Housekeeping** | **Partially usable.** Backend + Vue board are fully wired and pass every automated check, but the board has real, already-documented UX gaps: no housekeeper picker (the Assign dialog only supports self-assign or a raw numeric user-ID field — see M5 report), no floor grouping, no cleaning-reason/notes display (the `index()` contract deliberately excludes these fields). A real housekeeper clicking through this today would likely find it usable for the happy path but clunky for anything else. |
| **Night Audit** | **Assessment inconclusive — needs browser confirmation, not a rewrite.** Permission gating is correct (`night_audit.view`/`night_audit.run`, held by ADMIN/MANAGER). The posting logic itself is well-tested. What's unverified: whether the trigger/run/retry workflow is smooth for a real user, and whether the manual-trigger-only design (no scheduled fallback — confirmed in the Gap Analysis) creates confusion in daily use. |
| **Reconciliation** | **Assessment inconclusive, likely fine but data-sparse.** Correctly gated (`reconciliation.view`). This module surfaces outstanding balances and voided entries — in a fresh pilot environment with little transaction history, it will look nearly empty, which can read as "broken" even though it isn't. |
| **Revenue** | **Same as Reconciliation** — correctly gated (`revenue.view`), architecturally a pure read-only aggregation, but will look sparse/uninteresting with little pilot data and is explicitly a reporting module the business goal says not to prioritize. |

**No permission-configuration bug was found** for any of these four modules — nav visibility and controller/policy gates use matching permission strings in every case checked. The "visible but not usable" perception is most likely a combination of (a) genuinely unverified browser behavior across the whole system, (b) real UX incompleteness in Housekeeping specifically, and (c) data-sparsity making reporting modules look non-functional in an early pilot.

---

## 7. Core Operational Gaps

Carried forward from the Gap Analysis, restated against the go-live lens:

1. **Booking date edits never update Folio/expected amount** (user concern #2). Confirmed root cause: `BookingService::updateBooking()` never calls `FolioService`. **Not a bug** — it's a missing capability (no "expected total" projection exists anywhere). For Pilot: a **process-level mitigation** is required (staff manually re-confirm/re-collect deposit whenever dates change) until Phase 4.4 ships the projection.
2. **Per-room independent planned-date editing does not exist** (user concerns #3–4). Important nuance: the *actual* billing side already handles this correctly today — Night Audit bills each `Stay` independently based on real check-in/checkout, regardless of `planned_checkout_at`. **A 3-room booking where one room genuinely stays an extra night will be billed correctly today**, even though the system's on-screen "planned checkout" can't be updated for that one room without affecting the other two. This is a display/planning gap, not a billing-correctness gap, for Pilot purposes.
3. **No room move / upgrade / downgrade capability at any layer.** Confirmed unsupported; `releaseAssignment()` hard-blocks once checked in. If this is operationally common, it is a real Pilot blocker (see §14) unless staff are willing to work around it manually outside the system for the pilot's duration.
4. **Posted room charges cannot be corrected** (ADR-50 immutability, no adjustment mechanism yet). A real risk for Pilot: any pricing mistake becomes permanent in the ledger with no in-system fix until Phase 4.4.

---

## 8. Go-live Core Scope

The minimum set of capabilities that must work correctly, confirmed in a real browser, for a safe Pilot:

- Booking creation and straightforward modification (customer info, requirements, dates when no active stay exists)
- Room assignment (single and multi-room)
- Check-in
- Check-out (including the existing balance/late-fee logic)
- Housekeeping's core loop: checkout → room dirty → assign/claim → start → complete → inspect → clean (the parts already built and tested in Phase 4.2/M5.1)
- Folio and Payment correctness for the straightforward, non-edited-mid-stay path
- Night Audit basic correctness: nightly room charges post correctly for active stays when triggered

**Explicitly in scope but with a known, accepted limitation for Pilot:** stay extension and multi-room divergent checkout — **already billed correctly today**, just without on-screen planning support (§7.2). Staff should be told to check guests out based on real departure, not to fight the date-edit UI.

---

## 9. Excluded From Go-live Core

Per the business goal, explicitly deferred:

- Advanced dashboards / KPI reports
- Advanced revenue analytics, forecasting
- Reconciliation dashboards (the existing Reconciliation *screen* stays available, but no enhancement work happens for Pilot)
- Automation, notifications
- **Overstay conflict scanner** — explicitly a Phase 5.x-only feature, not included in Go-live Core under any circumstance unless a specific safety incident during Pilot proves otherwise
- Room move / upgrade / downgrade / split-stay UI (Phase 4.3 — see §12)
- Adjustment entries / expected-total projection (Phase 4.4 — see §13)

---

## 10. Recommended Roadmap

```
Phase 4.2.5 — Production Freeze        (confirm & stabilize what already exists)
        │
        ▼
Phase 4.3 — Stay Lifecycle Evolution   (per-room planning independence, room move/upgrade/downgrade, Stay Events)
        │
        ▼
Phase 4.4 — Financial Evolution        (adjustment engine, expected-total projection, revenue reconciliation)
        │
        ▼
Phase 5.x — Expansion Modules          (dashboards, forecasting, automation, notifications, overstay scanner)
```

---

## 11. Phase 4.2.5 Recommended Scope

**Focus: confirm, don't build.**

- Real-browser click-through of every core module in §8, plus Housekeeping, Night Audit, Reconciliation, Revenue (to convert §6's "inconclusive" assessments into confirmed facts).
- Fix any menu-visibility, permission, or genuinely-broken-screen issues found during that pass — **bug fixes only**, no new capability.
- Confirm Booking → Assignment → Check-in → Check-out → Folio → Payment → Housekeeping runs correctly for the basic scenarios end to end, in a browser, by a human.
- **No new architecture. No new big features.** If a gap is found that requires new capability (not a bug fix), it is logged and routed to Phase 4.3/4.4 — not fixed in place.

---

## 12. Phase 4.3 Recommended Scope

**Focus: Stay Lifecycle Evolution.**

- Stay Extension (formalize as a Stay Event, per §4's event vocabulary)
- Partial Checkout (already correct at the billing level; formalize as an explicit Stay Event for auditability)
- Split Stay / per-room independent planned checkout (closes §7.2's planning-side gap)
- TransferRoom (Room Move) — modeled per the revised RoomAssignment role (§4): ends the old physical allocation, creates a new one, **within the same Booking** (no child booking, per user concern #5)
- UpgradeRoom / DowngradeRoom — built on TransferRoom, with rate resolution per new `RoomAssignment`
- Stay Event foundation — the explicit event vocabulary (CheckIn, ExtendStay, PartialCheckout, TransferRoom, UpgradeRoom, DowngradeRoom, Checkout, Adjustment) becomes a first-class, audited concept rather than implicit status changes

---

## 13. Phase 4.4 Recommended Scope

**Focus: Financial Evolution.**

- Adjustment Engine (additive, `reversal_of_entry_id`-style corrections — closes §7.4's uncorrectable-charge gap)
- Expected Total Projection (closes §7.1's "Booking edit doesn't update expected amount" gap)
- Folio correction workflow built on the Adjustment Engine
- Revenue Projection & Revenue Reconciliation (compares projected vs. actual — closes the Gap Analysis's "no reconciliation" risk)
- Night Audit operational hardening (workflow polish informed by what Phase 4.2.5's browser pass finds; scheduled-trigger question from the SLA's Open Questions resolved here if pursued)

---

## 14. Pilot Go-live Entry Criteria

All of the following must be true before Pilot begins:

- [ ] Phase 4.2.5 complete: every module in §8 confirmed working in a real browser by a human tester
- [ ] No unresolved 403/permission-mismatch bugs on any core screen
- [ ] Staff briefed on the two accepted Pilot-time limitations: (a) booking date edits require manual deposit reconciliation, (b) room move/upgrade/downgrade is not supported in-system — must be handled manually outside the PMS if it occurs
- [ ] At least one full manual walkthrough of Booking → Assignment → Check-in → (optional extension) → Check-out → Housekeeping → Folio/Payment, performed by hotel staff (not just automated tests)
- [ ] Night Audit run successfully at least once against real pilot data, with correct room charges confirmed by a human against the actual stay

## 15. Production Go-live Entry Criteria

All Pilot criteria, plus:

- [ ] Phase 4.3 complete: per-room planning independence and room move/upgrade/downgrade available in-system (removing the two Pilot-time manual workarounds)
- [ ] Phase 4.4's Adjustment Engine available (removing the "uncorrectable charge" risk for full production volume)
- [ ] No open Pilot-discovered defect classified as HIGH severity
- [ ] Reconciliation and Revenue screens confirmed meaningful against a real transaction history accumulated during Pilot

---

## 16. Deferred Features

- Dashboards / advanced reports / KPI views
- Forecasting
- Overstay conflict scanner (Phase 5.x only, per explicit instruction)
- Automation, notifications
- Analytics, optimization
- Any UI enhancement to Reconciliation/Revenue beyond confirming they work

---

## 17. Risks

| Risk | Severity | Mitigation |
|------|----------|-----------|
| No module in this system has ever been confirmed in a real browser | **HIGH** | Phase 4.2.5's entire purpose; must precede any Pilot commitment |
| Room move/upgrade/downgrade occurs during Pilot with no in-system support | **MEDIUM–HIGH** | Explicit staff briefing + manual workaround process; accelerate Phase 4.3 if this proves frequent |
| A room-charge pricing mistake occurs during Pilot with no correction mechanism | **MEDIUM** | Manual off-system credit/discount process until Phase 4.4's Adjustment Engine ships; document every occurrence for Phase 4.4 prioritization |
| Housekeeping's UX gaps (no housekeeper picker, no floor grouping) frustrate real staff | **MEDIUM** | Acceptable for a small supervised Pilot; prioritize a lightweight fix if Pilot feedback confirms it's a blocker |
| Booking date-edit / expected-amount mismatch causes a real under-collection at checkout | **MEDIUM** | Existing outstanding-balance check at checkout already prevents checkout with a negative balance — the *risk* is under-quoting a deposit mid-stay, not losing money at final checkout |
| Reconciliation/Revenue misread as "broken" due to data sparsity, eroding staff trust in the system generally | **LOW–MEDIUM** | Brief staff in advance that these screens will look empty early in Pilot by design |

---

## 18. Recommendations

1. **Approve Phase 4.2.5 exactly as scoped in §11** — a confirmation-and-bugfix pass, not new development. This is the fastest path to a defensible Pilot Go-live decision.
2. **Do not attempt Phase 4.3/4.4 work before Phase 4.2.5 completes.** Building more architecture on an unconfirmed foundation compounds risk rather than reducing it.
3. **Run Pilot with the two named manual workarounds** (deposit reconciliation on date edit; off-system handling of room moves) rather than delaying Pilot until Phase 4.3/4.4 ship — this matches the business goal of operating "partially and safely as soon as possible."
4. **Treat every Pilot-discovered gap as roadmap input, not an ad hoc fix.** Route findings to Phase 4.3 (operational/lifecycle gaps) or Phase 4.4 (financial gaps) per this document's scope split, preserving the disciplined milestone-by-milestone delivery pattern already established in Phase 4.1/4.2.
5. **Keep the overstay conflict scanner out of scope through Phase 4.4**, exactly as instructed — revisit only in Phase 5.x, and only if Pilot/Production experience demonstrates real need.

---

## Analysis Scope Note

This document is a **planning and roadmap review only**. No source file, migration, test, or configuration was modified. Module usability assessments are grounded in direct code/permission inspection performed in this session plus accumulated knowledge from Phase 4.1/4.2 delivery and the prior Gap Analysis / Stay Lifecycle Architecture documents; browser-level claims are explicitly marked as unconfirmed rather than asserted, since no browser-automation tool has been available in any session to date.
