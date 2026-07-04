# Phase 3.3.6.1 — Backend Report: ChargeType Extension & CityTaxPostingJob

## Status

Implementation complete. Awaiting ChatGPT Backend Review.

---

## Files Changed

### Modified

| File | Change |
|------|--------|
| `app/Enums/ChargeType.php` | Added `case CityTax = 'CITY_TAX'` and label `'Thuế du lịch'` in `label()` match |
| `app/Services/HotelSettingsService.php` | Added `city_tax_enabled` (bool, default `false`) and `city_tax_quantity` (int, default `1`) to `$defaults` |
| `app/Services/NightAuditService.php` | Injected `CityTaxPostingJob $cityTaxJob`, registered via `->register($this->cityTaxJob)` after `$breakfastJob` |
| `database/seeders/DatabaseSeeder.php` | Added `ServiceRateSeeder::class` to seeder call list |

### Created

| File | Purpose |
|------|---------|
| `app/Services/Posting/CityTaxPostingJob.php` | New PostingJob for hotel-wide city tax automation |
| `database/seeders/ServiceRateSeeder.php` | Optional seeder: inserts a default CITY_TAX rate if none exists |
| `tests/Feature/CityTaxPostingJobTest.php` | 9 feature tests covering shouldProcess, execute, and pipeline registration |

---

## Implementation Notes

### Medium Review Improvements Applied

**#1 — Configurable quantity:**
`CityTaxPostingJob` reads `city_tax_quantity` from `HotelSettingsService` (int, default 1). `max(1, ...)` guard prevents invalid zero/negative values. The quantity is reflected in the `quantity` field of `FolioEntry` and used in `bcmul(unit_price, quantity, 2)` for the `amount` field.

`city_tax_quantity` is now surfaced as a proper hotel setting with description "Số đơn vị thuế du lịch mỗi đêm" — configurable via the existing Hotel Settings UI without code changes.

**#3 — Dynamic seeder date:**
`ServiceRateSeeder` uses `Carbon::today()->toDateString()` for `effective_from`. No hardcoded 2024 dates.

**#4 — No PackageType enum:**
Scope unchanged. No new abstractions introduced.

### Posting Key Format (ADR-81)

```
CITY_TAX_{stay_id}_{YYYY-MM-DD}
```

Example: `CITY_TAX_42_2026-07-04`

### shouldProcess Logic

1. `$context->stay === null` → false (no stay, no tax)
2. `$context->folio->status->value !== 'OPEN'` → false (closed folio)
3. `HotelSettingsService::getBool('city_tax_enabled', false)` → the master switch

Default state: `city_tax_enabled = false` — no CITY_TAX entries are posted on a fresh installation until an admin explicitly enables the setting. Zero regression risk for existing deployments.

### Idempotency

Two-layer defence (unchanged from existing PostingJob pattern):
1. `isAlreadyPosted()` pre-check — exits early without entering a transaction
2. `FolioEntry::lockForUpdate()` + `UNIQUE(posting_key)` inside the transaction — database-level backstop

### NightAuditService Registration Order

```
RoomChargePostingJob   (existing)
BreakfastPostingJob    (existing)
CityTaxPostingJob      (NEW — depends on RoomChargePostingJob)
```

`dependsOn()` returns `[RoomChargePostingJob::class]`. The pipeline enforces this ordering.

---

## Test Results

### New Tests

```
PASS  Tests\Feature\CityTaxPostingJobTest
✓ should process returns false when city tax disabled              1.22s
✓ should process returns false when folio not open                0.19s
✓ should process returns false when no stay in context            0.17s
✓ should process returns true when enabled and folio open         0.17s
✓ execute skips when no active city tax rate                      0.18s
✓ execute posts entry with correct fields and posting key         0.20s
✓ execute amount uses configured quantity                         0.16s
✓ execute is idempotent on retry                                  0.19s
✓ night audit service registers city tax job                      0.21s

Tests: 9 passed (16 assertions)   Duration: 3.02s
```

### Phase 3.3.x Regression

All existing Phase 3.3.x tests pass with no changes:

```
Tests: 136 passed (477 assertions)   Duration: 25.69s

Included test files:
- CityTaxPostingJobTest (9 new)
- BreakfastPostingJobFeatureTest (13 existing, unchanged)
- PostingTimelineTest (18 existing, unchanged)
- ServiceRateCrudTest (existing)
- ServiceRateVersioningTest (existing)
- NightAuditOperationsServiceTest (existing)
- ReconciliationTest (existing)
- RevenueReportTest (existing)
```

### Full Suite

```
Tests: 26 failed, 517 passed (2658 assertions)   Duration: 160.21s
```

The 26 failures are **pre-existing** and **unrelated to Phase 3.3.6.1**:

| Test file | Failures | Cause |
|-----------|---------|-------|
| `RoomAvailabilityCheckerTest` | 12 | Room availability date logic (pre-existing) |
| `BookingManagementUiTest` | 13 | Booking UI room board assertions (pre-existing) |
| `DashboardTest` | 1 | Dashboard room count (pre-existing) |

None of the failing tests involve ChargeType, HotelSettingsService, NightAuditService, or FolioEntry. Zero regressions introduced by Phase 3.3.6.1.

---

## Security Checklist

- [x] No hardcoded secrets or credentials
- [x] `city_tax_enabled` defaults to `false` — safe default, opt-in only
- [x] `city_tax_quantity` minimum clamped to `1` in `CityTaxPostingJob::execute()`
- [x] PostingJob uses `lockForUpdate` pattern (ADR-12 lock order honoured)
- [x] `posting_key UNIQUE` constraint protects against double-posting
- [x] No user input in `CityTaxPostingJob` — all values from trusted hotel settings and service rates
- [x] `ServiceRateSeeder` skips insert if CITY_TAX rate already exists — no duplicates on re-seed

---

## Ready for ChatGPT Backend Review

YES — implementation complete, 9/9 new tests passing, 136/136 Phase 3.3.x regression tests passing, zero regressions introduced.

**Awaiting ChatGPT Backend Review before proceeding to sub-task 3.3.6.2.**
