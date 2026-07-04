# Phase 3.3.6.3 — Backend Report: PackageEnrollmentController + Routes

## Status

Implementation complete. Awaiting ChatGPT Backend Review.

---

## Files Changed

### Created

| File | Purpose |
|------|---------|
| `app/Http/Controllers/Admin/PackageEnrollmentController.php` | `show`, `enroll`, `unenroll` with permission guards |
| `tests/Feature/PackageEnrollmentControllerTest.php` | 12 HTTP-level tests |

### Modified

| File | Change |
|------|--------|
| `routes/web.php` | Added GET `admin.bookings.packages` route; updated POST + DELETE to point to `PackageEnrollmentController` |

---

## Implementation Notes

### Controller Design

`PackageEnrollmentController` lives in `App\Http\Controllers\Admin\` (admin-level, not the `Booking/` sub-namespace) because it owns a full Inertia page response in addition to mutation endpoints.

**`show()`:**
- Requires `folio.view` permission
- Loads `folio` and `stays` relations
- Calls `PackageEnrollmentService::getEnrollmentSummary()` for enrollment state
- Calls `buildAvailablePackages()` which resolves current rate per `ChargeType` via `ServiceRateService::resolveFor()`
- Passes `city_tax_enabled` from `HotelSettingsService` (read-only info for the UI)
- Returns `Admin/Booking/Packages` Inertia component (implemented in 3.3.6.4)

**`enroll()`:**
- Requires `booking.package.manage` permission
- Terminal booking guard (inline): aborts 403 for `CheckedOut`, `Cancelled`, `NoShow` statuses
- Validates `package_key` against `PackageEnrollmentService::ALLOWED_PACKAGES` whitelist
- `quantity` is **optional** (default 1) for backward compatibility with existing `BookingPackageEnrollmentTest` calls that omit it
- Calls `enrollmentService->enroll($booking, $key, $user, $quantity)`

**`unenroll()`:**
- Requires `booking.package.manage` permission
- Validates `$packageKey` route segment against `ALLOWED_PACKAGES` whitelist (critical security gate)
- Catches both `BreakfastAlreadyPostedException` and `PackageAlreadyPostedException` → `back()->withErrors(['package' => ...])`

**`buildAvailablePackages()`:**
- Private helper; resolves `ServiceRate` for each package's `ChargeType` on the current business date
- Returns `current_rate: float|null` — null when no active rate exists for the date
- Package-to-ChargeType map: BREAKFAST → FoodBeverage, EXTRA_PERSON → ExtraPerson, EXTRA_BED → ExtraBed

### Routes

| Method | URI | Name | Controller |
|--------|-----|------|-----------|
| GET | `admin/bookings/{booking}/packages` | `admin.bookings.packages` | `PackageEnrollmentController@show` (NEW) |
| POST | `admin/bookings/{booking}/packages` | `admin.bookings.packages.enroll` | `PackageEnrollmentController@enroll` (updated) |
| DELETE | `admin/bookings/{booking}/packages/{packageKey}` | `admin.bookings.packages.unenroll` | `PackageEnrollmentController@unenroll` (updated) |

The existing `BookingPackageController` is preserved (not deleted). The POST/DELETE routes were updated from `BookingPackageController` to `PackageEnrollmentController`.

### Backward Compatibility

The existing `BookingPackageEnrollmentTest` (8 tests) continues to pass without modification:
- Same route names (`admin.bookings.packages.enroll`, `admin.bookings.packages.unenroll`)
- `quantity` is optional — existing callers that omit it receive `quantity=1` by default
- Permission check (`booking.package.manage`) is equivalent to the previous Policy-based `authorize('enroll', BookingPackageFlag::class)` — both delegate to the same permission
- `BreakfastAlreadyPostedException` is now caught explicitly (previously propagated to its `render()` method); behavior is identical

### Permission

`booking.package.manage` already exists in `RolePermissionSeeder` and is assigned to ADMIN and MANAGER roles. No seeder changes needed.

### Notable Decisions

- **`quantity` validation**: `sometimes` instead of `required` preserves backward compat. The service enforces `max(1, $quantity)` anyway.
- **`component(false)` in tests**: The Vue file `Admin/Booking/Packages.vue` doesn't exist until 3.3.6.4. Using `->component('Admin/Booking/Packages', false)` skips Inertia's page-existence check in backend-only tests.
- **`current_rate` type**: Controller casts to `(float)`, which JSON serializes as integer `200000` for whole numbers. Test asserts `200000` (int) to match the actual deserialized type.

---

## Test Results

### New Tests

```
PASS  Tests\Feature\PackageEnrollmentControllerTest — 12 tests passed (62 assertions)
Duration: 3.86s

Tests:
✓ admin_can_view_packages_page
✓ reception_can_view_packages_page_read_only
✓ unauthenticated_redirected_to_login
✓ admin_can_enroll_breakfast
✓ admin_can_enroll_extra_person_with_quantity_2
✓ admin_can_enroll_extra_bed
✓ reception_cannot_enroll_package
✓ enroll_rejects_invalid_package_key
✓ enroll_rejects_quantity_zero
✓ admin_can_unenroll_package_when_not_yet_posted_today
✓ unenroll_returns_error_when_already_posted_today
✓ show_page_includes_current_rate_when_rate_exists
```

### Phase 3.3.x Regression (178 total)

```
Tests: 178 passed (606 assertions)   Duration: 36.44s

Included:
- PackageEnrollmentControllerTest (12 new)
- BookingPackageEnrollmentTest (8 existing — all pass, backward compat confirmed)
- ExtraPersonPostingJobTest (9), ExtraBedPostingJobTest (9)
- PackageEnrollmentServiceTest (4), CityTaxPostingJobTest (9)
- BreakfastPostingJobFeatureTest (13), PostingTimelineTest (18)
- NightAuditOperationsServiceTest, ServiceRateCrudTest, ServiceRateVersioningTest,
  ReconciliationTest, RevenueReportTest (all passing)
```

Zero regressions. Pre-existing 26 failures in `RoomAvailabilityCheckerTest`, `BookingManagementUiTest`, `DashboardTest` remain unchanged — unrelated to this sub-task.

---

## Security Checklist

- [x] `package_key` validated against `ALLOWED_PACKAGES` whitelist in both `enroll()` and `unenroll()`
- [x] `booking.package.manage` permission gates all mutation endpoints
- [x] `folio.view` permission gates read-only `show()` endpoint
- [x] Terminal booking guard prevents enrollment on completed bookings
- [x] `PackageAlreadyPostedException` message exposed only as a user-visible validation error (no stack trace)
- [x] No hardcoded secrets or credentials
- [x] `packageKey` route segment validated server-side (not trusted from URL)

---

## Ready for ChatGPT Backend Review

YES — 12/12 new tests passing, 178/178 Phase 3.3.x regression passing, zero regressions.

**Awaiting ChatGPT Backend Review before proceeding to sub-task 3.3.6.4.**
