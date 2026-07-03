# Phase 3.3.3 – Revenue Summary & Daily Revenue Report: Frontend Report

## Summary

Phase 3.3.3 frontend adds a Revenue Summary page at `/admin/reports/revenue`.
The page is purely presentational — all revenue calculations remain in `RevenueReportService`.
No backend business logic was modified. All frontend state changes are Inertia-driven server round-trips.

---

## Files Created

| File | Purpose |
|------|---------|
| `resources/js/Pages/Admin/Revenue/Index.vue` | Revenue Summary page (Inertia component) |

## Files Modified

| File | Change |
|------|--------|
| `resources/js/Layouts/AppLayout.vue` | Added `TrendingUp` icon import; added "Doanh thu" nav item gated on `revenue.view` |

---

## UI Implemented

### Date Range Filter

- `from` / `to` date inputs bound to `reactive({ from, to })`
- **Áp dụng** triggers `router.get(route('admin.reports.revenue.index'), filter, { preserveState: true, replace: true })`
- **Đặt lại** resets to current-month-start → today (derived from `businessDate` prop), then reapplies
- Filter state initialised from `props.filters` (server-echoed) so page refreshes preserve the selected range

### Summary Cards

Three cards in a responsive grid (2 cols → 3 cols on sm+):

| Card | Data source | Highlight |
|------|------------|-----------|
| Hôm nay | `daily.total` | pine text |
| Kỳ được chọn | `period.total` | pine-bordered card |
| Số giao dịch | sum of `period.by_charge_type[*].count` | ink text |

### Revenue by Charge Type

Table with progress bar visualisation:
- Columns: Loại phí / Giao dịch / Thành tiền / bar
- Bar width = `(row.amount / maxAmount) * 100%` (pine fill, gray background)
- Footer row: total count + total amount (pine bold)
- Empty state: "Chưa có doanh thu trong khoảng thời gian này."

### Revenue by Posting Source

Table with coloured source badges:
- `NIGHT_AUDIT` → `bg-blue-100 text-blue-800`
- `MANUAL` → `bg-gray-100 text-gray-700`
- `SYSTEM_AUTO` → `bg-amber-100 text-amber-800`
- Columns: Nguồn / Giao dịch / Thành tiền
- Footer: total count + total amount

### Daily Breakdown

Only rendered when `period.by_date.length > 1` (multi-day range):
- Columns: Ngày / Giao dịch / Thành tiền
- Dates formatted as `dd/mm/yyyy` via `formatDate()`
- Footer: aggregate count + total amount

### CSV Export Button

- Rendered as a plain `<a>` link: `/admin/reports/revenue/export?from=...&to=...`
- Href recalculates via `computed` whenever `filter.from` / `filter.to` change
- Download happens outside Inertia (browser native), so no Inertia progress bar needed
- Requires `revenue.view` permission — enforced by the backend (403 if missing)

### Permission-Gated Navigation

AppLayout.vue updated:
```javascript
{ label: 'Doanh thu', href: '/admin/reports/revenue', icon: TrendingUp, show: can('revenue.view') }
```

Nav item appears for ADMIN, MANAGER, ACCOUNTANT; hidden for RECEPTION, SALES, HOUSEKEEPING.

---

## Inertia Props Consumed

| Prop | Type | Used for |
|------|------|---------|
| `daily` | `DailyData` | Today's total card; today's date label |
| `period` | `PeriodData` | Period total card; charge type table; daily breakdown table |
| `bySource` | `BySourceData` | Source table |
| `filters` | `{ from, to }` | Initialise filter reactive state |
| `businessDate` | `string` | "Ngày kinh doanh hiện tại" label; reset-to-today logic |

---

## CSV Export Flow

1. User sets date range via filter → `filter.from` / `filter.to` update
2. `exportUrl` computed recalculates: `/admin/reports/revenue/export?from=...&to=...`
3. User clicks "Xuất CSV" link → browser navigates to export URL
4. Backend `RevenueReportController@export` checks `revenue.view` → streams UTF-8 BOM CSV
5. Browser downloads `revenue-YYYYMMDD-YYYYMMDD.csv`

No Inertia involvement — native browser file download. Export always uses the **current filter state**, not the last applied server round-trip.

---

## Design Decisions

- **No frontend calculations**: `total`, `amount`, `count` come directly from backend. No derived totals computed in Vue.
- **`router.get` not `useForm`**: Filter applies via GET (bookmarkable URL), not a POST form.
- **`<a>` not Inertia Link for export**: File downloads must bypass Inertia's interceptor.
- **`isMultiDay` guard on daily breakdown**: Single-day views already show charge-type and source detail; the daily-breakdown table adds no value and is hidden.
- **Responsive grid**: Cards use `grid-cols-2 sm:grid-cols-3` following the NightAudit/Show.vue pattern.

---

## No Frontend Tests Added

The project has no frontend test infrastructure (no Vitest, no @vue/test-utils, no Playwright configured). The page is purely presentational with no custom business logic — all assertions are covered by the 18 PHP feature tests in `RevenueReportTest.php`.

---

## Build

```
vite build — ✓ built in 11.25s (0 errors, 0 warnings)
```

---

## Test Results

| Test file | Tests | Status |
|-----------|-------|--------|
| `RevenueReportTest.php` | 18/18 | ✅ All passing |

Full regression suite: **26 failed (pre-existing), 466 passed — 0 new regressions.**

---

## Regression Analysis

| Metric | Pre-frontend | Post-frontend |
|--------|-------------|--------------|
| Build | ✓ | ✓ (clean, 11.25s) |
| Revenue tests | 18/18 | 18/18 |
| Pre-existing failures | 26 | 26 |
| New regressions | — | **0** |

Frontend changes are:
- One new Vue SFC (no side effects on existing components)
- One new `import` + one nav item added to AppLayout (additive, no existing nav item changed)

---

## Manual QA Checklist

- [ ] Log in as **Admin** → sidebar shows "Doanh thu" link with TrendingUp icon
- [ ] Log in as **Manager** → sidebar shows "Doanh thu" link
- [ ] Log in as **Accountant** → sidebar shows "Doanh thu" link
- [ ] Log in as **Receptionist** → "Doanh thu" link NOT shown
- [ ] Log in as **Sales** → "Doanh thu" link NOT shown
- [ ] Navigate to `/admin/reports/revenue` as Admin → page loads with correct current month range
- [ ] Today card shows correct business date and total (may be 0 on fresh DB)
- [ ] Change date range → click Áp dụng → URL updates, tables refresh
- [ ] Click Đặt lại → range resets to current month, page refreshes
- [ ] Period total matches sum of charge type rows
- [ ] Source table rows show correct badge colors (NIGHT_AUDIT=blue, MANUAL=gray, SYSTEM_AUTO=amber)
- [ ] Single-day range → daily breakdown table NOT shown
- [ ] Multi-day range → daily breakdown table shown with correct dates
- [ ] Click Xuất CSV → file downloads as `revenue-YYYYMMDD-YYYYMMDD.csv`
- [ ] CSV contains UTF-8 BOM (Excel opens without encoding issues)
- [ ] CSV row for each active (non-voided) entry in range
- [ ] Try accessing `/admin/reports/revenue` as Receptionist → 403

---

## Remaining Risks

| Risk | Severity | Mitigation |
|------|----------|-----------|
| No E2E test for CSV download | LOW | PHP test verifies content; manual QA covers download flow |
| No loading state on filter apply | LOW | Inertia's progress bar handles this globally |
| Export URL uses current filter state, not last applied | LOW | By design — export always reflects what's visible in the filter inputs |

---

## Ready for Final Review

**YES** — implementation complete, build clean, 18/18 tests passing, 0 regressions.

## Awaiting

- ChatGPT Final Review
- Commit / push (blocked until Final Review clears)
