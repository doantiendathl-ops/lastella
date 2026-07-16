# LASTELLA Product Foundation v1.0 — Final Lock Report

## Status

- Document: LASTELLA Product Foundation v1.0
- Branch: phase-3
- Final status: OFFICIALLY APPROVED AND LOCKED
- Authority: Highest-level Product Baseline

## Purpose

- Product Constitution for LASTELLA PMS
- Mandatory reference for all future Sprint, Phase, prompt, implementation, and review work
- Resolves conflicts between lower-level prompts, reports, or implementations — this document wins unless formally revised through an explicit Product Review

## Locked Principles

**Product Scope**
- Small Hotel First
- Reception-Centric Operations
- Minimal Clicks
- Operational Flexibility
- Audit Over Workflow Rigidity
- Invisible Complexity

**Core Domain Definitions**
- Booking = Commercial Contract + Guest Journey Container
- Stay = Operational Reality
- No Child Booking for operational capabilities (Stay Extension, Partial Checkout, Room Move, Cross-Type Room Move)
- No Child Stay may be created merely to implement Stay Extension, Partial Checkout, or Change Room

**Change Room Capability**
- Change Room Principle (one capability, two variants: Room Move / Operational Cross-Type Move)
- Single Source of Truth
- Assignment Principle
- Physical / Commercial Separation
- Pricing Independence

**Financial Principles**
- Financial Separation Principle
- Commercial Source Principle
- Payment Projection is read-only
- Ledger history is additive (never rewritten)

**Operational Principles**
- Operational Exception Principle
- Product Capability Principle
- Operational Core Principle

**Audit Principles**
- Audit Over Workflow Rigidity
- System actor must always be recorded accurately; the real-world business actor may differ and this must not block the workflow
- Optional note/reason/attribution where operationally valuable; never mandatory dual-actor entry for ordinary Reception-centred operations
- `StayEvent` append-only audit mechanism
- `ROOM_MOVE` shared event type for same-type and cross-type Change Room moves

**Governance**
- Mandatory AI/Developer checklist
- Change Control

## Approved Product Baseline

| Milestone | Commit | Tag |
|---|---|---|
| Phase 4.3A — Stay Operations Foundation | `a91f23949b70953ea8694c0b09c8199429d227fd` | `phase-4.3a` |
| Product Sprint 01 — Payment Projection M1 | `6e4c6248e3b9823df554d5d2aae1bf7ac7d70654` | `product-sprint-01-payment-projection-m1` |
| Product Sprint 02 — Change Room, Phase 1 (Room Move) | `765f0dbc49f9ac3b0280f96bede39ab60a97a059` | `product-sprint-02-room-move-m1` |
| Product Sprint 03 — Change Room, Phase 2 (Cross-Type Move) | `38cee8be64c0181baa658c2905b14d160172814c` | `product-sprint-03-change-room-phase-2` |

Commit hashes verified against `git log`/`git rev-parse` prior to this closure — all four match the Product Foundation document exactly.

## Known Accepted Limitations

- 23 pre-existing date-sensitive test failures (11 `BookingManagementUiTest` + 12 `RoomAvailabilityCheckerTest`) — Technical Debt
- `PerStayAttributionTest` intermittent Faker unique-value collision — Technical Debt
- `LateCheckoutFeeTest` wall-clock/time-of-day dependency — Technical Debt
- No live browser automation verification performed to date — Accepted Scope Limitation
- No Room Occupancy History (only current `RoomAssignment` + `StayEvent` audit trail exist) — Future Product Enhancement
- Future recurring non-room charges (breakfast, extra bed, city tax) not forecast by Payment Projection — Future Product Enhancement
- `expected_balance` has no dedicated wording for a negative (guest-in-credit) value — Future Product Enhancement
- `VacantDirty` room status not yet rejected by the shared availability rule — **Operational Product Gap**

None of the above are blockers for the current baseline.

## Change Control

The Product Foundation may only be changed when:

- A new Product Decision has been made.
- A clear Architecture Review has taken place.
- There is a real operational reason.
- The impact has been recorded.

Must not be changed for: developer preference, refactor convenience, framework trends, or enterprise best practices that do not fit a 10–100 room hotel.

## Git Information

- Commit hash: self-referential (this report is committed together with the locked document in the same commit) — see the chat Final Response for the exact hash, or `git log -1 -- docs/architecture/lastella-product-foundation-v1.md`
- Commit message: `docs(product): lock Lastella Product Foundation v1.0`
- Branch pushed: `phase-3`
- Tag name: `lastella-product-foundation-v1.0`
- Tag pushed: yes
- Remote confirmation: see chat Final Response

## Closure Confirmation

- Product Foundation v1.0 APPROVED
- Product Foundation v1.0 LOCKED
- No code changed
- No tests changed
- No migration
- No Sprint started
- Working tree clean for this task (only the two authorized files committed; pre-existing unrelated untracked report files left untouched, as instructed)
