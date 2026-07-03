# Phase 3.3.3 – Revenue Summary & Daily Revenue Report: Backend Report

## Summary

Phase 3.3.3 adds a read-only revenue reporting layer on top of the existing `folio_entries` table.
No data is mutated. No new database tables are created.
The implementation satisfies ADR-77 (voided entries excluded) and ADR-78 (reporting is read-only).

---

## Scope

- Backend only (frontend deferred to Phase 3.3.4)
- No changes to Night Audit, PostingJob, FolioService, or any financial write path
- `revenue.view` permission scoped to ADMIN, MANAGER, and ACCOUNTANT (RECEPTION and SALES excluded by design)

---

## Files Created

| File | Purpose |
|------|---------|
| `app/Services/RevenueReportService.php` | Core read-only revenue aggregation service |
| `app/Http/Controllers/Admin/RevenueReportController.php` | Inertia index + CSV export endpoints |
| `tests/Feature/RevenueReportTest.php` | 18 feature tests covering service logic, isolation, auth, and CSV export |

## Files Modified

| File | Change |
|------|--------|
| `database/seeders/RolePermissionSeeder.php` | Added `revenue.view` permission; granted to ADMIN, MANAGER, ACCOUNTANT |
| `routes/web.php` | Added `GET admin/reports/revenue` and `GET admin/reports/revenue/export` |

---

## Architecture

### Permission Design

New `revenue.view` permission created instead of reusing `report.view`:

| Role | `report.view` | `revenue.view` |
|------|--------------|---------------|
| ADMIN | ✓ | ✓ |
| MANAGER | ✓ | ✓ |
| SALES | ✓ | ✗ |
| RECEPTION | ✓ | ✗ |
| HOUSEKEEPING | ✗ | ✗ |
| ACCOUNTANT | ✓ | ✓ |

Rationale: `report.view` was already granted to RECEPTION and SALES. A financial revenue report requires a more narrow permission scoped to accounting/management roles.

### Service: `RevenueReportService`

Three public methods, all read-only:

```
dailySummary(Carbon $date): array
    → { date, total, by_charge_type: [{ charge_type, label, amount, count }] }

periodSummary(Carbon $from, Carbon $to): array
    → { from, to, total, by_charge_type: [...], by_date: [{ date, total, count }] }

revenueBySource(Carbon $from, Carbon $to): array
    → { from, to, total, by_source: [{ source, label, amount, count }] }

exportRows(Carbon $from, Carbon $to): iterable  // LazyCollection via ->cursor()
```

**SQL invariants:**
- All queries use `FolioEntry::active()` scope → `WHERE voided_at IS NULL` (ADR-77)
- Date comparisons use `->whereDate('entry_date', ...)` → generates `DATE(entry_date) = '...'` (SQLite-safe)
- `COALESCE(posting_source, 'MANUAL')` in `revenueBySource` handles legacy NULL rows defensively
- Revenue is sourced exclusively from `folio_entries.amount` (no booking_payments, no booking_requirements)

### Controller: `RevenueReportController`

```
GET  admin/reports/revenue         → index()   → Inertia::render('Admin/Revenue/Index')
GET  admin/reports/revenue/export  → export()  → StreamedResponse (CSV)
```

**Authorization:** `abort_unless($request->user()?->can('revenue.view'), 403)` — direct Spatie permission check, no Policy needed for a simple view gate.

**Date range resolution (both endpoints):**
- Default: current month start → today (business date)
- `from` > `to` → reset to start of `to`'s month
- Max 365 days; auto-clamps to `to - 365d` if exceeded

**CSV:**
- UTF-8 BOM prepended for Excel compatibility
- Streaming via `response()->streamDownload()` + `cursor()` — memory-safe for large date ranges
- Headers: `Ngày, Loại phí, Nguồn, Mô tả, Số lượng, Đơn giá, Thành tiền`
- Filename: `revenue-YYYYMMDD-YYYYMMDD.csv`

---

## Routes

```
admin.reports.revenue.index   GET  admin/reports/revenue
admin.reports.revenue.export  GET  admin/reports/revenue/export
```

Both routes live inside the existing `admin.*` prefix + `auth` middleware group.

---

## Tests

### File: `tests/Feature/RevenueReportTest.php`

**18 tests, 64 assertions — all passing**

| # | Test | Category |
|---|------|----------|
| 1 | Daily summary sums non-voided entries by charge type | Service |
| 2 | Daily summary excludes voided entries | Service |
| 3 | Period summary aggregates across date range | Service |
| 4 | Period summary excludes voided entries | Service |
| 5 | Date filter uses entry_date | Service |
| 6 | Revenue by source groups all posting sources | Service |
| 7 | Entries with default posting_source are grouped as MANUAL | Service |
| 8 | Booking payments are not counted as revenue | Isolation |
| 9 | Booking requirements are not counted as revenue | Isolation |
| 10 | Unauthenticated user cannot view revenue report | Auth |
| 11 | RECEPTION cannot view revenue report | Auth |
| 12 | SALES cannot view revenue report | Auth |
| 13 | Admin can view revenue report | Auth |
| 14 | Manager can view revenue report | Auth |
| 15 | Accountant can view revenue report | Auth |
| 16 | CSV export requires revenue.view permission | CSV |
| 17 | CSV export contains correct headers and rows | CSV |
| 18 | CSV export excludes voided entries | CSV |

### Notes

- Test 7 (NULL posting_source → MANUAL): The `posting_source` column has NOT NULL with DEFAULT 'MANUAL'. The COALESCE in the SQL is a defensive measure for legacy production rows that may predate the column's migration. Tests verify the MANUAL grouping via the DB default. Direct NULL insertion via SQLite is blocked by the NOT NULL constraint; the COALESCE correctness is verifiable by code inspection.

---

## ADR Compliance

| ADR | Requirement | Status |
|-----|-------------|--------|
| ADR-77 | Revenue reporting excludes voided entries | ✅ `FolioEntry::active()` (whereNull voided_at) |
| ADR-78 | Reconciliation / reporting must be read-only | ✅ No INSERT/UPDATE/DELETE in any code path |

---

## Regression Analysis

| Metric | Pre-3.3.3 | Post-3.3.3 |
|--------|-----------|-----------|
| Total tests | 474 | 492 |
| Passing | 447 | 466 |
| Pre-existing failures (3 known classes, isolated) | 26 | 26 |
| New regressions | — | **0** |

The 26 pre-existing failures are unchanged (`BookingManagementUiTest`, `DashboardTest`, `RoomAvailabilityCheckerTest`). All Phase 3.3.3 changes are additive:
- Zero writes to any financial table
- No changes to `FolioService`, `NightAuditService`, `RoomChargePostingJob`, `BreakfastPostingJob`
- Seeder changes are additive only: new permission `revenue.view` added to `PERMISSIONS` array and granted to ADMIN + MANAGER + ACCOUNTANT via `syncPermissions`. Existing role permissions unchanged.
- Route addition is additive; no existing route modified

---

## Awaiting

- ChatGPT Backend Review
- Commit / push (blocked until review clears)
- Phase 3.3.4: Revenue Index frontend (Vue component — `Admin/Revenue/Index.vue`)
