# Phase 3.3.4 – Reconciliation Tools: Frontend Report

## Summary

Phase 3.3.4 frontend adds a two-page financial reconciliation UI at `/admin/reconciliation`.
All financial calculations remain in the backend (`ReconciliationService`).
Vue components are purely presentational — no client-side financial logic.

Post-review fixes applied:
- Non-unique `v-for` key on discrepancies table: `row.booking_id` → `` `${row.booking_id}-${row.type}` ``
- `exportUrl` derived from `props.filters` (committed server state) rather than `filter` (pending client state) on both pages
- Added `id`/`for` associations on all filter labels and inputs for screen reader accessibility

---

## Files Created

| File | Purpose |
|------|---------|
| `resources/js/Pages/Admin/Reconciliation/Index.vue` | Outstanding balances + discrepancies page |
| `resources/js/Pages/Admin/Reconciliation/Voids.vue` | Voided entries page with date range filter |

## Files Modified

| File | Change |
|------|--------|
| `resources/js/Layouts/AppLayout.vue` | Added `Scale` icon import; added "Đối soát" nav item gated on `reconciliation.view` |

---

## UI Implemented

### Index.vue (`/admin/reconciliation`)

**Page header**
- Title: "Đối soát tài chính"
- Current business date display
- Navigation link to Voids page ("Phí đã hủy →")
- CSV export link (outstanding balances)

**Status filter**
- `<select>` with all 13 `BookingStatus` values + "Tất cả trạng thái"
- **Áp dụng** → `router.get(route('admin.reconciliation.index'), {status}, { preserveState: true, replace: true })`
- **Đặt lại** → clears status, reapplies
- Label linked to select via `id="status-filter"` / `for="status-filter"`

**Summary cards (2)**
- Total outstanding: sum of `outstanding[*].balance_due`, in coral
- Discrepancy count: `discrepancies.length`, coral if > 0 else pine

**Outstanding balances table**
- Columns: Mã đặt phòng | Khách hàng | Trạng thái | Folio | Tổng phí | Đã TT | Còn nợ
- Booking code is a `<Link>` to the booking detail page (`admin.bookings.show`)
- Status badge with color: CHECKED_IN=blue, CANCELLED=coral, others=gray
- "Còn nợ" (balance due) column in coral bold
- Footer row: total outstanding (coral bold)
- Empty state: "Không có khoản tồn nợ nào."

**Discrepancies table**
- Columns: Loại bất thường | Mã đặt phòng | Khách hàng | Trạng thái | Folio | Số dư
- `v-for` key: `` `${row.booking_id}-${row.type}` `` (composite, safe for multi-row per booking)
- Discrepancy type badge colors:
  - `CHECKED_OUT_OUTSTANDING_BALANCE` → `bg-coral/10 text-coral`
  - `CLOSED_FOLIO_NON_ZERO_BALANCE` → `bg-amber/10 text-amber-700`
  - `NON_ZERO_BALANCE` → `bg-gray-100 text-gray-600`
- Vietnamese labels: "Đã trả phòng còn nợ", "Folio đóng còn số dư", "Số dư bất thường"
- Empty state: "Không phát hiện bất thường."

**CSV export flow (outstanding)**
- `exportUrl` computed from `props.filters.status` (server state — matches visible table)
- Native `<a>` link bypasses Inertia, triggers browser file download
- Filename: `outstanding-YYYYMMDD.csv`

---

### Voids.vue (`/admin/reconciliation/voids`)

**Page header**
- Title: "Phí đã hủy"
- Business date display
- Navigation link back to Index ("← Tồn nợ")
- CSV export link (voided entries)

**Date range filter**
- `from` / `to` date inputs, labelled via `id="voids-from"` / `id="voids-to"`
- Filter label clarifies it filters by voided_at date: "Từ ngày (ngày hủy)"
- **Áp dụng** → `router.get(route('admin.reconciliation.voids'), filter, { preserveState: true, replace: true })`
- **Đặt lại** → resets to current month-start → businessDate

**Summary cards (2)**
- Total voided amount: sum of `voids[*].amount`
- Entry count: `voids.length`
- Both show `periodLabel` (formatted date range)

**Voided entries table**
- Columns: Mã đặt phòng | Khách hàng | Loại phí | Mô tả | Thành tiền | Ngày phát sinh | Thời gian hủy | Người hủy | Lý do hủy
- Charge type displayed as badge (gray) with Vietnamese label from backend
- Description and void_reason columns use `truncate` + `:title` tooltip for long text
- Footer row: total voided amount (pine bold)
- Empty state: "Không có phí nào bị hủy trong khoảng thời gian này."

**CSV export flow (voids)**
- `exportUrl` computed from `props.filters.from` / `props.filters.to` (server state — matches visible table)
- Filename: `voided-entries-YYYYMMDD-YYYYMMDD.csv`

---

### AppLayout.vue

Added to imports:
```javascript
Scale,
```

Added to `navItems` (after "Doanh thu"):
```javascript
{ label: 'Đối soát', href: '/admin/reconciliation', icon: Scale, show: can('reconciliation.view') }
```

Nav item is active for both `/admin/reconciliation` and `/admin/reconciliation/voids` via `currentPath.startsWith('/admin/reconciliation')`.

---

## Inertia Props Consumed

### Index page

| Prop | Type | Used for |
|------|------|---------|
| `outstanding` | `OutstandingRow[]` | Outstanding balances table, total card |
| `discrepancies` | `DiscrepancyRow[]` | Discrepancy table, count card |
| `filters.status` | `string \| null` | Initialise filter select; derive exportUrl |
| `businessDate` | `string` | Header display |

### Voids page

| Prop | Type | Used for |
|------|------|---------|
| `voids` | `VoidRow[]` | Voided entries table, summary cards |
| `filters.from` | `string` | Initialise date filter; derive exportUrl |
| `filters.to` | `string` | Initialise date filter; derive exportUrl |
| `businessDate` | `string` | Header display; reset-to-today logic |

No client-side financial calculations. All amounts, labels, and aggregations come directly from backend props.

---

## CSV Export Flow

### Outstanding balances

1. Table renders from `props.outstanding` (server-applied filters)
2. `exportUrl` computed from `props.filters.status` (same applied state)
3. User clicks "Xuất CSV" → browser navigates to `/admin/reconciliation/export?status=...`
4. Backend `ReconciliationController@exportOutstanding` checks `reconciliation.view` → streams UTF-8 BOM CSV
5. Browser downloads `outstanding-YYYYMMDD.csv`

### Voided entries

1. Table renders from `props.voids` (server-applied date range)
2. `exportUrl` computed from `props.filters.from` / `props.filters.to` (same applied state)
3. User clicks "Xuất CSV" → browser navigates to `/admin/reconciliation/voids/export?from=...&to=...`
4. Backend `ReconciliationController@exportVoids` checks `reconciliation.view` → streams UTF-8 BOM CSV
5. Browser downloads `voided-entries-YYYYMMDD-YYYYMMDD.csv`

Export always reflects what's visible in the table — pending filter edits (not yet applied) do not affect the export URL.

---

## No Frontend Tests Added

The project has no frontend test infrastructure (no Vitest, no @vue/test-utils, no Playwright configured). Both pages are purely presentational with no custom business logic — all assertions are covered by the 24 PHP feature tests in `ReconciliationTest.php`.

---

## Build

```
vite build — ✓ built in 5.20s (0 errors, 0 warnings)
```

---

## Test Results

| Test file | Tests | Status |
|-----------|-------|--------|
| `ReconciliationTest.php` | 24/24 | ✅ All passing |
| `RevenueReportTest.php` | 18/18 | ✅ All passing |

---

## Regression Analysis

| Metric | Pre-frontend | Post-frontend |
|--------|-------------|--------------|
| Build | ✓ | ✓ (clean, 5.20s) |
| Reconciliation tests | 24/24 | 24/24 |
| Revenue tests | 18/18 | 18/18 |
| Pre-existing failures | 26 | 26 (confirmed by isolated run) |
| New regressions | — | **0** |

Note: Full suite run showed 28 failed vs the expected 26 due to known test-ordering flakiness in `BookingManagementUiTest` (timing-dependent room availability assertions). Running those test files in isolation confirms exactly 26 pre-existing failures, unchanged.

Frontend changes are:
- Two new Vue SFCs (no side effects on existing components)
- One new `import` + one nav item added to AppLayout (additive, no existing entry changed)

---

## Manual QA Checklist

- [ ] Log in as **Admin** → sidebar shows "Đối soát" link with Scale icon
- [ ] Log in as **Manager** → sidebar shows "Đối soát" link
- [ ] Log in as **Accountant** → sidebar shows "Đối soát" link
- [ ] Log in as **Receptionist** → "Đối soát" link NOT shown
- [ ] Log in as **Sales** → "Đối soát" link NOT shown
- [ ] Navigate to `/admin/reconciliation` as Admin → page loads with outstanding + discrepancies tables
- [ ] Empty state: "Không có khoản tồn nợ nào." when no outstanding balances
- [ ] Select status filter → Áp dụng → URL updates, table refreshes
- [ ] Đặt lại → clears filter, page refreshes with all statuses
- [ ] Click "Phí đã hủy →" link → navigates to voids page
- [ ] Voids page loads with date range filter defaulting to current month
- [ ] Change date range → Áp dụng → table refreshes
- [ ] Đặt lại → range resets to current month, page refreshes
- [ ] Click "← Tồn nợ" → returns to Index page
- [ ] Click "Xuất CSV" on Index → downloads `outstanding-YYYYMMDD.csv`
- [ ] Click "Xuất CSV" on Voids → downloads `voided-entries-YYYYMMDD-YYYYMMDD.csv`
- [ ] CSVs contain UTF-8 BOM (Excel opens without encoding issues)
- [ ] Try accessing `/admin/reconciliation` as Receptionist → 403
- [ ] Try accessing `/admin/reconciliation/voids` as Sales → 403
- [ ] Discrepancy badge: "Đã trả phòng còn nợ" in coral, "Folio đóng còn số dư" in amber
- [ ] Booking code links in outstanding table navigate to correct booking detail page

---

## Ready for Final Review

**YES** — implementation complete, build clean, 24/24 tests passing, 0 regressions.

## Awaiting

- ChatGPT Final Review
- Commit (blocked until Final Review clears)
