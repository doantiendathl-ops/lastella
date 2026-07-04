# Phase 3.3.6.2 — Backend Report: ExtraPersonPostingJob + ExtraBedPostingJob + PackageEnrollmentService

## Status

Implementation complete. Awaiting ChatGPT Backend Review.

---

## Files Changed

### Modified

| File | Change |
|------|--------|
| `app/Services/PackageEnrollmentService.php` | New constants, updated `enroll()`, extended `unenroll()` guard, new `getEnrollmentSummary()` |
| `app/Services/NightAuditService.php` | Injected `ExtraPersonPostingJob` and `ExtraBedPostingJob`; registered in pipeline |
| `database/seeders/ServiceRateSeeder.php` | Added EXTRA_PERSON and EXTRA_BED default rates |

### Created

| File | Purpose |
|------|---------|
| `app/Exceptions/PackageAlreadyPostedException.php` | Generic package-posted guard exception with `render()` → `back()->withErrors(['package' => ...])` |
| `app/Services/Posting/ExtraPersonPostingJob.php` | Per-night extra person charge PostingJob |
| `app/Services/Posting/ExtraBedPostingJob.php` | Per-night extra bed charge PostingJob |
| `tests/Feature/ExtraPersonPostingJobTest.php` | 9 tests |
| `tests/Feature/ExtraBedPostingJobTest.php` | 9 tests |
| `tests/Feature/PackageEnrollmentServiceTest.php` | 4 service-level tests |

---

## Implementation Notes

### PackageEnrollmentService Changes

**New constants:**
```php
const EXTRA_PERSON_PER_NIGHT = 'EXTRA_PERSON_PER_NIGHT';
const EXTRA_BED_PER_NIGHT    = 'EXTRA_BED_PER_NIGHT';
const ALLOWED_PACKAGES       = [BREAKFAST_PER_NIGHT, EXTRA_PERSON_PER_NIGHT, EXTRA_BED_PER_NIGHT];
```

**`enroll()` signature (ADR-80 quantity support, backward-compatible):**
```php
public function enroll(Booking $booking, string $packageKey, ?User $enrolledBy = null, int $quantity = 1): BookingPackageFlag
```
`?User` stays 3rd argument to preserve the existing `BookingPackageController` call: `enroll($booking, $key, $request->user())`. The new controller (3.3.6.3) will use `enroll($booking, $key, $user, $quantity)`. Quantity is stored as a string in `BookingPackageFlag.value`.

`firstOrCreate` → `updateOrCreate`: re-enrolling the same package with a new quantity correctly updates `value` without creating duplicates.

**`unenroll()` guard extension:**

Uses a `match` expression routing to:
- `BREAKFAST_PER_NIGHT` → existing `guardBreakfastAlreadyPosted()` (throws `BreakfastAlreadyPostedException`) — unchanged
- `EXTRA_PERSON_PER_NIGHT` → new `guardAlreadyPostedByChargeType(ChargeType::ExtraPerson)` (throws `PackageAlreadyPostedException`)
- `EXTRA_BED_PER_NIGHT` → new `guardAlreadyPostedByChargeType(ChargeType::ExtraBed)`
- `default` → no guard (unknown keys silently pass; the controller validates the key whitelist)

Guard logic: checks `folio_entries` for `charge_type` + `posting_source=NIGHT_AUDIT` + `entry_date=businessDate` + `voided_at IS NULL`. Same pattern as the breakfast guard.

**`getEnrollmentSummary(Booking): array`:**
Returns all three packages with `enrolled`, `quantity`, `enrolled_at`, `enrolled_by` keys. Non-enrolled packages default to `quantity = 1`. Eager-loads `createdBy` relation.

### ExtraPersonPostingJob / ExtraBedPostingJob

Both follow the `BreakfastPostingJob` canonical pattern exactly. Key differences:

| | ExtraPersonPostingJob | ExtraBedPostingJob |
|-|----------------------|-------------------|
| Package key | `EXTRA_PERSON_PER_NIGHT` | `EXTRA_BED_PER_NIGHT` |
| ChargeType | `ChargeType::ExtraPerson` | `ChargeType::ExtraBed` |
| Posting key | `EXTRA_PERSON_{stay_id}_{YYYY-MM-DD}` | `EXTRA_BED_{stay_id}_{YYYY-MM-DD}` |
| Quantity | Read from `BookingPackageFlag.value` (ADR-80) | Read from `BookingPackageFlag.value` |
| Amount | `bcmul(unit_price, quantity, 2)` | `bcmul(unit_price, quantity, 2)` |
| `dependsOn()` | `[RoomChargePostingJob::class]` | `[RoomChargePostingJob::class]` |

Both re-read the flag inside `execute()` to get the quantity, in addition to the `shouldProcess()` enrollment check. If the flag is deleted between `shouldProcess()` and `execute()`, `execute()` returns `skipped` rather than throwing.

### NightAuditService Registration Order

```
RoomChargePostingJob   (existing)
BreakfastPostingJob    (existing)
ExtraPersonPostingJob  (NEW)
ExtraBedPostingJob     (NEW)
CityTaxPostingJob      (added in 3.3.6.1)
```

### Signature Fix (Notable)

Initial implementation used `enroll(Booking, string, int, ?User)`. This broke the existing `BookingPackageController` which calls `enroll($booking, $key, $request->user())`. The type mismatch (User passed as int) caused a PHP TypeError at runtime, silently redirected by Laravel's exception handler without creating the flag.

Fix: Swapped to `enroll(Booking, string, ?User = null, int = 1)`. Existing controller call unchanged. New quantity-aware calls use named argument syntax: `enroll($booking, $key, quantity: 3)`.

---

## Test Results

### New Tests

```
PASS  Tests\Feature\ExtraPersonPostingJobTest   — 9 tests passed
PASS  Tests\Feature\ExtraBedPostingJobTest       — 9 tests passed
PASS  Tests\Feature\PackageEnrollmentServiceTest — 4 tests passed

Tests: 22 passed (48 assertions)   Duration: 5.45s
```

### Phase 3.3.x Regression (166 total)

```
Tests: 166 passed (544 assertions)   Duration: 27.43s

Included:
- ExtraPersonPostingJobTest (9 new)
- ExtraBedPostingJobTest (9 new)
- PackageEnrollmentServiceTest (4 new)
- BookingPackageEnrollmentTest (8 existing — all pass, including controller tests)
- CityTaxPostingJobTest (9 from 3.3.6.1)
- BreakfastPostingJobFeatureTest (13 existing — unchanged)
- PostingTimelineTest (18 existing — unchanged)
- NightAuditOperationsServiceTest, ServiceRateCrudTest, ServiceRateVersioningTest,
  ReconciliationTest, RevenueReportTest (all passing)
```

Zero regressions. The pre-existing 26 failures in `RoomAvailabilityCheckerTest`, `BookingManagementUiTest`, `DashboardTest` remain unchanged — unrelated to this sub-task.

---

## Security Checklist

- [x] No hardcoded secrets or credentials
- [x] `PackageAlreadyPostedException` exposes no sensitive data in its message
- [x] `unenroll()` guard prevents removing enrollment after same-night charge posted
- [x] `enroll()` uses `max(1, $quantity)` — minimum 1 enforced at service layer
- [x] `updateOrCreate` does not create duplicates (UNIQUE constraint on `booking_id + package_key`)
- [x] Both PostingJobs use `lockForUpdate` + posting_key UNIQUE constraint (two-layer idempotency)
- [x] `getEnrollmentSummary()` is read-only — no mutation possible

---

## Ready for ChatGPT Backend Review

YES — 22/22 new tests passing, 166/166 Phase 3.3.x regression passing, zero regressions.

**Awaiting ChatGPT Backend Review before proceeding to sub-task 3.3.6.3.**
