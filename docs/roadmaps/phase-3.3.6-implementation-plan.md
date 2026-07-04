# Phase 3.3.6 — Implementation Plan: Extra Charges Pipeline & Package Enrollment UI

**Architecture document:** `docs/roadmaps/phase-3.3.6-extra-charges-pipeline.md`  
**Status:** Awaiting ChatGPT Architecture Review  
**Estimated size:** ~1,800 lines total (PHP: ~1,200 / Vue: ~400 / tests: ~200 lines assertions)  
**Estimated sub-tasks:** 5 (sequential by dependency)

---

## Sub-task Dependencies

```
3.3.6.1 (ChargeType + CityTaxPostingJob)
    └── 3.3.6.2 (ExtraPersonPostingJob + ExtraBedPostingJob + PackageEnrollmentService)
          └── 3.3.6.3 (PackageEnrollmentController + Routes + Policy)
                └── 3.3.6.4 (Packages.vue)
                      └── 3.3.6.5 (Night Audit Show enhancement)

All sub-tasks build on 3.3.6.1. 3.3.6.3 requires 3.3.6.2 for the enrollment service.
3.3.6.4 requires 3.3.6.3 for the routes. 3.3.6.5 requires 3.3.6.2 for the new job_class names.
```

---

## Sub-task 3.3.6.1 — ChargeType Extension & CityTaxPostingJob

### Goal

Extend the ChargeType enum with CITY_TAX, implement CityTaxPostingJob following the BreakfastPostingJob pattern, register it in NightAuditService, and seed the CITY_TAX ServiceRate catalog entry.

### Files to Create/Modify

| File | Action | Change |
|------|--------|--------|
| `app/Enums/ChargeType.php` | MODIFY | Add `case CityTax = 'CITY_TAX';` and label `'Thuế du lịch'` |
| `app/Services/Posting/CityTaxPostingJob.php` | CREATE | Full PostingJob implementation |
| `app/Services/NightAuditService.php` | MODIFY | Inject CityTaxPostingJob, register in pipeline |
| `database/seeders/ServiceRateSeeder.php` | MODIFY | Add CITY_TAX seed entry (unit_price = 50000, effective_from = current date) |
| `tests/Feature/NightAudit/CityTaxPostingJobTest.php` | CREATE | 8 tests |

### Implementation Contract

**`app/Enums/ChargeType.php`:**
```
case CityTax = 'CITY_TAX';
// label(): 'Thuế du lịch'
// Place after AIRPORT_TRANSFER alphabetically
```

**`CityTaxPostingJob::shouldProcess()`:**
```
- Requires folio.status = 'OPEN'
- Requires context->stay !== null
- Requires HotelSettingsService::getBool('city_tax_enabled') === true
- Returns false otherwise (no exception, just skips)
```

**`CityTaxPostingJob::execute()`:**
```
- Resolve rate: ServiceRateService::resolveFor(ChargeType::CityTax, businessDate)
- If null rate: return PostingResult::skipped('Chưa có biểu giá CITY_TAX cho ngày này')
- Posting key: "CITY_TAX_{$context->stay->id}_{$context->businessDate->toDateString()}"
- quantity: '1.00' (fixed; per-person extension deferred per ADR-79)
- amount: unit_price (bcmul with '1.00')
- posting_source: 'NIGHT_AUDIT'
- description: "Thuế du lịch đêm {DD/MM/YYYY}"
- posted_by: null (system entry)
```

**`NightAuditService` update:**
```
- DI: add CityTaxPostingJob $cityTaxJob to constructor
- After breakfastJob registration:
    ->register($this->cityTaxJob)
```

**`ServiceRateSeeder`:**
```
Add if not exists:
  charge_type: 'CITY_TAX'
  name: 'Thuế du lịch (2024)'
  unit_price: 50000
  effective_from: 2024-01-01
  is_active: true
  tax_rate: 0
  gl_account_code: null
  created_by: system_user_id
```

### Tests (8 required)

```
CityTaxPostingJobTest::test_skips_when_folio_closed
CityTaxPostingJobTest::test_skips_when_city_tax_disabled_in_settings
CityTaxPostingJobTest::test_skips_when_no_stay_in_context
CityTaxPostingJobTest::test_skips_when_no_active_rate_for_date
CityTaxPostingJobTest::test_posts_entry_with_correct_fields
CityTaxPostingJobTest::test_returns_already_posted_when_key_exists
CityTaxPostingJobTest::test_idempotent_on_retry_via_posting_key_unique
CityTaxPostingJobTest::test_night_audit_pipeline_registers_city_tax_job
```

### Complexity: MEDIUM

City tax introduces no new patterns — it mirrors BreakfastPostingJob exactly. Only difference: `shouldProcess` checks hotel setting instead of package flag. The only risk is the `HotelSettingsService::getBool()` helper: confirm this method exists and returns boolean default false for unknown keys.

### Review Checkpoint

After 3.3.6.1: Run `php artisan test --filter=CityTaxPostingJob` (8 tests pass). Run full regression: 155 + 8 = 163 tests should pass. Confirm no ChargeType::CityTax case breaks any existing match/switch statement — grep for match on ChargeType in codebase.

---

## Sub-task 3.3.6.2 — ExtraPersonPostingJob + ExtraBedPostingJob + PackageEnrollmentService

### Goal

Add two new package keys to PackageEnrollmentService, extend the unenroll guard for the new packages, implement both PostingJobs (ExtraPerson and ExtraBed), and register them in NightAuditService.

### Files to Create/Modify

| File | Action | Change |
|------|--------|--------|
| `app/Services/PackageEnrollmentService.php` | MODIFY | New constants, enroll() quantity support, unenroll() guard extension, getEnrollmentSummary() method |
| `app/Services/Posting/ExtraPersonPostingJob.php` | CREATE | PostingJob for EXTRA_PERSON |
| `app/Services/Posting/ExtraBedPostingJob.php` | CREATE | PostingJob for EXTRA_BED |
| `app/Services/NightAuditService.php` | MODIFY | Inject + register ExtraPersonPostingJob, ExtraBedPostingJob |
| `database/seeders/ServiceRateSeeder.php` | MODIFY | Add EXTRA_PERSON and EXTRA_BED seed entries |
| `tests/Feature/NightAudit/ExtraPersonPostingJobTest.php` | CREATE | 8 tests |
| `tests/Feature/NightAudit/ExtraBedPostingJobTest.php` | CREATE | 8 tests |
| `tests/Feature/PackageEnrollmentServiceTest.php` | MODIFY | 4 new tests for new methods |

### Implementation Contract

**`PackageEnrollmentService` additions:**
```php
const EXTRA_PERSON_PER_NIGHT = 'EXTRA_PERSON_PER_NIGHT';
const EXTRA_BED_PER_NIGHT    = 'EXTRA_BED_PER_NIGHT';

const ALLOWED_PACKAGES = [
    self::BREAKFAST_PER_NIGHT,
    self::EXTRA_PERSON_PER_NIGHT,
    self::EXTRA_BED_PER_NIGHT,
];

// enroll() signature — add $quantity = 1 with default (backward compatible)
// Stores intval(max(1, $quantity)) as string in $flag->value
// If flag already exists: update value if quantity changed (not firstOrCreate alone)

// unenroll() — extend guardAlreadyPostedToday() to handle new keys
// Map EXTRA_PERSON_PER_NIGHT → 'EXTRA_PERSON_{stay_id}_{today}'
//     EXTRA_BED_PER_NIGHT   → 'EXTRA_BED_{stay_id}_{today}'
// Guard fires only if stay exists for booking

// getEnrollmentSummary(Booking): array
// Returns: [
//   'BREAKFAST_PER_NIGHT' => [
//     'enrolled' => bool,
//     'quantity' => int,
//     'enrolled_at' => string|null (formatted),
//     'enrolled_by' => string|null (user name),
//   ],
//   'EXTRA_PERSON_PER_NIGHT' => [...],
//   'EXTRA_BED_PER_NIGHT' => [...],
// ]
```

**`ExtraPersonPostingJob::execute()`:**
```
- Read flag: BookingPackageFlag where booking_id, package_key = 'EXTRA_PERSON_PER_NIGHT'
- quantity = max(1, intval($flag->value ?? '1'))
- Posting key: "EXTRA_PERSON_{$context->stay->id}_{$context->businessDate->toDateString()}"
- charge_type: ChargeType::ExtraPerson (existing enum case)
- amount: bcmul(unit_price, (string) $quantity, 2)
- description: "Người thêm ({$quantity} người) đêm {DD/MM/YYYY}"
- posting_source: 'NIGHT_AUDIT'
```

**`ExtraBedPostingJob` (identical pattern):**
```
- package_key: 'EXTRA_BED_PER_NIGHT'
- posting_key prefix: 'EXTRA_BED'
- charge_type: ChargeType::ExtraBed (existing enum case)
- description: "Giường phụ ({$quantity} giường) đêm {DD/MM/YYYY}"
```

**`NightAuditService` update:**
```
- Add ExtraPersonPostingJob, ExtraBedPostingJob to constructor DI
- Register after breakfastJob (before cityTaxJob):
    ->register($this->extraPersonJob)
    ->register($this->extraBedJob)
    ->register($this->cityTaxJob)
```

**ServiceRate seeds:**
```
EXTRA_PERSON: unit_price = 200000, name = 'Người thêm / đêm'
EXTRA_BED:    unit_price = 150000, name = 'Giường phụ / đêm'
effective_from: 2024-01-01 for both
```

### Tests (16 + 4 = 20 new)

**ExtraPersonPostingJobTest (8):**
```
test_skips_when_not_enrolled
test_skips_when_folio_closed
test_skips_when_no_active_rate
test_posts_with_quantity_1_when_value_is_1
test_posts_with_quantity_2_when_value_is_2 (amount = 2 × unit_price)
test_returns_already_posted_when_key_exists
test_idempotent_on_retry
test_description_includes_quantity_and_date
```

**ExtraBedPostingJobTest (8): identical structure**

**PackageEnrollmentServiceTest additions (4):**
```
test_enroll_stores_quantity_in_value_field
test_enroll_updates_quantity_on_re_enroll
test_unenroll_blocked_for_extra_person_when_already_posted_today
test_get_enrollment_summary_returns_all_three_packages
```

### Complexity: LOW-MEDIUM

Both PostingJobs follow the established BreakfastPostingJob pattern. The only novel element is reading `value` as quantity. Highest risk: the `guardAlreadyPostedToday()` extension in `unenroll()` — must handle the case where no active stay exists (booking not checked in) gracefully (skip the guard).

### Review Checkpoint

After 3.3.6.2: Run `php artisan test --filter="ExtraPersonPosting|ExtraBedPosting|PackageEnrollment"`. Total new tests: 20. Full regression: 163 + 20 = 183 tests pass. Confirm `BreakfastPostingJob` tests still pass (existing guard unchanged).

---

## Sub-task 3.3.6.3 — PackageEnrollmentController + Routes + Permission

### Goal

Create the PackageEnrollmentController with show/enroll/unenroll endpoints, add the three routes to `routes/web.php`, seed the `package.manage` permission and assign it to ADMIN and MANAGER roles.

### Files to Create/Modify

| File | Action | Change |
|------|--------|--------|
| `app/Http/Controllers/Admin/PackageEnrollmentController.php` | CREATE | show, enroll, unenroll |
| `routes/web.php` | MODIFY | 3 new routes under admin group |
| `database/seeders/RolePermissionSeeder.php` | MODIFY | Add `package.manage` permission, assign to ADMIN, MANAGER |
| `tests/Feature/PackageEnrollmentControllerTest.php` | CREATE | 11 tests |

### Implementation Contract

**Routes:**
```php
// Inside admin route group, after existing booking routes:
Route::get('bookings/{booking}/packages', [PackageEnrollmentController::class, 'show'])
     ->name('admin.bookings.packages');
Route::post('bookings/{booking}/packages', [PackageEnrollmentController::class, 'enroll'])
     ->name('admin.bookings.packages.enroll');
Route::delete('bookings/{booking}/packages/{packageKey}', [PackageEnrollmentController::class, 'unenroll'])
     ->name('admin.bookings.packages.unenroll');
```

**`PackageEnrollmentController::show()`:**
```php
public function show(Request $request, Booking $booking): Response
{
    abort_unless($request->user()->can('folio.view'), 403);

    $booking->load(['folio', 'stays']);

    // Last Night Audit logs for this booking, one per job_class, ordered desc
    $lastAuditLogs = NightAuditBookingLog::where('booking_id', $booking->id)
        ->whereIn('job_class', [
            ExtraPersonPostingJob::class,
            ExtraBedPostingJob::class,
            BreakfastPostingJob::class,
        ])
        ->orderByDesc('created_at')
        ->get()
        ->unique('job_class')
        ->map(fn ($log) => [
            'job_class' => $log->job_class,
            'result' => $log->result,
            'posted_at' => $log->created_at->format('d/m/Y H:i'),
        ])
        ->values();

    // Available packages with current rate
    $availablePackages = $this->buildAvailablePackages($booking);

    return Inertia::render('Admin/Booking/Packages', [
        'booking' => [
            'id' => $booking->id,
            'booking_code' => $booking->booking_code,
            'customer_name' => $booking->customer_name,
            'status' => $booking->status,
        ],
        'enrollments' => $this->enrollmentService->getEnrollmentSummary($booking),
        'available_packages' => $availablePackages,
        'last_audit_logs' => $lastAuditLogs,
        'can' => [
            'manage_packages' => $request->user()->can('package.manage'),
        ],
    ]);
}
```

**`PackageEnrollmentController::enroll()`:**
```php
public function enroll(Request $request, Booking $booking): RedirectResponse
{
    abort_unless($request->user()->can('package.manage'), 403);
    abort_if($booking->isTerminal(), 403, 'Đặt phòng đã kết thúc.');

    $data = $request->validate([
        'package_key' => ['required', 'string', Rule::in(PackageEnrollmentService::ALLOWED_PACKAGES)],
        'quantity' => ['required', 'integer', 'min:1', 'max:4'],
    ]);

    $this->enrollmentService->enroll(
        $booking,
        $data['package_key'],
        $data['quantity'],
        $request->user()
    );

    $this->auditLog->log(/* ... */);

    return back()->with('success', 'Đã đăng ký gói dịch vụ.');
}
```

**`PackageEnrollmentController::unenroll()`:**
```php
public function unenroll(Request $request, Booking $booking, string $packageKey): RedirectResponse
{
    abort_unless($request->user()->can('package.manage'), 403);

    $validated = validator(['package_key' => $packageKey], [
        'package_key' => ['required', 'string', Rule::in(PackageEnrollmentService::ALLOWED_PACKAGES)],
    ])->validate();

    try {
        $this->enrollmentService->unenroll($booking, $packageKey);
    } catch (PackageAlreadyPostedException $e) {
        return back()->withErrors(['package' => 'Gói đã được ghi phí hôm nay. Hủy đăng ký từ ngày mai.']);
    }

    $this->auditLog->log(/* ... */);

    return back()->with('success', 'Đã hủy đăng ký gói dịch vụ.');
}
```

**Permission seeder:**
```
Insert permission: name = 'package.manage', description = 'Quản lý gói dịch vụ đặt phòng'
Assign to: ADMIN, MANAGER roles
```

**Note on `Booking::isTerminal()`:** Confirm this method exists. If not, inline the check:
```php
abort_if(in_array($booking->status->value, ['CANCELLED', 'NO_SHOW', 'CHECKED_OUT']), 403, '...');
```

### Tests (11 required)

```
test_admin_can_view_packages_page
test_reception_can_view_packages_page_read_only
test_unauthenticated_redirected_to_login
test_admin_can_enroll_breakfast
test_admin_can_enroll_extra_person_with_quantity_2
test_admin_can_enroll_extra_bed
test_reception_cannot_enroll_package (403)
test_enroll_rejects_invalid_package_key
test_enroll_rejects_quantity_zero
test_admin_can_unenroll_package_when_not_yet_posted_today
test_unenroll_returns_error_when_already_posted_today
```

### Complexity: MEDIUM

Standard Inertia controller. Main risk: ensuring `PackageAlreadyPostedException` is a named exception class (create it in `app/Exceptions/` if not already there). The `packageKey` as a route segment (DELETE route) must be validated server-side — the `Rule::in(ALLOWED_PACKAGES)` whitelist is the critical security gate.

### Review Checkpoint

After 3.3.6.3: Run `php artisan test --filter=PackageEnrollmentController`. Run security-reviewer agent on `PackageEnrollmentController.php`. Specifically verify: package_key whitelist enforced, terminal booking guard active, `package.manage` permission correctly gates mutations.

---

## Sub-task 3.3.6.4 — Admin/Booking/Packages.vue

### Goal

Build the Package Management Vue page. Read-only view for RECEPTION/ACCOUNTANT; interactive toggles for ADMIN/MANAGER. No new backend changes in this sub-task.

### Files to Create/Modify

| File | Action | Change |
|------|--------|--------|
| `resources/js/Pages/Admin/Booking/Packages.vue` | CREATE | Full SFC |
| `resources/js/Pages/Admin/Booking/Show.vue` | MODIFY | Add "Gói dịch vụ" link in quick actions or tab area |

### Props Contract

```typescript
interface EnrollmentStatus {
    enrolled: boolean
    quantity: number
    enrolled_at: string | null
    enrolled_by: string | null
}

interface AvailablePackage {
    key: string                // 'BREAKFAST_PER_NIGHT' etc.
    label: string              // 'Ăn sáng mỗi đêm'
    charge_label: string       // 'Ăn & uống'
    current_rate: number | null
}

interface AuditLog {
    job_class: string
    result: string             // 'POSTED' | 'SKIPPED' | 'FAILED'
    posted_at: string
}

// Props:
booking: { id: number, booking_code: string, customer_name: string, status: string }
enrollments: Record<string, EnrollmentStatus>
available_packages: AvailablePackage[]
last_audit_logs: AuditLog[]
can: { manage_packages: boolean }
```

### UI Specification

**Package card layout (one card per available package):**
```
┌────────────────────────────────────────────────────────┐
│ [Package Label]               [Current Rate / "Chưa có"]│
│ [Charge type label]                                      │
│                                                          │
│ ● Đã đăng ký  ·  2 người  ·  Đăng ký: 01/06/2024       │  ← if enrolled
│   Đăng ký bởi: Nguyễn A                                 │
│   Lần ghi phí cuối: 29/06/2024 22:05 (POSTED)           │  ← from last_audit_logs
│                                                          │
│ [Hủy đăng ký]                                           │  ← if can.manage_packages
└────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────┐
│ [Package Label]               [Current Rate / "Chưa có"]│
│ [Charge type label]                                      │
│                                                          │
│ ○ Chưa đăng ký                                          │  ← if not enrolled
│                                                          │
│ [Quantity: 1 ↓]  [Đăng ký]                              │  ← if can.manage_packages
└────────────────────────────────────────────────────────┘
```

**Key behaviors:**
- Quantity selector: number input `min="1" max="4"` default 1
- Only ExtraPerson and ExtraBed show quantity input (Breakfast is always 1, but can still show quantity = 1 non-editable for consistency, or hide)
- Enroll: `router.post(route('admin.bookings.packages.enroll', booking.id), { package_key, quantity })`
- Unenroll: `router.delete(route('admin.bookings.packages.unenroll', [booking.id, packageKey]))`
- Flash success/error via Inertia shared flash (existing pattern)
- Disabled state: all buttons disabled when `!can.manage_packages`
- Permission note: amber banner "Chỉ xem — không có quyền chỉnh sửa gói" when `!can.manage_packages`

**City Tax informational section (below package cards):**
```
┌────────────────────────────────────────────────────────┐
│ ℹ Thuế du lịch                                         │
│ Tự động áp dụng theo cài đặt khách sạn.                │
│ Trạng thái: Đang bật / Tắt                             │
│ (Không thể điều chỉnh theo từng đặt phòng)             │
└────────────────────────────────────────────────────────┘
```

Note: City tax enabled status needs to be passed from the controller as `city_tax_enabled: bool` prop. Add this to sub-task 3.3.6.3 controller `show()` if not already included.

**Navigation link in Show.vue:**
Add to existing booking show page actions area:
```html
<Link
    :href="route('admin.bookings.packages', booking.id)"
    class="..."
>
    Gói dịch vụ
</Link>
```

### Complexity: MEDIUM

No novel Vue patterns. The package list is a straightforward v-for over available_packages with enrollment state from the `enrollments` record. Main attention point: the DELETE route has `packageKey` as a URL segment, so `router.delete()` must encode it correctly (it's a safe ASCII constant like `EXTRA_PERSON_PER_NIGHT`).

### Review Checkpoint

After 3.3.6.4: Run `npm run build` — confirm zero build errors. Run `php artisan test` — all existing tests pass. Manually test in browser: enroll extra person qty 2, verify card shows qty 2; attempt unenroll after posting, verify error message. Run vue-reviewer agent on `Packages.vue`.

---

## Sub-task 3.3.6.5 — Night Audit Show Page Enhancement

### Goal

Extend the Night Audit show page to group booking logs by job_class and display per-job summary statistics. This is a UI-only enhancement; no new backend data is created.

### Files to Create/Modify

| File | Action | Change |
|------|--------|--------|
| `app/Http/Controllers/Admin/NightAuditController.php` | MODIFY | `show()` adds `job_summary` aggregated prop |
| `resources/js/Pages/Admin/NightAudit/Show.vue` | MODIFY | Add per-job summary table above booking log list |

### Implementation Contract

**Controller `show()` addition:**
```php
$jobSummary = NightAuditBookingLog::where('run_id', $run->id)
    ->selectRaw('job_class, result, COUNT(*) as count')
    ->groupBy('job_class', 'result')
    ->get()
    ->groupBy('job_class')
    ->map(fn ($logs) => [
        'posted' => $logs->where('result', 'POSTED')->first()?->count ?? 0,
        'already_posted' => $logs->where('result', 'ALREADY_POSTED')->first()?->count ?? 0,
        'skipped' => $logs->where('result', 'SKIPPED')->first()?->count ?? 0,
        'failed' => $logs->where('result', 'FAILED')->first()?->count ?? 0,
    ]);

// Add to Inertia::render() data:
'job_summary' => $jobSummary,
```

**Vue change:**
```
Add prop: job_summary: Record<string, { posted, already_posted, skipped, failed }>

Add before booking log list: a small summary table showing:
  Job class (human-readable short name) | Posted | Skipped | Already posted | Failed

Short name map:
  RoomChargePostingJob → 'Tiền phòng'
  BreakfastPostingJob → 'Ăn sáng'
  ExtraPersonPostingJob → 'Người thêm'
  ExtraBedPostingJob → 'Giường phụ'
  CityTaxPostingJob → 'Thuế du lịch'
  (fallback: class name last segment)
```

### Tests (2 new in existing NightAuditControllerTest)

```
test_show_page_includes_job_summary_with_new_job_types
test_job_summary_correctly_counts_posted_and_skipped_per_job
```

### Complexity: LOW

Purely additive. The `job_summary` prop is a new key; the existing Vue template is unaffected. The groupBy/aggregate is a standard Eloquent query. No risk to existing data or tests.

### Review Checkpoint

After 3.3.6.5: Run full test suite — target 183 + 2 = 185 tests passing. Run `npm run build`. Verify Night Audit show page still renders correctly when `job_summary` is empty (first run before any new jobs fire).

---

## Final Integration Checklist

Before submitting Phase 3.3.6 for ChatGPT Final Review:

### Functional
- [ ] CityTaxPostingJob posts when city_tax_enabled=true and skips when false
- [ ] ExtraPersonPostingJob posts with quantity 1 and quantity 2 (different amounts)
- [ ] ExtraBedPostingJob posts correctly
- [ ] All three jobs are idempotent on retry
- [ ] Unenroll blocked when today's charge already posted
- [ ] Unenroll succeeds before today's Night Audit
- [ ] Packages.vue shows correct enrollment state per package
- [ ] RECEPTION can view but not enroll/unenroll
- [ ] Night Audit show page shows per-job summary

### Tests
- [ ] 185 total tests pass (155 Phase 3.3.5 baseline + 30 new)
- [ ] No existing test broken or modified to accommodate new code
- [ ] All new tests follow AAA pattern
- [ ] Coverage target ≥ 80% for new files

### Security
- [ ] `package_key` validated against ALLOWED_PACKAGES whitelist in controller
- [ ] `package.manage` permission gates all enroll/unenroll mutations
- [ ] Terminal booking guard in enroll
- [ ] City tax setting only editable via existing HotelSettings UI (ADMIN only)
- [ ] No hardcoded secrets or credentials

### Build
- [ ] `npm run build` — zero errors
- [ ] `php artisan test` — 185/185 passing
- [ ] No TypeScript errors in new Vue file

### Regression
- [ ] `BreakfastPostingJob` existing tests still pass (unchanged)
- [ ] `RoomChargePostingJob` existing tests still pass (unchanged)
- [ ] Posting Timeline shows new CITY_TAX/EXTRA_PERSON/EXTRA_BED entries correctly
- [ ] Revenue Report includes new charge types automatically
- [ ] Reconciliation total unaffected (new entries correctly included in folio_total)

---

## Risk Register

| Risk | Severity | Likelihood | Mitigation |
|------|---------|-----------|-----------|
| `HotelSettingsService::getBool()` does not exist | HIGH | LOW | Confirm method exists; if not, use `get()` with `=== true` |
| `Booking::isTerminal()` method missing | MEDIUM | LOW | Inline the check in controller |
| `PackageAlreadyPostedException` class missing | MEDIUM | MEDIUM | Create `app/Exceptions/PackageAlreadyPostedException.php` |
| ChargeType match exhaustiveness error (if any `match` exists) | MEDIUM | LOW | Grep for `match ($chargeType)` and add CityTax case |
| `value` field interpreted as quantity breaks Breakfast | LOW | LOW | BreakfastPostingJob hardcodes '1.00', no change |
| Extra person/bed double-post on retry | LOW | VERY LOW | posting_key UNIQUE constraint + isAlreadyPosted() |
| `packageKey` route segment encoding issue | LOW | LOW | `EXTRA_PERSON_PER_NIGHT` is URL-safe; no special chars |
| City tax posts when folio just closed mid-audit | LOW | VERY LOW | shouldProcess() checks folio.status = OPEN |

---

## Suggested Implementation Order

1. **3.3.6.1** — Foundation: ChargeType + CityTaxPostingJob. Validates the extension pattern before adding more jobs.
2. **3.3.6.2** — Additive: ExtraPersonPostingJob + ExtraBedPostingJob + PackageEnrollmentService. All new jobs registered.
3. **3.3.6.3** — API: PackageEnrollmentController + Routes. Backend complete and testable.
4. **3.3.6.4** — UI: Packages.vue. Frontend wires to the API.
5. **3.3.6.5** — Polish: Night Audit show enhancement. Quick additive finish.

---

## Deliverable Size Estimate

| Component | Lines (approx) |
|-----------|---------------|
| `CityTaxPostingJob.php` | ~90 |
| `ExtraPersonPostingJob.php` | ~90 |
| `ExtraBedPostingJob.php` | ~90 |
| `PackageEnrollmentService.php` additions | ~80 |
| `PackageEnrollmentController.php` | ~130 |
| `ChargeType.php` change | ~5 |
| `NightAuditService.php` change | ~10 |
| Route additions | ~10 |
| Seeder additions | ~30 |
| `Packages.vue` | ~280 |
| `Show.vue` modifications | ~40 |
| `NightAuditController.php` change | ~20 |
| **PHP tests** | ~450 |
| **Total** | **~1,325** |

---

## Ready for Review

**This implementation plan is submitted for ChatGPT Architecture Review.**

Architecture document: `docs/roadmaps/phase-3.3.6-extra-charges-pipeline.md`

**Do NOT begin implementation until ChatGPT Architecture Review clears.**
