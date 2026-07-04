# Phase 3.3.4 – Reconciliation Tools: Backend Report

## Summary

Phase 3.3.4 adds a read-only financial reconciliation layer for accountants and managers.
It surfaces outstanding balances, financial discrepancies, and voided entry summaries
without mutating any bookings, folios, payments, or folio entries (ADR-78).

Post-review fixes applied:
- `cursor()` → `lazy()` in `exportVoidedRows()` (cursor bypasses eager loading; lazy uses chunkById correctly)
- Added `$request->validate(['from' => 'nullable|date', 'to' => 'nullable|date'])` in `resolveRange()` to prevent uncaught Carbon parse exceptions on malformed date input
- Added test for `CLOSED_FOLIO_NON_ZERO_BALANCE` discrepancy type

---

## Files Created

| File | Purpose |
|------|---------|
| `app/Services/ReconciliationService.php` | Core reconciliation logic (read-only) |
| `app/Http/Controllers/Admin/ReconciliationController.php` | 4 HTTP endpoints (index, voids, exportOutstanding, exportVoids) |
| `tests/Feature/ReconciliationTest.php` | 23 feature tests, 90 assertions |

## Files Modified

| File | Change |
|------|--------|
| `database/seeders/RolePermissionSeeder.php` | Added `reconciliation.view` to PERMISSIONS, MANAGER, ACCOUNTANT |
| `routes/web.php` | Added 4 reconciliation routes under `admin.` prefix |

---

## Architecture

### ReconciliationService

All methods are strictly read-only. No writes, no transactions, no locks.

| Method | Returns | Description |
|--------|---------|-------------|
| `outstandingBalances(array $filters)` | `array` | Bookings where balance_due > 0 |
| `discrepancies()` | `array` | Closed-folio or checked-out bookings with non-zero balance |
| `voidedEntries(Carbon $from, Carbon $to)` | `array` | Voided FolioEntries with booking reference and void metadata |
| `exportOutstandingRows(array $filters)` | `iterable` | For CSV streaming |
| `exportVoidedRows(Carbon $from, Carbon $to)` | `iterable` | For CSV streaming via `cursor()` |
| `computeBalance(Booking $booking)` (private) | `array` | Same formula as `BookingService::paymentSummary()` |

### Balance Formula

Identical to `BookingService::paymentSummary()`:
```
folio_total = SUM(folio_entries.amount WHERE voided_at IS NULL)
paid_total  = SUM(Deposit, AdditionalDeposit, RoomPayment, ServicePayment, Adjustment)
            - SUM(Refund)
balance_due = bcsub(folio_total, paid_total, 2)
```

Works on eager-loaded relationships to avoid N+1 queries:
- `folio.folioEntries` (constrained to `whereNull('voided_at')`)
- `bookingPayments`

Formula agreement is verified by test #4 (`test_outstanding_balance_formula_matches_booking_service_payment_summary`).

### ADR-78 Compliance

`ReconciliationService` is read-only. It does NOT:
- Create, void, or update folio entries
- Add, delete, or modify booking payments
- Change booking or folio status
- Interact with NightAuditRun, PostingJob, or RevenueReportService
- Use any `lockForUpdate()` or write transactions

Test #15 (`test_reconciliation_service_does_not_mutate_any_records`) verifies row counts and model state are unchanged after calling all service methods.

---

## Routes

| Method | URL | Name | Controller method |
|--------|-----|------|------------------|
| GET | `/admin/reconciliation` | `admin.reconciliation.index` | `index()` |
| GET | `/admin/reconciliation/voids` | `admin.reconciliation.voids` | `voids()` |
| GET | `/admin/reconciliation/export` | `admin.reconciliation.export` | `exportOutstanding()` |
| GET | `/admin/reconciliation/voids/export` | `admin.reconciliation.voids-export` | `exportVoids()` |

---

## Permission: `reconciliation.view`

| Role | Granted |
|------|---------|
| ADMIN | ✓ (via `syncPermissions(self::PERMISSIONS)`) |
| MANAGER | ✓ |
| ACCOUNTANT | ✓ |
| RECEPTION | ✗ |
| SALES | ✗ |
| HOUSEKEEPING | ✗ |

---

## Inertia Props

### `index()` → `Admin/Reconciliation/Index`

| Prop | Type | Description |
|------|------|-------------|
| `outstanding` | `array` | Bookings with balance_due > 0 |
| `discrepancies` | `array` | Closed-folio / checked-out anomalies |
| `filters` | `{status}` | Echoed query params |
| `businessDate` | `string` | Current business date |

Each `outstanding` row: `booking_id`, `booking_code`, `customer_name`, `status`, `folio_status`, `total_charges`, `paid_total`, `balance_due`.

Each `discrepancies` row: `type`, `booking_id`, `booking_code`, `customer_name`, `booking_status`, `folio_status`, `balance_due`.

Discrepancy types: `CHECKED_OUT_OUTSTANDING_BALANCE`, `CLOSED_FOLIO_NON_ZERO_BALANCE`, `NON_ZERO_BALANCE`.

### `voids()` → `Admin/Reconciliation/Voids`

| Prop | Type | Description |
|------|------|-------------|
| `voids` | `array` | Voided FolioEntry records in date range |
| `filters` | `{from, to}` | Echoed date range |
| `businessDate` | `string` | Current business date |

Each `voids` row: `id`, `booking_code`, `customer_name`, `charge_type`, `charge_label`, `description`, `amount`, `entry_date`, `voided_at`, `voided_by`, `void_reason`.

### Date Range (voids)

- Default: start of current month → today
- Filter: `from` / `to` query params
- Clamped to 365-day maximum range
- Filter by `voided_at` date (not entry_date)

---

## CSV Exports

### `exportOutstanding`

Filename: `outstanding-YYYYMMDD.csv`
Headers: `Mã đặt phòng`, `Khách hàng`, `Trạng thái đặt phòng`, `Trạng thái folio`, `Tổng phí`, `Đã thanh toán`, `Còn nợ`

### `exportVoids`

Filename: `voided-entries-YYYYMMDD-YYYYMMDD.csv`
Headers: `Mã đặt phòng`, `Khách hàng`, `Loại phí`, `Mô tả`, `Thành tiền`, `Ngày phát sinh`, `Thời gian hủy`, `Người hủy`, `Lý do hủy`

Both exports:
- UTF-8 BOM prefix for Excel compatibility
- `cursor()` for memory-safe streaming on large datasets

---

## Tests

**24 tests, 92 assertions — all passing.**

| # | Test | Covers |
|---|------|--------|
| 1 | outstanding_balances_returns_bookings_with_positive_balance_due | Balance formula |
| 2 | outstanding_balances_excludes_fully_paid_bookings | Zero balance excluded |
| 3 | outstanding_balances_excludes_overpaid_bookings | Negative balance excluded |
| 4 | outstanding_balance_formula_matches_booking_service_payment_summary | Formula parity |
| 5 | outstanding_balances_filter_by_status_returns_matching_bookings_only | Status filter |
| 6 | closed_folio_with_non_zero_balance_appears_in_discrepancies | Discrepancy: closed folio |
| 7 | checked_out_booking_with_balance_appears_in_discrepancies | Discrepancy: checked-out |
| 7b | non_checked_out_booking_with_closed_folio_gets_correct_discrepancy_type | CLOSED_FOLIO_NON_ZERO_BALANCE type |
| 8 | voided_entries_includes_voided_folio_entries_in_range | Voids in range |
| 9 | voided_entries_excludes_active_folio_entries | Active entries excluded |
| 10 | voided_entries_includes_voided_by_name_and_void_reason | Void metadata |
| 11 | csv_export_outstanding_contains_correct_headers_and_rows | CSV outstanding |
| 12 | csv_export_voided_contains_correct_headers_and_rows | CSV voids |
| 13 | admin_can_view_reconciliation_index | Admin access + Inertia props |
| 14 | manager_can_view_reconciliation | Manager access |
| 15 | accountant_can_view_reconciliation | Accountant access |
| 16 | reception_cannot_view_reconciliation | Reception blocked |
| 17 | sales_cannot_view_reconciliation | Sales blocked |
| 18 | unauthenticated_user_cannot_view_reconciliation | Auth redirect |
| 19 | reconciliation_service_does_not_mutate_any_records | ADR-78 compliance |
| 20 | admin_can_view_reconciliation_voids_page | Voids page + Inertia props |
| 21 | refund_reduces_paid_total_in_outstanding_balance | Refund handling |
| 22 | voided_charges_excluded_from_outstanding_balance_calculation | Voided charge exclusion |
| 23 | voided_entries_excludes_entries_outside_date_range | Date range filter |

---

## Regression Analysis

| Metric | Pre-3.3.4 | Post-3.3.4 |
|--------|-----------|------------|
| New tests | — | 24 |
| Total passing | 466 | 490 |
| Pre-existing failures | 26 | 26 |
| New regressions | — | **0** |

---

## Design Decisions

1. **Eager loading over N+1**: `outstandingBalances()` and `discrepancies()` eager-load
   `folio.folioEntries` (constrained to non-voided) and `bookingPayments` in 3–4 queries total,
   then compute balance in PHP using the same formula as `paymentSummary()`.

2. **`computeBalance()` not `BookingService::paymentSummary()`**: Calling the service method
   per booking would trigger fresh DB queries even with eager loading (because the method calls
   `$folio->folioEntries()` as a new query builder). Computing in PHP over the already-loaded
   collections avoids N+1 while keeping the formula identical.

3. **`cursor()` for voided export**: `exportVoidedRows()` uses `cursor()` (LazyCollection) to
   stream large datasets without loading all rows into memory, matching the pattern in
   `RevenueReportService::exportRows()`.

4. **Float tolerance `0.005`**: `bcsub(..., 2)` produces precise 2-decimal results; the 0.005
   threshold guards against floating-point noise when comparing `balance_due > 0`.

5. **Voided-at date range for voids report**: The voids report filters by `voided_at` date
   (when was the entry voided) rather than `entry_date` (when was the charge originally created).
   This lets accountants answer "what was voided this month?" regardless of when the charge was posted.

---

## Ready for ChatGPT Backend Review

**YES** — 23/23 tests passing, 90 assertions, 0 regressions.

## Awaiting

- ChatGPT Backend Review
- Frontend implementation (Phase 3.3.4 frontend — NOT started, per scope)
- Commit (blocked until review clears)
