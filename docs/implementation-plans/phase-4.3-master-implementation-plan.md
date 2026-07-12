# Phase 4.3 Master Implementation Plan — Stay Lifecycle Evolution & Financial Core Completion

**Date:** 2026-07-06
**Branch:** phase-3
**Type:** Planning only — no code, no migration in this document
**Status:** DRAFT FOR CHATGPT REVIEW
**Companion document:** `docs/architecture/phase-4.3-core-architecture.md`

---

## Purpose

This plan sequences Phase 4.3 (Stay Lifecycle Evolution) and Phase 4.3B (Financial Core Completion) into concrete, individually-reviewable milestones, following the exact delivery discipline already proven in Phase 4.1 and Phase 4.2: one Architecture Review, one Implementation Plan, then milestone-by-milestone delivery, each with its own report and its own regression check, ending in an Integration & Final Verification pass before the Operational Readiness Review takes over.

This document does **not** specify code, method signatures, or migration DDL — that level of detail is produced at the start of each milestone, exactly as `phase-4.2-housekeeping-workflow-implementation-plan.md` did for Phase 4.2, once this master sequencing is approved.

---

## Milestone Breakdown

### Phase 4.3 — Stay Lifecycle Evolution

| # | Milestone | Scope | Maps to architecture doc §|
|---|-----------|-------|---|
| 4.3-M1 | **Stay Event Foundation** | The audited event-log concept (mirrors the existing `NightAuditBookingLog` pattern) that every subsequent milestone depends on. Records event type, actor, timestamp, Stay reference, and event-specific metadata for all 7 canonical events. | §4, §7.7 |
| 4.3-M2 | **Stay Extension** | `ExtendStay` event: moves `planned_checkout_at` forward on a `CheckedIn` Stay, event-logged, zero Folio interaction. | §7.1 |
| 4.3-M3 | **Partial Checkout (formalized)** | `PartialCheckout` event: makes the already-correct per-Stay checkout behavior explicit and auditable, distinct from final `Checkout`. | §7.2 |
| 4.3-M4 | **Per-Assignment Pre-Arrival Planning (Split Stay foundation)** | Independent date/room/rate editing per `RoomAssignment`, usable before check-in, with no Folio interaction. | §7.3 |
| 4.3-M5 | **Room Transfer Mechanics** | `TransferRoom` event: new `Transferred` status on `Stay`/`RoomAssignment`; new linked `RoomAssignment`/`Stay` pair; availability gate on the destination room; Housekeeping hook on the vacated room. | §7.4 |
| 4.3-M6 | **Upgrade / Downgrade** | Reuses 4.3-M5's transfer mechanism with rate resolution from `RoomRate` (date + room-type aware) instead of the frozen `BookingRequirement.room_price`. | §7.5, §7.6 |
| 4.3-M7 | **Phase 4.3 Integration & Verification** | Full end-to-end chains across every new event (mirroring `HousekeepingWorkflowIntegrationTest`'s pattern from Phase 4.2), full regression suite, Phase 4.3 closure report. | — |

### Phase 4.3B — Financial Core Completion

| # | Milestone | Scope | Maps to architecture doc § |
|---|-----------|-------|---|
| 4.3B-M1 | **Folio Adjustment Engine** | New `ADJUSTMENT` `ChargeType`, `reversal_of_entry_id` column, additive-only posting method — no change to ADR-50's void/immutability rules. | §8.4, §8.5 |
| 4.3B-M2 | **Expected Total Projection** | Read-only, per-Booking projection computed from current `RoomAssignment`/`Stay` rate × remaining nights + already-posted actuals. | §8.1 |
| 4.3B-M3 | **Booking Amendment Flow** | New explicit amendment workflow: pre-arrival edits stay direct and Folio-free; post-arrival edits are structurally routed to Stay Events instead of a `Booking`-level edit. | §8.2 |
| 4.3B-M4 | **Deposit Recalculation** | Staff-facing delta prompt driven by 4.3B-M2/M3, surfacing expected additional deposit or refund — never an automatic charge. | §8.3 |
| 4.3B-M5 | **Revenue Projection** | New `RevenueReportService` method for forward-looking expected revenue, kept structurally separate from the existing actuals-only methods. | §8.6 |
| 4.3B-M6 | **Phase 4.3B Integration & Verification** | Full regression suite, reconciliation-view sanity check (projected vs. actual), Phase 4.3B closure report. | — |

---

## Dependencies

```
4.3-M1 (Event Foundation)
   │
   ├──▶ 4.3-M2 (Extension)          ─┐
   ├──▶ 4.3-M3 (Partial Checkout)   ─┼─▶ 4.3-M7 (Integration)
   ├──▶ 4.3-M4 (Per-Assignment)     ─┤
   └──▶ 4.3-M5 (Transfer) ──▶ 4.3-M6 (Upgrade/Downgrade) ─┘

4.3-M7 complete
   │
   ▼
4.3B-M1 (Adjustment Engine) ──────────────────┐  (independent — can start immediately after 4.3-M7,
                                                │   does not depend on M2/M3/M4/M6)
4.3-M4 + 4.3-M6 complete                       │
   │                                            │
   ▼                                            │
4.3B-M2 (Expected Total Projection)             │
   │                                            │
   ▼                                            │
4.3B-M3 (Booking Amendment Flow) ◄──────────────┘  (needs Adjustment Engine available for
   │                                                 the case where an amendment requires
   ▼                                                 a correction, not just a projection)
4.3B-M4 (Deposit Recalculation)
   │
   ▼
4.3B-M5 (Revenue Projection)
   │
   ▼
4.3B-M6 (Phase 4.3B Integration)
```

**Key dependency notes:**
- **4.3-M1 blocks everything else in Phase 4.3** — no event can be safely built without the audit-log foundation it provides.
- **4.3-M2, M3, M4 are mutually independent** and could in principle be delivered in parallel by different reviewers, though this project's established single-threaded milestone discipline (Phase 4.1/4.2 precedent) recommends sequential delivery for review-quality reasons, not a technical constraint.
- **4.3-M6 strictly depends on 4.3-M5** — upgrade/downgrade is explicitly defined as a specialization of the transfer mechanism, not a separate implementation.
- **4.3B-M1 (Adjustment Engine) has no dependency on any other Phase 4.3B milestone** and could be pulled forward — it is the same lowest-risk, highest-value item the original Gap Analysis roadmap recommended doing first. It is sequenced after 4.3-M7 here only to respect the macro Phase-4.3-before-4.3B ordering given in this planning round, not because of a technical dependency.
- **4.3B-M2 depends on 4.3-M4 and 4.3-M6** — a meaningful projection needs per-assignment rate data, which only exists once pre-arrival planning (M4) and rate-aware transfers (M6) are in place.

---

## Estimated Implementation Order

Respecting the dependency graph above, and matching this planning round's macro sequencing (Phase 4.3 fully before Phase 4.3B):

1. 4.3-M1 — Stay Event Foundation
2. 4.3-M2 — Stay Extension
3. 4.3-M3 — Partial Checkout (formalized)
4. 4.3-M4 — Per-Assignment Pre-Arrival Planning
5. 4.3-M5 — Room Transfer Mechanics
6. 4.3-M6 — Upgrade / Downgrade
7. 4.3-M7 — Phase 4.3 Integration & Verification
8. 4.3B-M1 — Folio Adjustment Engine
9. 4.3B-M2 — Expected Total Projection
10. 4.3B-M3 — Booking Amendment Flow
11. 4.3B-M4 — Deposit Recalculation
12. 4.3B-M5 — Revenue Projection
13. 4.3B-M6 — Phase 4.3B Integration & Verification

**Rationale for M2 before M3 before M4 before M5/M6 within Phase 4.3:** lowest-risk, most-isolated changes first (Extension and Partial Checkout are both close to what the system already does correctly), building confidence and the event-log pattern before attempting the structurally larger Transfer mechanism (M5), with Upgrade/Downgrade (M6) — the item with the most interaction with pricing/rate resolution — deliberately last within Phase 4.3.

---

## Regression Strategy

Directly reusing the proven Phase 4.1/4.2 discipline:

- **Baseline discipline:** before 4.3-M1 begins, capture the exact current full-suite failure baseline (expected: the same 23 pre-existing date-drift failures documented since Phase 4.2, plus any newly-time-drifted count — re-verify, do not assume the old number still holds given the elapsed time since it was last measured).
- **Per-milestone check:** every milestone runs the full `php artisan test` suite **at least twice** before its report is written, exactly as every Phase 4.2 milestone did — a milestone is not complete until both runs decompose to the same baseline (or an explicitly documented, root-caused flake, per the standard already established for `PerStayAttributionTest` and `LateCheckoutFeeTest`).
- **Financial isolation checks:** every milestone's report must explicitly confirm, via `git status`/`git diff` scope review, that `FolioService`'s core invariants (ADR-50 immutability, Open/Closed/Voided lifecycle) were not weakened — this is the single most important regression class for this entire phase, since the whole point of 4.3B is to add correction capability *without* touching the existing immutability guarantee.
- **Cross-domain checks:** every milestone touching `Stay`/`RoomAssignment` must re-run the existing Housekeeping suite (124+ tests from Phase 4.2) to confirm the Room-status/Housekeeping-hook interactions (especially the vacated-room-on-transfer hook, new in 4.3-M5) don't regress the established Housekeeping workflow.
- **No milestone weakens an existing ADR.** Any milestone that appears to require weakening ADR-38/40/41/44/48/49/50/84–90 is a signal to stop and re-architect that milestone, not to proceed with the weakening.

---

## Testing Strategy

- **Unit tests** for every new service method (mirrors `HousekeepingServiceTest`'s pattern: happy path, precondition violations, permission/ownership edge cases).
- **Feature tests** for every new HTTP-level flow (mirrors `HousekeepingControllerTest`'s pattern: 403/422/200 coverage per endpoint).
- **Dedicated end-to-end integration tests per Stay Event chain** (mirrors `HousekeepingWorkflowIntegrationTest`'s pattern) — at minimum:
  - Full chain: Booking → Assignment → CheckIn → ExtendStay → Checkout, asserting correct nightly billing throughout.
  - Full chain: Booking → Assignment → CheckIn → TransferRoom → Checkout (new room), asserting the vacated room's Housekeeping hook fires, the destination room's availability gate is enforced, and billing continues correctly under the new rate.
  - Full chain: Upgrade and Downgrade variants of the above, asserting correct rate resolution from `RoomRate`.
  - Full chain: 3-room booking with divergent Partial Checkout timing, asserting `Booking.status` aggregates correctly without any child booking.
  - Full chain (4.3B): a Booking Amendment post-arrival, asserting it is correctly routed to a Stay Event rather than a direct `Booking`/`Folio` write.
  - Full chain (4.3B): an Adjustment Engine correction, asserting the original entry is untouched and the new entry correctly references it.
- **Concurrency test (carried over as an acknowledged gap from Phase 4.2, now in scope):** a genuine concurrent-request test for `TransferRoom` — two simultaneous transfer attempts for the same `Stay`, confirming the `SELECT FOR UPDATE` lock pattern (ADR-87 precedent) correctly serializes them.

---

## Rollout Strategy

- **Milestone-by-milestone delivery with individual review gates**, exactly matching Phase 4.2's M1 → M2 → M3 → M4 → M5 → Integration → M5.1 → Closure pattern. Each milestone produces its own report (`docs/reports/phase-4.3-backend-mN-report.md` / `phase-4.3b-backend-mN-report.md`) and stops for review before the next begins.
- **No feature flags.** Consistent with this codebase's established YAGNI convention — each milestone ships additive, backward-compatible capability that coexists safely with the current behavior until the next milestone builds on it, rather than being hidden behind a runtime toggle.
- **No mid-phase Pilot.** Per the Go-live Core Scope Review and this document's §11/§13, Pilot Go-live is gated on **both** Phase 4.3 and Phase 4.3B being complete and passing the Operational Readiness Review — not on any individual milestone.
- **Documentation parity with Phase 4.1/4.2:** every milestone updates the relevant architecture baseline document incrementally, so `docs/architecture/phase-4.3-core-architecture.md` remains the living reference throughout delivery, not a snapshot that drifts out of date.
- **Final gate before Operational Readiness Review:** both 4.3-M7 and 4.3B-M6 (the two Integration & Verification milestones) must be independently approved before the Operational Readiness Review process (architecture doc §9) begins.

---

## Explicitly Not In This Document

Per the instructions governing this planning round, this document does **not** contain:
- Exact method signatures, class names, or file paths for new code.
- Migration DDL for the `Transferred` status additions, the `reversal_of_entry_id` column, or the Stay Event log table.
- Any code, test, or configuration change.

These are produced at the start of each milestone (matching the level of detail in `phase-4.2-housekeeping-workflow-implementation-plan.md`), only after this master sequencing is approved.
