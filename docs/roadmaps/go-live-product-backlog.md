# Go-live Product Backlog

**Date:** 2026-07-10
**Branch:** phase-3
**Type:** Product backlog — no architecture, no code changed
**Grounded in:** `docs/roadmaps/go-live-core-scope-review.md`, `docs/implementation-plans/phase-4.3a-stay-foundation-implementation-plan.md`

Principle: prioritize "what blocks hotel operation?" over "what is the perfect architecture?"

---

## Backlog

| P | Feature / Issue | Business Impact | Proposed Phase | Complexity | Recommended Next Action |
|---|---|---|---|---|---|
| P0-1 | Stay Extension | Guest stays longer than planned; today billed correctly but no on-screen date update, forcing manual workaround | Phase 4.3A M2 (already planned) | Medium | Proceed after M1 (Stay Event Foundation) lands |
| P0-2 | Partial Checkout | Multi-room booking, one room leaves early — already works, not yet audited as a distinct event | Phase 4.3A M3 (already planned) | Low | Proceed after M1 |
| P0-3 | Expected Payment / Expected Total update on booking/stay change | Deposit under-collection risk when dates change mid-stay (no projection exists today) | Phase 4.4 (Expected Total Projection) | High | Defer; pilot uses manual deposit reconciliation as interim mitigation |
| P0-4 | Payment / Folio consistency for real stays | Ledger trust; posted charges currently cannot be corrected if wrong | Phase 4.4 (Adjustment Engine) | Medium–High | Defer engine work; browser-verify existing correctness during Phase 4.2.5-style pass |
| P1-5 | Simplify Housekeeping to Chờ dọn → Đang dọn → Dọn xong | Real staff will find current picker/flow clunky day-to-day | Phase 4.2.5 UX fix or Phase 4.3B | Medium | Scope as a UI/status-mapping fix, not a backend rewrite |
| P1-6 | Night Audit practical usability | Financial correctness trust for nightly posting | Confirm first (4.2.5), polish in 4.4 | Low–Medium | Browser-confirm trigger/run/retry flow before any rework |
| P1-7 | Room Move | Real operational need; currently hard-blocked once checked in | Phase 4.3B (TransferRoom) | Medium | Start after Phase 4.3A closes |
| P1-8 | Upgrade / Downgrade | Same as Room Move, built on TransferRoom | Phase 4.3B | Medium | After Room Move |
| P2-9 | Revenue basic usability | Reporting; will look sparse early in pilot, not a defect | Confirm in browser during pilot | Low | No build — just confirm it renders and reads correctly |
| P2-10 | Reconciliation basic usability | Same as Revenue | Confirm in browser during pilot | Low | No build — confirm only |
| P2-11 | Operational Review by shift | Shift handover visibility; not yet scoped anywhere | Phase 4.4 / 5.x | Medium | Scope later; not a pilot blocker |
| P3-12 | Dashboard | Nice-to-have | Phase 5.x | — | Deferred |
| P3-13 | Advanced reports | Nice-to-have | Phase 5.x | — | Deferred |
| P3-14 | Overstay warning | Explicitly excluded from go-live core unless a safety incident proves otherwise | Phase 5.x | — | Deferred |
| P3-15 | Automation / notifications | Nice-to-have | Phase 5.x | — | Deferred |

---

## First Implementation Target

**Phase 4.3A Backend Milestone 1 — Stay Event Foundation** (`docs/implementation-plans/phase-4.3a-stay-foundation-implementation-plan.md` §7).

Why: already planned and reviewed-ready; unlocks P0-1 and P0-2; creates the audit trail (`stay_events` table + `recordStayEvent()` helper) that both depend on; small, additive, zero Folio/Night-Audit/Housekeeping touch.

---

## Next-Step Execution Prompt (for Claude's next session)

```
Implement Phase 4.3A Backend Milestone 1 — Stay Event Foundation ONLY.
Reference: docs/implementation-plans/phase-4.3a-stay-foundation-implementation-plan.md §7.

Scope (additive only, nothing else touched):
1. Migration: create `stay_events` table exactly per the plan's DDL
   (stay_id, event_type, actor_id, occurred_at, metadata JSON, timestamps;
   FK stay_id→stays RESTRICT, FK actor_id→users SET NULL;
   indexes: stay_id, event_type, (stay_id, event_type)).
2. Enum: app/Enums/StayEventType.php — cases CheckIn, ExtendStay,
   PartialCheckout, Checkout only (no TransferRoom/Upgrade/Downgrade/
   Adjustment yet), with Vietnamese label() matching existing enum style.
3. Model: app/Models/StayEvent.php — fillable, casts (event_type,
   occurred_at datetime, metadata array), belongsTo(Stay), belongsTo(User,
   'actor_id'). No AuditObserver attached.
4. Factory: database/factories/StayEventFactory.php.
5. Stay model relationship to StayEvent (hasMany).
6. StayService: add private recordStayEvent(Stay $stay, StayEventType
   $type, ?User $actor, array $metadata = []): void, called inside the
   same DB::transaction() as its trigger — never its own transaction.
7. Retrofit: add one recordStayEvent(..., StayEventType::CheckIn, ...)
   call inside the EXISTING StayService::checkIn() — one line, no other
   behavior change.
8. Tests: StayEvent model unit test (factory, casts, relationships);
   extend existing check-in feature test with one assertion that a
   StayEvent row (event_type=CheckIn) exists after checkIn().
9. Run targeted tests for the new/changed files, then run the full
   regression suite — zero new failures expected.
10. Write docs/reports/phase-4.3a-backend-m1-report.md covering: what
    was built, test results (targeted + full suite), and an explicit
    confirmation that no FolioService/NightAuditPipeline/Housekeeping
    file appears in the diff.

Do NOT:
- Implement Stay Extension (Milestone 2) or Partial Checkout (Milestone 3).
- Touch Folio, Payment, Night Audit, Housekeeping, or Booking schema/service.
- Add TransferRoom/Upgrade/Downgrade/Adjustment enum cases.
- Commit, push, or tag.

Stop after the report is written. Wait for review before Milestone 2.
```
