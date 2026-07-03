# Phase 3.3 — Financial Operations: Implementation Plan

**Date:** 2026-07-02
**Status:** Design — Pending Architecture Review
**Architecture Reference:** `docs/roadmaps/phase-3.3-financial-operations.md`
**Prerequisite:** Phase 3.2 complete (commit `3a06631`)
**Do NOT implement until architecture document is approved.**

---

## Overview

Phase 3.3 consists of **5 sub-phases**. Sub-phases 3.3.1 is the prerequisite foundation (Night Audit operational controls). Sub-phases 3.3.3, 3.3.4, and 3.3.5 are independent and may be implemented in parallel after 3.3.1. Sub-phase 3.3.2 (Breakfast PostingJob) requires a migration and registers a new pipeline step — it carries the highest regression risk and should be reviewed most carefully.

```
3.3.1  Night Audit Operations Center    [foundation — do first]
  ↓
3.3.2  Breakfast PostingJob             [new pipeline step — highest regression risk]
3.3.3  Revenue Summary Dashboard        [read-only reporting — safe any order]
3.3.4  Reconciliation Tools             [read-only reporting — safe any order]
3.3.5  Posting Timeline & Rate History  [read-only views — safe any order]
```

---

## Sub-Phase 3.3.1 — Night Audit Operations Center

### Objective

Expose Night Audit run management through a web UI. Hotel managers must be able to see run history, view per-booking logs, trigger manual runs, and retry failed runs — without CLI access.

### Files Expected

**Backend:**

| File | Action |
|------|--------|
| `app/Services/NightAuditOperationsService.php` | NEW — manual trigger, retry, run summary, booking log retrieval |
| `app/Http/Controllers/Admin/NightAuditController.php` | MODIFY — add `trigger()`, `retry()`, `show()` actions |
| `app/Http/Requests/Admin/TriggerNightAuditRequest.php` | NEW — validates `date` input |
| `app/Policies/NightAuditRunPolicy.php` | MODIFY — add `trigger` and `retry` policy methods |
| `routes/web.php` | MODIFY — add POST `/admin/night-audit/trigger`, POST `/admin/night-audit/{run}/retry` |
| `app/Exceptions/ManualRunBlockedException.php` | NEW |
| `app/Exceptions/RunNotRetryableException.php` | NEW |

**Frontend:**

| File | Action |
|------|--------|
| `resources/js/Pages/Admin/NightAudit/Index.vue` | NEW — run history table, trigger button, status badges |
| `resources/js/Pages/Admin/NightAudit/Show.vue` | NEW — run detail, booking log table, retry button |
| `resources/js/Components/NightAuditTriggerModal.vue` | NEW — date picker + confirm dialog |

**Tests:**

| File | Tests |
|------|-------|
| `tests/Feature/NightAuditOperationsTest.php` | NEW — trigger validation, retry eligibility, duplicate run guard, policy enforcement |
| `tests/Unit/NightAuditOperationsServiceTest.php` | NEW — unit coverage for trigger logic, retry logic, status transitions |

### Risk

**LOW-MEDIUM.** Backend adds new routes and a new service method. No changes to the existing `NightAuditPipeline`, `NightAuditService`, or any posting logic. The only risk is the manual trigger path which must not bypass the `UNIQUE` constraint on `audit_date`. This is handled at DB level as a final safety net even if the service-layer check is bypassed.

### Tests Required

- `test_trigger_creates_new_run_for_valid_date`
- `test_trigger_blocked_if_completed_run_exists_for_date`
- `test_trigger_blocked_outside_audit_window`
- `test_trigger_requires_admin_role`
- `test_retry_reprocesses_only_failed_booking_logs`
- `test_retry_blocked_if_run_is_completed`
- `test_retry_updates_run_status_to_completed_when_all_resolved`
- `test_manager_can_view_run_history_and_detail`
- `test_receptionist_cannot_view_night_audit`

### Review Checkpoint

Submit for ChatGPT review after completing backend service + tests. Do not implement frontend until backend is approved.

### Commit Checkpoint

One commit per layer: `feat: Phase 3.3.1 Night Audit Operations Center — backend`, `feat: Phase 3.3.1 Night Audit Operations Center — UI`

---

## Sub-Phase 3.3.2 — Additional Night Audit Pipeline Steps (Breakfast)

### Objective

Register `BreakfastPostingJob` as an optional Night Audit pipeline step. Introduce `booking_package_flags` to track per-booking service enrollments. Validate that the Phase 3.2 pipeline extensibility architecture (ADR-69, ADR-70) works as designed by adding a real second job.

### Files Expected

**Migration:**

| File | Action |
|------|--------|
| `database/migrations/XXXX_create_booking_package_flags_table.php` | NEW — `booking_package_flags` table with UNIQUE(booking_id, package_key) |
| `database/seeders/BookingPackageFlagSeeder.php` | NEW (optional) — for test/QA data only |

**Backend:**

| File | Action |
|------|--------|
| `app/Models/BookingPackageFlag.php` | NEW — Eloquent model; `belongsTo Booking`, `belongsTo User` (created_by) |
| `app/Services/Posting/BreakfastPostingJob.php` | NEW — implements `PostingJob`; posting_key `BREAKFAST_{stay_id}_{date}` |
| `app/Services/NightAuditPipeline.php` | MODIFY — register `BreakfastPostingJob` in the pipeline discovery |
| `app/Services/PackageEnrollmentService.php` | NEW — `enroll(Booking, string $packageKey, User)`, `unenroll(...)`, `isEnrolled(Booking, string)` |
| `app/Http/Controllers/Admin/BookingPackageController.php` | NEW — `store()`, `destroy()` for enrollment API |
| `app/Policies/BookingPackageFlagPolicy.php` | NEW — ADMIN + MANAGER may enroll; RECEPTIONIST read-only |
| `routes/web.php` | MODIFY — POST/DELETE `/admin/bookings/{booking}/packages/{key}` |

**Frontend:**

| File | Action |
|------|--------|
| `resources/js/Pages/Admin/Bookings/Show.vue` | MODIFY — add Packages section (enrollment toggles) |
| `resources/js/Components/PackageEnrollmentPanel.vue` | NEW — toggle list for available packages |

**Tests:**

| File | Tests |
|------|-------|
| `tests/Feature/BookingPackageEnrollmentTest.php` | NEW — enroll/unenroll, guard post-posting, policy |
| `tests/Feature/BreakfastPostingJobTest.php` | NEW — enrolled booking posts, non-enrolled skips, missing rate skips, idempotency |
| `tests/Feature/NightAuditBreakfastPipelineTest.php` | NEW — full pipeline run with mixed enrolled/non-enrolled bookings |

### Risk

**MEDIUM-HIGH.** This is the highest-risk sub-phase because it modifies the production Night Audit Pipeline. Key concerns:

1. **Non-enrolled bookings must not receive breakfast charges.** `shouldProcess()` is the guard — must be unit-tested exhaustively.
2. **Pipeline ordering must not create a cycle.** `BreakfastPostingJob::dependsOn()` returns `['ROOM_NIGHT']`; the topological sort must validate this at startup.
3. **Idempotency under retry.** A second Night Audit run for the same date must not create a duplicate `BREAKFAST_{stay_id}_{date}` entry.
4. **Rate absence must be a SKIP, not a FAILURE.** A hotel that enables Phase 3.3.2 without seeding a FOOD_BEVERAGE rate must not see audit failures.

### Tests Required

- `test_breakfast_posting_job_posts_entry_for_enrolled_booking`
- `test_breakfast_posting_job_skips_non_enrolled_booking`
- `test_breakfast_posting_job_skips_when_no_active_food_beverage_rate`
- `test_breakfast_posting_job_is_idempotent`
- `test_breakfast_posting_job_depends_on_room_night`
- `test_pipeline_processes_room_before_breakfast`
- `test_pipeline_with_no_enrolled_bookings_completes_without_breakfast_entries`
- `test_enroll_booking_for_breakfast_stores_package_flag`
- `test_unenroll_booking_removes_package_flag`
- `test_unenroll_blocked_if_posting_key_exists_for_current_night`

### Review Checkpoint

Full ChatGPT review required after all backend + test implementation. Do NOT implement frontend enrollment UI until pipeline tests are passing and reviewed.

### Commit Checkpoint

Two commits: `feat: Phase 3.3.2 BreakfastPostingJob + booking_package_flags`, `feat: Phase 3.3.2 Breakfast enrollment UI`

---

## Sub-Phase 3.3.3 — Revenue Summary & Daily Revenue Report

### Objective

Give hotel management a daily and period-based view of revenue by charge type and posting source. All data comes from existing `folio_entries`; no schema changes required.

### Files Expected

**Backend:**

| File | Action |
|------|--------|
| `app/Services/RevenueReportService.php` | NEW — `dailySummary()`, `periodSummary()`, `revenueBySource()` |
| `app/Http/Controllers/Admin/RevenueReportController.php` | NEW — index (view), export (CSV download) |
| `routes/web.php` | MODIFY — GET `/admin/revenue`, GET `/admin/revenue/export` |

**Frontend:**

| File | Action |
|------|--------|
| `resources/js/Pages/Admin/Revenue/Index.vue` | NEW — date range picker, summary table, source breakdown |

**Tests:**

| File | Tests |
|------|-------|
| `tests/Feature/RevenueReportTest.php` | NEW — daily aggregation, period aggregation, voided exclusion, source breakdown, CSV export |
| `tests/Unit/RevenueReportServiceTest.php` | NEW — unit coverage for aggregation logic |

### Risk

**LOW.** Pure read queries on `folio_entries`. No writes. No pipeline interaction. The only risk is a slow query on large datasets — mitigated by existing indexes on `entry_date` and `charge_type`.

### Tests Required

- `test_daily_summary_sums_non_voided_entries_by_charge_type`
- `test_daily_summary_excludes_voided_entries`
- `test_period_summary_aggregates_across_date_range`
- `test_revenue_by_source_groups_by_posting_source`
- `test_null_posting_source_treated_as_manual`
- `test_csv_export_contains_correct_headers_and_rows`
- `test_manager_can_view_revenue_dashboard`
- `test_receptionist_cannot_view_revenue_dashboard`

### Review Checkpoint

One ChatGPT review after backend + tests. Frontend may be reviewed in the same pass.

### Commit Checkpoint

One commit: `feat: Phase 3.3.3 Revenue Summary Dashboard`

---

## Sub-Phase 3.3.4 — Reconciliation Tools

### Objective

Give the accountant a consolidated view of bookings with outstanding balances and a summary of voided entries. All data is computed from existing tables; no schema changes required.

### Files Expected

**Backend:**

| File | Action |
|------|--------|
| `app/Services/ReconciliationService.php` | NEW — `outstandingBalances()`, `discrepancyReport()`, `voidedEntriesSummary()` |
| `app/Http/Controllers/Admin/ReconciliationController.php` | NEW — `index()` (balances), `voids()`, `exportBalances()`, `exportVoids()` |
| `routes/web.php` | MODIFY — GET `/admin/reconciliation`, GET `/admin/reconciliation/voids`, GET export variants |

**Frontend:**

| File | Action |
|------|--------|
| `resources/js/Pages/Admin/Reconciliation/Index.vue` | NEW — outstanding balance table, filter by status |
| `resources/js/Pages/Admin/Reconciliation/Voids.vue` | NEW — voided entries table, date range filter |

**Tests:**

| File | Tests |
|------|-------|
| `tests/Feature/ReconciliationTest.php` | NEW — outstanding balances, discrepancy detection, voided entries |
| `tests/Unit/ReconciliationServiceTest.php` | NEW — balance formula, filter by status |

### Risk

**LOW.** Read-only queries. No writes. No pipeline interaction. The balance formula (`folio_total - paid_total`) replicates the same logic as `BookingService::paymentSummary()` — regression risk is minimal, but tests must explicitly assert agreement between the two computation paths.

### Tests Required

- `test_outstanding_balances_returns_bookings_with_positive_balance_due`
- `test_outstanding_balances_excludes_fully_paid_bookings`
- `test_outstanding_balances_filter_by_checked_out_status`
- `test_discrepancy_report_flags_closed_folio_with_non_zero_balance`
- `test_voided_entries_summary_groups_by_charge_type_and_voided_by`
- `test_csv_export_outstanding_balances`
- `test_manager_can_view_reconciliation`
- `test_receptionist_cannot_view_reconciliation`
- `test_balance_formula_agrees_with_payment_summary`

### Review Checkpoint

One ChatGPT review after backend + tests. Frontend may be reviewed in the same pass.

### Commit Checkpoint

One commit: `feat: Phase 3.3.4 Reconciliation Tools`

---

## Sub-Phase 3.3.5 — Posting Timeline & Service Rate History

### Objective

Surface the chronological posting history of a folio directly in the booking detail view, and expose service rate version history in the admin catalog. Both are read-only views over existing data.

### Files Expected

**Backend:**

| File | Action |
|------|--------|
| `app/Services/PostingTimelineService.php` | NEW — `getTimeline(Folio)`, `getRateHistory(string $chargeType)` |
| `app/Http/Controllers/Admin/BookingController.php` | MODIFY — include timeline data in `show()` Inertia props |
| `app/Http/Controllers/Admin/ServiceRateController.php` | MODIFY — add `history(string $chargeType)` action |
| `routes/web.php` | MODIFY — GET `/admin/service-rates/history/{chargeType}` |

**Frontend:**

| File | Action |
|------|--------|
| `resources/js/Components/FolioPostingTimeline.vue` | NEW — chronological entry list; source badges; voided strikethrough; cumulative total |
| `resources/js/Pages/Admin/Bookings/Show.vue` | MODIFY — add `FolioPostingTimeline` to folio panel tab or section |
| `resources/js/Pages/Admin/ServiceRates/Index.vue` | MODIFY — add "History" button per rate row → side panel or modal |
| `resources/js/Components/ServiceRateHistoryPanel.vue` | NEW — rate version list: effective_from, unit_price, is_active |

**Tests:**

| File | Tests |
|------|-------|
| `tests/Feature/PostingTimelineTest.php` | NEW — timeline order, voided entries included (admin), source attribution, cumulative total |
| `tests/Feature/ServiceRateHistoryTest.php` | NEW — rate history returns all versions, ordered by effective_from DESC |
| `tests/Unit/PostingTimelineServiceTest.php` | NEW — unit coverage for timeline construction |

### Risk

**LOW.** All read-only. The only changes to existing files (`BookingController::show()` and `ServiceRateController`) add new Inertia props — additive, backward compatible. The `FolioPostingTimeline` component replaces or augments the existing `FolioEntryTable` — care must be taken not to break the existing folio display.

### Tests Required

- `test_timeline_returns_entries_in_chronological_order`
- `test_timeline_includes_voided_entries_for_admin`
- `test_timeline_excludes_voided_entries_for_non_admin`
- `test_timeline_shows_cumulative_total_for_non_voided_entries`
- `test_timeline_attributes_stay_room_number`
- `test_rate_history_returns_all_versions_by_charge_type`
- `test_rate_history_ordered_by_effective_from_desc`
- `test_booking_show_includes_posting_timeline_prop`

### Review Checkpoint

One ChatGPT review after backend + tests. Frontend may be reviewed in the same pass.

### Commit Checkpoint

One commit: `feat: Phase 3.3.5 Posting Timeline & Service Rate History`

---

## Phase 3.3 Summary

| Sub-Phase | New Files | Migrations | Risk | Priority |
|-----------|-----------|------------|------|----------|
| 3.3.1 Night Audit Operations Center | ~10 | None | LOW-MEDIUM | **HIGHEST** |
| 3.3.2 Breakfast PostingJob | ~10 | 1 new table | MEDIUM-HIGH | HIGH |
| 3.3.3 Revenue Summary | ~4 | None | LOW | MEDIUM |
| 3.3.4 Reconciliation Tools | ~5 | None | LOW | MEDIUM |
| 3.3.5 Posting Timeline & Rate History | ~6 | None | LOW | MEDIUM |

**Total estimated new files:** ~35
**Total migrations:** 1 (`booking_package_flags`)
**Total new test cases:** ~50

---

## Workflow Per Sub-Phase

Each sub-phase follows the project workflow exactly:

```
Architecture (this document) → approved
  ↓
For each sub-phase:
  1. Implement backend (services, controllers, routes)
  2. Implement tests (unit + feature)
  3. Run test suite → confirm no new failures
  4. Generate implementation report
  5. STOP → wait for ChatGPT review
  6. If PASS → implement frontend
  7. Generate updated implementation report
  8. STOP → wait for ChatGPT review
  9. If PASS → commit
  ↓
After all sub-phases:
  Phase 3.3 Final Review document
  ChatGPT Final Review
  Tag milestone
```

**Do NOT commit any sub-phase before its ChatGPT review passes.**

---

## Pre-Implementation Checklist

Before starting Phase 3.3:

- [ ] `docs/roadmaps/phase-3.3-financial-operations.md` — ChatGPT Architecture Review: PASS
- [ ] `docs/roadmaps/phase-3.3-implementation-plan.md` — ChatGPT Architecture Review: PASS
- [ ] Phase 3.2 production deployment checklist complete (backfill run, hotel settings seeded)
- [ ] Test suite baseline confirmed: 27 pre-existing failures, 386 passed
- [ ] Branch `phase-3` up to date with latest `phase-3` commits
