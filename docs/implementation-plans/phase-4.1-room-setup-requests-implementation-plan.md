# Phase 4.1 — Room Setup Requests Implementation Plan

**Date:** 2026-07-04
**Status:** READY FOR BACKEND IMPLEMENTATION
**Branch:** phase-3
**Architecture Review:** PASS (commit c3d5a9e)
**ADRs:** ADR-80, ADR-81, ADR-82, ADR-83

---

## 1. Executive Summary

### Objective

Phase 4.1 introduces **Room Setup Requests (Yêu cầu chuẩn bị phòng)** — the first operational module of Lastella PMS. It captures guest requests for physical room preparation (bed configuration, extra items, decorations, accessibility accommodations) that staff must fulfill before or during a stay.

### Operational Nature — No Financial Impact

This is a **pure operational module**:

- **Zero `FolioEntry` records created** by any special request action
- **Zero `BookingPayment` records touched**
- **Zero `NightAuditPipeline` jobs added** in Phase 4.1
- **`FolioService::calculateGuardedFolioTotal()` is never called**
- The Phase 3 Financial Foundation (single-sided ledger, canonical lock order, guarded balance) is completely unchanged

If a request happens to incur a charge (e.g., a flower decoration arrangement), the charge is entered separately through `FolioService::addCharge()` as an independent operation. The request record and the charge record are decoupled.

### Architecture Review

| Item | Status |
|---|---|
| Architecture Review Result | **PASS** |
| ADR-80: Table architecture | Accepted |
| ADR-81: HOUSEKEEPING restricted UI | Accepted — Option A |
| ADR-82: booking_id FK RESTRICT | Accepted |
| ADR-83: requested_by NOT NULL | Accepted |
| Architecture blockers remaining | **NONE** |

### Ready for Backend Implementation

**YES** — after this Implementation Plan has been reviewed and approved.

---

## 2. Architecture References

| Document | Role in Phase 4.1 |
|---|---|
| `docs/reports/phase-4.1-architecture-review.md` | Approved architecture review — PASS verdict; all 8 review areas passed |
| `docs/roadmaps/phase-4.1-room-setup-requests.md` | Source design document; updated with all ADR decisions; defines scope, data model, UI design, request categories |
| `docs/adr/ADR-80-booking-special-requests-architecture.md` | Authoritative table DDL; nullable stay_id bridge pattern; actor tracking convention; status machine |
| `docs/adr/ADR-81-housekeeping-restricted-ui.md` | HOUSEKEEPING restricted mode decision (Option A); permission-to-action mapping; UI conditional rendering rules |
| `docs/adr/ADR-82-booking-id-fk-restrict.md` | `booking_id` FK must be ON DELETE RESTRICT; rationale for consistency with `folios.booking_id` |
| `docs/adr/ADR-83-requested-by-not-null.md` | `requested_by` must be NOT NULL; system-created requests not supported in Phase 4.1 |
| `docs/architecture/phase-3-architecture-baseline.md` | Phase 3 Financial Foundation lock; canonical lock order; Phase 4 integration points (§7); permission baseline (§6); regression baseline (§9) |

---

## 3. Scope

### In Scope — Phase 4.1

| Component | Layer |
|---|---|
| `create_booking_special_requests_table` migration | Database |
| `RequestCategory` PHP backed enum | Backend |
| `RequestStatus` PHP backed enum | Backend |
| `BookingSpecialRequest` Eloquent model | Backend |
| `SpecialRequestService` — write gateway | Backend |
| `BookingSpecialRequestPolicy` — authorization | Backend |
| `BookingSpecialRequestController` — HTTP handler | Backend |
| `StoreBookingSpecialRequestRequest` — validation | Backend |
| Routes: `/bookings/{booking}/special-requests` | Backend |
| AuditObserver registration (`AppServiceProvider.php`) | Backend |
| `RolePermissionSeeder` update: `special_request.*` | Backend |
| Integration hook: `BookingService::cancelBooking()` | Backend |
| Integration hook: `StayService::createStayFromAssignment()` | Backend |
| Room Board pending count backend query | Backend |
| Booking Detail "Yêu cầu" tab in `Show.vue` | Frontend |
| Add Request form (ADMIN, MANAGER, RECEPTION only) | Frontend |
| HOUSEKEEPING restricted mode (view + acknowledge + fulfill) | Frontend |
| Request status badges | Frontend |
| Room Board pending badge per room | Frontend |
| Stay summary inline request list | Frontend |
| Unit, Feature, Policy, Inertia, Regression tests | Testing |

### Out of Scope — Phase 4.1

| Component | Reason |
|---|---|
| Housekeeping Board full page (`/admin/housekeeping`) | Phase 4.2 |
| Room cleaning status (clean/dirty/inspected) | Phase 4.3 |
| Task assignment to housekeeping staff | Phase 4.4 |
| Maintenance module | Separate module |
| Guest-facing booking portal | Future CRM |
| Financial charge automation from requests | Intentionally decoupled |
| `FolioEntry`, `BookingPayment`, `NightAuditPipeline` changes | Phase 3 — closed |
| `special_request_types` catalog table | Phase 4.2 optional |
| Admin-configurable request type catalog | Phase 4.2 optional |

---

## 4. Database Implementation Plan

### 4.1 Migration File

**Name:** `create_booking_special_requests_table`
**Location:** `database/migrations/YYYY_MM_DD_HHMMSS_create_booking_special_requests_table.php`

### 4.2 Final DDL (authoritative — ADR-80, ADR-82, ADR-83)

```sql
CREATE TABLE booking_special_requests (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Booking link (always present)
    booking_id       BIGINT UNSIGNED NOT NULL,

    -- Stay link (nullable bridge — ADR-80)
    stay_id          BIGINT UNSIGNED NULL,           -- NULL = not yet attributed to a room

    -- Request classification
    category         VARCHAR(32)     NOT NULL,       -- RequestCategory enum (app-enforced)
    request_type     VARCHAR(64)     NOT NULL,       -- string code — not a DB enum (extensibility)
    quantity         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    note             TEXT            NULL,

    -- Status lifecycle
    status           VARCHAR(32)     NOT NULL DEFAULT 'pending',
                                                    -- pending | acknowledged | fulfilled | cancelled

    -- Actor tracking — pending → acknowledged
    requested_by     BIGINT UNSIGNED NOT NULL,       -- ADR-83: always staff; never null
    acknowledged_by  BIGINT UNSIGNED NULL,
    acknowledged_at  TIMESTAMP       NULL,

    -- Actor tracking — acknowledged → fulfilled
    fulfilled_by     BIGINT UNSIGNED NULL,
    fulfilled_at     TIMESTAMP       NULL,

    -- Actor tracking — cancelled terminal state (ADR-80)
    cancelled_by     BIGINT UNSIGNED NULL,
    cancelled_at     TIMESTAMP       NULL,

    -- Laravel timestamps
    created_at       TIMESTAMP       NULL,
    updated_at       TIMESTAMP       NULL,

    -- Foreign keys (ADR-82: booking_id uses RESTRICT)
    CONSTRAINT fk_bsr_booking  FOREIGN KEY (booking_id)    REFERENCES bookings(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bsr_stay     FOREIGN KEY (stay_id)       REFERENCES stays(id)    ON DELETE SET NULL,
    CONSTRAINT fk_bsr_req_by   FOREIGN KEY (requested_by)  REFERENCES users(id)    ON DELETE RESTRICT,
    CONSTRAINT fk_bsr_ack_by   FOREIGN KEY (acknowledged_by) REFERENCES users(id)  ON DELETE SET NULL,
    CONSTRAINT fk_bsr_ful_by   FOREIGN KEY (fulfilled_by)  REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_bsr_can_by   FOREIGN KEY (cancelled_by)  REFERENCES users(id)    ON DELETE SET NULL,

    -- Indexes
    INDEX idx_bsr_booking        (booking_id),
    INDEX idx_bsr_stay           (stay_id),
    INDEX idx_bsr_status         (status),
    INDEX idx_bsr_category       (category),
    INDEX idx_bsr_booking_status (booking_id, status)   -- covers most frequent query pattern
);
```

### 4.3 Design Decisions — What Is NOT in This Table

| Absent Field | Reason |
|---|---|
| `amount` / `price` | Requests are operational, not billable. Charges entered separately via `FolioService::addCharge()` |
| `folio_entry_id` | No FK to financial domain |
| `payment_id` | No FK to payment domain |
| `posting_key` | No Night Audit PostingJob |
| `deleted_at` | No soft delete. `cancelled` terminal state replaces deletion |
| `room_id` | Room context is derived from the linked `stay_id → stay → room` |

### 4.4 Rollback

```php
public function down(): void
{
    Schema::dropIfExists('booking_special_requests');
}
```

Migration rollback is safe: the table has no dependants (no other table FKs into it in Phase 4.1). Rollback removes all request data — acceptable since Phase 4.1 is an additive module with no cross-domain dependencies.

---

## 5. Backend Implementation Plan

### 5.1 Enums

**Location:** `app/Enums/`

#### `RequestCategory` — app/Enums/RequestCategory.php

```php
enum RequestCategory: string
{
    case BedConfig     = 'bed_config';
    case ExtraItem     = 'extra_item';
    case Decoration    = 'decoration';
    case Accessibility = 'accessibility';
    case General       = 'general';

    public function label(): string { /* Vietnamese labels */ }
}
```

Adding a new category: add a case here and update the frontend category selector. **No migration required** (column is VARCHAR(32)).

#### `RequestStatus` — app/Enums/RequestStatus.php

```php
enum RequestStatus: string
{
    case Pending      = 'pending';
    case Acknowledged = 'acknowledged';
    case Fulfilled    = 'fulfilled';
    case Cancelled    = 'cancelled';

    public function isTerminal(): bool
    {
        return match($this) {
            self::Fulfilled, self::Cancelled => true,
            default => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match($this) {
            self::Pending      => in_array($next, [self::Acknowledged, self::Cancelled]),
            self::Acknowledged => in_array($next, [self::Fulfilled, self::Cancelled]),
            default            => false,
        };
    }
}
```

### 5.2 Model

**File:** `app/Models/BookingSpecialRequest.php`

#### Fillable

```php
protected $fillable = [
    'booking_id', 'stay_id',
    'category', 'request_type', 'quantity', 'note', 'status',
    'requested_by',
    'acknowledged_by', 'acknowledged_at',
    'fulfilled_by',    'fulfilled_at',
    'cancelled_by',    'cancelled_at',
];
```

#### Casts

```php
protected $casts = [
    'category'        => RequestCategory::class,
    'status'          => RequestStatus::class,
    'acknowledged_at' => 'datetime',
    'fulfilled_at'    => 'datetime',
    'cancelled_at'    => 'datetime',
];
```

#### Relationships

```php
public function booking():     BelongsTo { return $this->belongsTo(Booking::class); }
public function stay():        BelongsTo { return $this->belongsTo(Stay::class); }
public function requestedBy(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
public function acknowledgedBy(): BelongsTo { return $this->belongsTo(User::class, 'acknowledged_by'); }
public function fulfilledBy(): BelongsTo { return $this->belongsTo(User::class, 'fulfilled_by'); }
public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
```

#### Scopes

```php
public function scopePending(Builder $query): Builder
{
    return $query->where('status', RequestStatus::Pending->value);
}

public function scopeActive(Builder $query): Builder
{
    return $query->whereNotIn('status', [
        RequestStatus::Fulfilled->value,
        RequestStatus::Cancelled->value,
    ]);
}
```

#### Add Relationship to Existing Models

```php
// Booking model — add:
public function specialRequests(): HasMany
{
    return $this->hasMany(BookingSpecialRequest::class);
}

// Stay model — add:
public function specialRequests(): HasMany
{
    return $this->hasMany(BookingSpecialRequest::class);
}
```

### 5.3 Service Layer

**File:** `app/Services/SpecialRequestService.php`

`SpecialRequestService` is the **only write gateway** for `BookingSpecialRequest`. No controller or other service may write to `booking_special_requests` directly except through this service.

#### Method Signatures and Contracts

```php
class SpecialRequestService
{
    /**
     * Create a new special request on a booking.
     *
     * Guards:
     * - Booking must not be in a terminal status (CHECKED_OUT, CANCELLED, NO_SHOW)
     * - $requestedBy is always Auth::id() — never null (ADR-83)
     * - $category validated as RequestCategory enum
     * - $requestType validated against known catalog in StoreBookingSpecialRequestRequest
     */
    public function addRequest(
        Booking $booking,
        RequestCategory $category,
        string $requestType,
        int $quantity,
        ?string $note,
        int $requestedBy,
        ?int $stayId = null
    ): BookingSpecialRequest;

    /**
     * Link a request to a specific stay (manual or auto).
     * Idempotent: if already linked to the same stay, returns the request unchanged.
     * If linked to a different stay, throws a ValidationException.
     */
    public function linkToStay(BookingSpecialRequest $request, Stay $stay): BookingSpecialRequest;

    /**
     * Transition status: pending → acknowledged.
     * Requires special_request.fulfill permission.
     * Sets acknowledged_by = Auth::id(), acknowledged_at = now().
     * Throws if request is not in pending status.
     */
    public function acknowledge(BookingSpecialRequest $request, int $actorId): BookingSpecialRequest;

    /**
     * Transition status: acknowledged → fulfilled.
     * Requires special_request.fulfill permission.
     * Sets fulfilled_by = Auth::id(), fulfilled_at = now().
     * Idempotent: if already fulfilled, returns request unchanged (no exception).
     * Throws if request is in cancelled status.
     */
    public function fulfill(BookingSpecialRequest $request, int $actorId): BookingSpecialRequest;

    /**
     * Transition status: pending|acknowledged → cancelled.
     * Requires special_request.cancel permission.
     * Sets cancelled_by = Auth::id(), cancelled_at = now().
     * Throws if request is already in a terminal state (fulfilled, cancelled).
     */
    public function cancel(BookingSpecialRequest $request, int $actorId): BookingSpecialRequest;

    /**
     * Bulk-cancel all pending and acknowledged requests for a booking.
     * Called from BookingService::cancelBooking() INSIDE the existing DB::transaction.
     * Does NOT throw. Returns count of cancelled requests.
     * Uses a single UPDATE statement for efficiency.
     *
     * SQL: UPDATE booking_special_requests
     *      SET status = 'cancelled', cancelled_by = $actorId, cancelled_at = now()
     *      WHERE booking_id = ? AND status NOT IN ('fulfilled', 'cancelled')
     */
    public function autoCancelForBooking(Booking $booking, int $actorId): int;

    /**
     * Auto-link pending (stay_id = null) requests to a newly created stay.
     *
     * Logic:
     * - For BedConfig category: link only if booking has exactly one active stay
     *   (ambiguous if multiple TWIN rooms) — otherwise leave stay_id = null
     * - For all other categories: link if booking has exactly one active stay
     * - If multiple stays: leave stay_id = null; staff links manually
     *
     * CRITICAL: This method MUST NOT throw any exception.
     * It MUST wrap all logic in try/catch and log failures.
     * It must never abort StayService::createStayFromAssignment().
     *
     * Called from StayService::createStayFromAssignment() — outside any transaction.
     */
    public function autoLinkSingleStayRequests(Booking $booking, Stay $newStay): void;
}
```

#### Terminal Booking Status Guard (addRequest)

```php
private function assertBookingIsNotTerminal(Booking $booking): void
{
    $terminalStatuses = [
        BookingStatus::CheckedOut,
        BookingStatus::Cancelled,
        BookingStatus::NoShow,
    ];
    if (in_array($booking->status, $terminalStatuses)) {
        throw ValidationException::withMessages([
            'booking' => 'Không thể thêm yêu cầu cho booking đã kết thúc.',
        ]);
    }
}
```

Follow the same pattern as `BookingPaymentService::createPayment()`.

### 5.4 Policy

**File:** `app/Policies/BookingSpecialRequestPolicy.php`

```php
class BookingSpecialRequestPolicy
{
    /**
     * Create a request on a booking.
     * Permission: special_request.create
     * Roles: ADMIN, MANAGER, RECEPTION
     */
    public function create(User $user, Booking $booking): bool;

    /**
     * Acknowledge or fulfill a request.
     * Permission: special_request.fulfill
     * Roles: ADMIN, MANAGER, HOUSEKEEPING
     * Note: one permission covers both acknowledge and fulfill transitions (ADR-81)
     */
    public function fulfill(User $user, BookingSpecialRequest $request): bool;

    /**
     * Cancel a request.
     * Permission: special_request.cancel
     * Roles: ADMIN, MANAGER only
     * RECEPTION cannot cancel (must escalate to MANAGER)
     */
    public function cancel(User $user, BookingSpecialRequest $request): bool;

    /**
     * View requests on a booking.
     * All four roles (ADMIN, MANAGER, RECEPTION, HOUSEKEEPING) can view.
     * Mirrors the Yêu cầu tab visibility rule (ADR-81).
     */
    public function view(User $user, Booking $booking): bool;
}
```

#### Permission-to-Role Matrix (ADR-81)

| Permission | ADMIN | MANAGER | RECEPTION | HOUSEKEEPING | ACCOUNTANT |
|---|:---:|:---:|:---:|:---:|:---:|
| `special_request.create` | ✅ | ✅ | ✅ | ❌ | ❌ |
| `special_request.fulfill` | ✅ | ✅ | ❌ | ✅ | ❌ |
| `special_request.cancel` | ✅ | ✅ | ❌ | ❌ | ❌ |
| View (tab access) | ✅ | ✅ | ✅ | ✅ | ❌ |

Use `FolioPolicy` and `FolioEntryPolicy` as structural templates — the RBAC pattern is identical.

### 5.5 Controller

**File:** `app/Http/Controllers/Admin/BookingSpecialRequestController.php`

```php
class BookingSpecialRequestController extends Controller
{
    /**
     * Return requests data for a booking.
     * Used by the Yêu cầu tab Inertia page/component.
     * Authorization: policy->view($user, $booking)
     */
    public function index(Booking $booking): InertiaResponse;

    /**
     * Create a new special request.
     * Authorization: policy->create($user, $booking)
     * Delegates to: SpecialRequestService::addRequest()
     */
    public function store(StoreBookingSpecialRequestRequest $request, Booking $booking): RedirectResponse;

    /**
     * Acknowledge a pending request.
     * Authorization: policy->fulfill($user, $specialRequest)
     * Delegates to: SpecialRequestService::acknowledge()
     */
    public function acknowledge(Booking $booking, BookingSpecialRequest $specialRequest): RedirectResponse;

    /**
     * Fulfill an acknowledged request.
     * Authorization: policy->fulfill($user, $specialRequest)
     * Delegates to: SpecialRequestService::fulfill()
     */
    public function fulfill(Booking $booking, BookingSpecialRequest $specialRequest): RedirectResponse;

    /**
     * Cancel a request.
     * Authorization: policy->cancel($user, $specialRequest)
     * Delegates to: SpecialRequestService::cancel()
     */
    public function destroy(Booking $booking, BookingSpecialRequest $specialRequest): RedirectResponse;
}
```

**Controller contract:** Controllers call service methods only. No direct `BookingSpecialRequest::create()`, `->update()`, or `->save()` calls in any controller. All writes go through `SpecialRequestService`.

### 5.6 Form Requests

**File:** `app/Http/Requests/StoreBookingSpecialRequestRequest.php`

```php
class StoreBookingSpecialRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category'     => ['required', 'string', Rule::in(
                array_column(RequestCategory::cases(), 'value')
            )],
            'request_type' => ['required', 'string', Rule::in(self::ALLOWED_REQUEST_TYPES)],
            'quantity'     => ['required', 'integer', 'min:1', 'max:99'],
            'note'         => ['nullable', 'string', 'max:1000'],
            'stay_id'      => ['nullable', 'integer', Rule::exists('stays', 'id')->where(
                'booking_id', $this->route('booking')->id
            )],
        ];
    }

    /**
     * Complete catalog of valid request_type codes.
     * New types are added here (+ frontend catalog array) — no migration needed.
     */
    public const ALLOWED_REQUEST_TYPES = [
        // bed_config
        'twin_keep', 'twin_to_double', 'separate_beds', 'extra_bed',
        // extra_item
        'baby_cot', 'extra_pillow', 'non_feather_pillow', 'extra_blanket',
        'extra_towel', 'welcome_fruit', 'welcome_amenity',
        // decoration
        'anniversary', 'honeymoon', 'birthday', 'vip_setup', 'flower_arrangement',
        // accessibility
        'wheelchair', 'non_smoking_prep', 'ground_floor', 'near_elevator',
        // general
        'late_arrival', 'airport_pickup', 'connecting_room', 'other',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('create', [BookingSpecialRequest::class, $this->route('booking')]);
    }
}
```

The `stay_id` validation uses `Rule::exists()` scoped to `booking_id` to ensure the stay belongs to the booking — preventing cross-booking stay injection.

### 5.7 Routes

**File:** `routes/web.php` — add within the existing `admin` group

```php
// Phase 4.1 — Room Setup Requests
Route::prefix('bookings/{booking}')->group(function () {
    Route::get('special-requests', [BookingSpecialRequestController::class, 'index'])
        ->name('admin.bookings.special-requests.index');

    Route::post('special-requests', [BookingSpecialRequestController::class, 'store'])
        ->name('admin.bookings.special-requests.store');

    Route::patch('special-requests/{specialRequest}/acknowledge',
        [BookingSpecialRequestController::class, 'acknowledge'])
        ->name('admin.bookings.special-requests.acknowledge');

    Route::patch('special-requests/{specialRequest}/fulfill',
        [BookingSpecialRequestController::class, 'fulfill'])
        ->name('admin.bookings.special-requests.fulfill');

    Route::delete('special-requests/{specialRequest}',
        [BookingSpecialRequestController::class, 'destroy'])
        ->name('admin.bookings.special-requests.destroy');
});
```

Route model binding: `{booking}` resolves `Booking`; `{specialRequest}` resolves `BookingSpecialRequest`. The controller verifies `$specialRequest->booking_id === $booking->id` at the start of each action (or use route scoping).

### 5.8 Integration Hooks

#### Hook 1: `BookingService::cancelBooking()` — line 344

**Integration point (Phase 3 Architecture Baseline §7.2):**

Add the following call **inside the existing `DB::transaction`**, after the booking status is updated to CANCELLED and before the method returns:

```php
// Inside BookingService::cancelBooking() DB::transaction:
app(SpecialRequestService::class)->autoCancelForBooking($booking, Auth::id());
```

**Why inside the transaction:** The auto-cancel is a single `UPDATE` statement that modifies `booking_special_requests`. If any part of `cancelBooking()` rolls back, the request statuses also roll back — maintaining consistency between booking status and request statuses.

**Risk:** LOW. `autoCancelForBooking()` is a simple `UPDATE WHERE` statement. It does not acquire additional locks. It does not touch financial tables.

#### Hook 2: `StayService::createStayFromAssignment()` — line 32

**Integration point (Phase 3 Architecture Baseline §7.1):**

Add the following call **after** `Stay::firstOrCreate()` returns, before `return $stay->load(...)`:

```php
// In StayService::createStayFromAssignment(), after firstOrCreate:
try {
    app(SpecialRequestService::class)->autoLinkSingleStayRequests(
        $assignment->booking,
        $stay
    );
} catch (\Throwable $e) {
    Log::warning('autoLinkSingleStayRequests failed', [
        'stay_id'    => $stay->id,
        'booking_id' => $assignment->booking_id,
        'error'      => $e->getMessage(),
    ]);
    // Never re-throw. Stay creation must succeed regardless.
}
```

**Why non-throwing:** `createStayFromAssignment()` has no wrapping transaction. If the auto-link fails and throws, the Stay record exists in the DB but the exception propagates to the caller — creating an inconsistent visible state. The correct behaviour is to create the Stay successfully and let staff manually link requests via the UI.

#### Hook 3: Room Board Pending Count Query

Add a method or scope to `BookingSpecialRequest` that returns pending request counts per room for the Room Board view:

```php
// Suggested: add to a BookingSpecialRequestRepository or as a static method
public static function pendingCountByRoom(array $roomIds): Collection
{
    return self::query()
        ->whereIn('status', [RequestStatus::Pending->value, RequestStatus::Acknowledged->value])
        ->whereHas('stay', fn ($q) => $q->whereIn('room_id', $roomIds))
        ->join('stays', 'stays.id', '=', 'booking_special_requests.stay_id')
        ->groupBy('stays.room_id')
        ->select('stays.room_id', DB::raw('COUNT(*) as pending_count'))
        ->pluck('pending_count', 'room_id');
}
```

The Room Board controller (Phase 2.x) loads this data and passes it as a prop to the Room Board Vue component.

### 5.9 AuditObserver Registration

**File:** `app/Providers/AppServiceProvider.php`

Add after `Stay::observe(AuditObserver::class)` (currently line 92):

```php
BookingSpecialRequest::observe(AuditObserver::class);
```

This ensures all creates, updates, and deletes on `booking_special_requests` are recorded in the audit log — consistent with all other critical models in the system.

---

## 6. Frontend Implementation Plan

### 6.1 Booking Detail — "Yêu cầu" Tab

**File:** `resources/js/Pages/Admin/Booking/Show.vue`

Add a new "Yêu cầu" tab alongside the existing tabs (Thông tin, Phòng, Tài chính, Gói dịch vụ, Posting Timeline).

**Tab badge:** Show pending request count as a number badge on the tab label. Badge is hidden when count is 0.

**Tab visibility:** Render for all four roles: ADMIN, MANAGER, RECEPTION, HOUSEKEEPING. The tab is hidden for ACCOUNTANT (no `special_request.*` permissions).

**Request table columns:**

| Column | Description |
|---|---|
| # | Row index |
| Loại | Category label (Vietnamese) |
| Yêu cầu | Request type label (Vietnamese, from frontend catalog) |
| Số lượng | Quantity |
| Phòng | Room number if `stay_id` linked; `—` if unattributed |
| Ghi chú | Truncated note (tooltip for full text) |
| Trạng thái | Status badge (color-coded) |
| Hành động | Action buttons (conditional per role) |

**Status badge colors:**

| Status | Color |
|---|---|
| `pending` | Yellow / Amber |
| `acknowledged` | Blue |
| `fulfilled` | Green |
| `cancelled` | Gray |

**Empty state:** When no requests exist, show: _"Chưa có yêu cầu đặc biệt nào. Nhấn '+ Thêm yêu cầu' để tạo yêu cầu mới."_ (Hidden for HOUSEKEEPING since they cannot create.)

### 6.2 Add Request Form

**Visibility:** Render only when `hasPermission('special_request.create')` — ADMIN, MANAGER, RECEPTION.

**Fields:**

```
Loại yêu cầu:  [Cấu hình giường ▾]          (required, RequestCategory)
Yêu cầu:       [Ghép giường thành đôi ▾]     (required, filtered by category)
Số lượng:      [1]                            (required, integer ≥ 1)
Phòng:         [Tùy chọn ▾]                  (optional, shows available stays for this booking)
Ghi chú:       [                          ]  (optional, max 1000 chars)
               [Hủy]  [Thêm yêu cầu]
```

**Category → request_type filtering:**

The frontend maintains a hardcoded catalog object (`REQUEST_TYPE_CATALOG`) matching the `StoreBookingSpecialRequestRequest::ALLOWED_REQUEST_TYPES` constant. When the category selector changes, the request type dropdown is filtered to show only types for that category.

**Stay selector:** The optional "Phòng" dropdown shows stays associated with the booking (room number + dates). Only shown if at least one stay exists.

### 6.3 HOUSEKEEPING Restricted Mode (ADR-81)

HOUSEKEEPING sees the full request list but the UI hides non-permitted actions:

```vue
<!-- Add Request button — hidden for HOUSEKEEPING -->
<Button v-if="can('special_request.create')" @click="showAddForm = true">
    + Thêm yêu cầu
</Button>

<!-- Per-row action buttons -->
<template v-if="row.status === 'pending'">
    <!-- Acknowledge — visible to ADMIN, MANAGER, HOUSEKEEPING -->
    <Button v-if="can('special_request.fulfill')" @click="acknowledge(row)">
        Tiếp nhận
    </Button>

    <!-- Cancel — visible to ADMIN, MANAGER only -->
    <Button v-if="can('special_request.cancel')" variant="danger" @click="cancel(row)">
        Hủy
    </Button>
</template>

<template v-if="row.status === 'acknowledged'">
    <!-- Fulfill — visible to ADMIN, MANAGER, HOUSEKEEPING -->
    <Button v-if="can('special_request.fulfill')" variant="success" @click="fulfill(row)">
        Hoàn thành
    </Button>

    <!-- Cancel — visible to ADMIN, MANAGER only -->
    <Button v-if="can('special_request.cancel')" variant="danger" @click="cancel(row)">
        Hủy
    </Button>
</template>
```

`can()` checks Inertia-shared permissions (`$page.props.auth.permissions`). Backend `BookingSpecialRequestPolicy` is the authoritative enforcement layer.

### 6.4 Room Board Pending Badge

**File:** `resources/js/Pages/Admin/RoomBoard/` (existing Phase 2.x component)

Add a small badge to each room card showing the count of pending + acknowledged requests for that room:

```
┌──────────────────┐
│  301  TWIN  ⏳ 2  │   ← ⏳ 2 = 2 active setup requests
│  Nguyễn Văn A    │
│  01/07 - 03/07   │
└──────────────────┘
```

Badge conditions:
- Show only if count > 0
- Badge disappears when all requests are fulfilled or cancelled
- Badge visible to ADMIN, MANAGER, RECEPTION, HOUSEKEEPING

The backend provides this count via the `pendingCountByRoom()` query (§5.8 Hook 3). The Room Board controller passes `pendingRequestCounts: { [roomId]: count }` as an Inertia prop.

**No full modal required for Phase 4.1.** Phase 4.2 Housekeeping Board provides the dedicated operational view.

### 6.5 Stay Summary Inline Request List

**Location:** Within the existing Stay card in Booking Detail (stays section of the booking)

```
Phòng 301 — TWIN
Nhận phòng: 01/07  |  Trả phòng: 03/07
Yêu cầu: Ghép giường đôi ✓  •  Tuần trăng mật ⏳  •  Xe lăn ✗
```

**Icons:**
- ✓ (green) = `fulfilled`
- ⏳ (yellow/blue) = `pending` or `acknowledged`
- ✗ (gray) = `cancelled`

Only shows requests where `stay_id = stay.id`. Requests with `stay_id = null` do not appear in the stay summary (they appear in the main Yêu cầu tab with `Phòng: —`).

---

## 7. Permission / Seeder Plan

**File:** `database/seeders/RolePermissionSeeder.php`

### 7.1 New Permissions to Create

Add these three permissions to the `$permissions` array or `Permission::create()` block:

```php
'special_request.create',
'special_request.fulfill',
'special_request.cancel',
```

### 7.2 Role Assignments (ADR-81)

```php
// ADMIN — full access
Role::findByName('ADMIN')->givePermissionTo([
    'special_request.create',
    'special_request.fulfill',
    'special_request.cancel',
]);

// MANAGER — full access
Role::findByName('MANAGER')->givePermissionTo([
    'special_request.create',
    'special_request.fulfill',
    'special_request.cancel',
]);

// RECEPTION — create only (cannot fulfill or cancel)
Role::findByName('RECEPTION')->givePermissionTo([
    'special_request.create',
]);

// HOUSEKEEPING — fulfill only (acknowledge + fulfill via ADR-81; cannot create or cancel)
Role::findByName('HOUSEKEEPING')->givePermissionTo([
    'special_request.fulfill',
]);

// ACCOUNTANT — no special_request permissions
// (no change to existing ACCOUNTANT role)
```

### 7.3 Important: Seeder Must Be Idempotent

Use `syncPermissions()` or `firstOrCreate()` patterns to ensure the seeder can be re-run without duplicating permissions or resetting other role permissions. **Never use `syncPermissions([...])` on a role that has existing permissions without including all current permissions in the array** — this would wipe existing Phase 3 permissions.

Recommended pattern:

```php
Role::findByName('HOUSEKEEPING')->givePermissionTo('special_request.fulfill');
// Use givePermissionTo() (additive) rather than syncPermissions() (replaces)
```

---

## 8. API / Payload Plan

### 8.1 Booking Detail — Yêu cầu Tab Props

The Inertia controller for `Booking/Show` (or the `index` action of `BookingSpecialRequestController`) passes:

```php
return Inertia::render('Admin/Booking/Show', [
    // ... existing booking props
    'specialRequests' => BookingSpecialRequestResource::collection(
        $booking->specialRequests()
            ->with(['requestedBy', 'acknowledgedBy', 'fulfilledBy', 'cancelledBy', 'stay.room'])
            ->latest()
            ->get()
    ),
    'availableStays' => $booking->stays()
        ->with('room')
        ->whereIn('status', [StayStatus::Reserved->value, StayStatus::CheckedIn->value])
        ->get()
        ->map(fn ($stay) => [
            'id'   => $stay->id,
            'label' => "Phòng {$stay->room->room_number} ({$stay->planned_checkin_at->format('d/m')} - {$stay->planned_checkout_at->format('d/m')})",
        ]),
    'requestCatalog' => RequestCategory::cases(),     // for category selector
    'pendingCount'   => $booking->specialRequests()->active()->count(),
]);
```

### 8.2 BookingSpecialRequestResource Shape

```php
[
    'id'             => $request->id,
    'category'       => $request->category->value,
    'category_label' => $request->category->label(),
    'request_type'   => $request->request_type,
    'quantity'       => $request->quantity,
    'note'           => $request->note,
    'status'         => $request->status->value,
    'stay_id'        => $request->stay_id,
    'room_number'    => $request->stay?->room?->room_number,
    'requested_by'   => $request->requestedBy?->name,
    'acknowledged_by' => $request->acknowledgedBy?->name,
    'acknowledged_at' => $request->acknowledged_at?->format('d/m/Y H:i'),
    'fulfilled_by'   => $request->fulfilledBy?->name,
    'fulfilled_at'   => $request->fulfilled_at?->format('d/m/Y H:i'),
    'cancelled_by'   => $request->cancelledBy?->name,
    'cancelled_at'   => $request->cancelled_at?->format('d/m/Y H:i'),
    'created_at'     => $request->created_at->format('d/m/Y H:i'),
]
```

### 8.3 Room Board Props (Existing Controller — Modify)

Add to the Room Board Inertia props:

```php
'pendingRequestCounts' => BookingSpecialRequest::pendingCountByRoom(
    $roomIds  // existing room IDs already loaded for the board
)->toArray(),
```

### 8.4 Auth / Permissions Shared Props

The existing Inertia middleware (`HandleInertiaRequests`) already shares `auth.permissions`. Ensure `special_request.create`, `special_request.fulfill`, and `special_request.cancel` are included in the shared permissions array so Vue `can()` checks work correctly.

---

## 9. Testing Plan

### 9.1 Unit Tests

**File:** `tests/Unit/Enums/RequestCategoryTest.php`
- All enum cases exist with correct string values
- `label()` returns Vietnamese string for each case

**File:** `tests/Unit/Enums/RequestStatusTest.php`
- `isTerminal()` returns true for Fulfilled, Cancelled; false for Pending, Acknowledged
- `canTransitionTo()`: Pending → Acknowledged (true), Pending → Fulfilled (false), Acknowledged → Fulfilled (true), Fulfilled → Cancelled (false), Cancelled → Fulfilled (false)

**File:** `tests/Unit/Services/SpecialRequestServiceTest.php`
- Status transition guards produce expected exceptions on illegal transitions
- `autoCancelForBooking()` cancels pending and acknowledged; leaves fulfilled and cancelled unchanged
- `autoLinkSingleStayRequests()`: single stay → links requests; multiple stays → no link for bed_config; non-bed_config with one stay → links

### 9.2 Feature Tests

**File:** `tests/Feature/SpecialRequestCrudTest.php` (≥ 16 tests)

```
✓ ADMIN can create a request on an active booking
✓ RECEPTION can create a request
✓ HOUSEKEEPING cannot create a request (403)
✓ ACCOUNTANT cannot create a request (403)
✓ Cannot create request on CANCELLED booking (422)
✓ Cannot create request on CHECKED_OUT booking (422)
✓ Cannot create request with invalid category (422)
✓ Cannot create request with invalid request_type (422)
✓ Cannot create request with quantity < 1 (422)
✓ Cannot create request with stay_id belonging to a different booking (422)
✓ ADMIN can acknowledge a pending request
✓ HOUSEKEEPING can acknowledge a pending request
✓ RECEPTION cannot acknowledge a request (403)
✓ ADMIN can fulfill an acknowledged request
✓ HOUSEKEEPING can fulfill an acknowledged request
✓ ADMIN can cancel a pending request
✓ MANAGER can cancel an acknowledged request
✓ RECEPTION cannot cancel a request (403)
✓ HOUSEKEEPING cannot cancel a request (403)
✓ Cannot fulfill an already-fulfilled request (idempotent — no exception)
✓ Cannot cancel a fulfilled request (422)
```

**File:** `tests/Feature/SpecialRequestLifecycleTest.php`

```
✓ BookingService::cancelBooking() auto-cancels all pending and acknowledged requests
✓ BookingService::cancelBooking() does not cancel already-fulfilled requests
✓ BookingService::cancelBooking() transaction rollback also rolls back auto-cancel
✓ StayService::createStayFromAssignment() auto-links requests when booking has one stay
✓ StayService::createStayFromAssignment() does not auto-link bed_config when multiple stays exist
✓ StayService::createStayFromAssignment() succeeds even if autoLinkSingleStayRequests throws
✓ stay_id is set to NULL when a stay is deleted (ON DELETE SET NULL)
```

### 9.3 Policy Tests

**File:** `tests/Unit/Policies/BookingSpecialRequestPolicyTest.php`

```
✓ ADMIN: create ✅, fulfill ✅, cancel ✅, view ✅
✓ MANAGER: create ✅, fulfill ✅, cancel ✅, view ✅
✓ RECEPTION: create ✅, fulfill ❌, cancel ❌, view ✅
✓ HOUSEKEEPING: create ❌, fulfill ✅, cancel ❌, view ✅
✓ ACCOUNTANT: create ❌, fulfill ❌, cancel ❌, view ❌
```

### 9.4 Frontend / Inertia Tests

**File:** `tests/Feature/SpecialRequestUiTest.php`

```
✓ Yêu cầu tab renders for ADMIN
✓ Yêu cầu tab renders for MANAGER
✓ Yêu cầu tab renders for RECEPTION
✓ Yêu cầu tab renders for HOUSEKEEPING
✓ Yêu cầu tab does NOT render for ACCOUNTANT
✓ Add Request form hidden for HOUSEKEEPING
✓ Cancel button hidden for RECEPTION
✓ Cancel button hidden for HOUSEKEEPING
✓ Acknowledge / fulfill buttons visible for HOUSEKEEPING
✓ Pending count badge shows correct count
✓ Empty state shows when no requests exist
✓ Room Board pending count prop is included in Room Board page
```

Use `assertInertia()` with `AssertableInertia` — consistent with Phase 3 `FolioUiTest.php` pattern.

### 9.5 Regression Tests

**File:** `tests/Feature/Phase41RegressionTest.php`

Verify that Phase 4.1 backend changes do not affect:

```
✓ Booking create / edit / cancel (existing tests still pass)
✓ Room assignment create / release (existing tests still pass)
✓ Stay creation via createStayFromAssignment() (existing tests still pass)
✓ Check-in (existing tests still pass)
✓ Check-out with balance check (existing tests still pass)
✓ Booking Detail tabs: Thông tin, Phòng, Tài chính (no prop changes)
✓ FolioService::calculateGuardedFolioTotal() returns correct balance
✓ BookingPaymentService::createPayment() unaffected
✓ Night Audit run does not reference booking_special_requests
✓ Revenue report unaffected
✓ Reconciliation report unaffected
✓ Package enrollment unaffected
✓ Permission gates for Phase 3 roles unchanged
✓ Audit log entries still created for Phase 3 models
```

---

## 10. Regression Checklist

Before marking Phase 4.1 as complete, verify each item manually or via test suite:

### Booking Lifecycle
- [ ] New booking can be created
- [ ] Booking can be edited
- [ ] Booking can be cancelled — requests auto-cancelled, folio unaffected
- [ ] Cancelled booking shows cancelled requests in Yêu cầu tab

### Room Assignment & Stay
- [ ] Room assignment creates a Stay (existing behaviour unchanged)
- [ ] Stay creation triggers auto-link — requests with `stay_id = null` linked if single stay
- [ ] Multi-stay booking: `bed_config` requests NOT auto-linked
- [ ] Stay deletion sets `stay_id = null` on linked requests (ON DELETE SET NULL)

### Check-in / Check-out
- [ ] Check-in proceeds normally
- [ ] Check-out balance check uses `FolioService::calculateGuardedFolioTotal()` — no change
- [ ] Checkout gate not affected by presence of special requests

### Booking Detail Tabs
- [ ] Thông tin tab renders correctly
- [ ] Phòng / Room assignment tab renders correctly
- [ ] Tài chính / Finance tab: no special request data shown
- [ ] Gói dịch vụ tab: no change
- [ ] Posting Timeline tab: no change
- [ ] Yêu cầu tab: renders correctly for all four roles

### Room Board
- [ ] Room Board renders (no errors)
- [ ] Pending count badge shows for rooms with active requests
- [ ] Badge disappears when all requests fulfilled/cancelled

### Financial Modules (must be completely unaffected)
- [ ] Folio balance correct after adding requests (balance unchanged)
- [ ] Payment create/refund/adjust unaffected
- [ ] Night Audit run completes without errors
- [ ] Revenue report correct
- [ ] Reconciliation report correct

### Permissions
- [ ] ACCOUNTANT cannot see Yêu cầu tab
- [ ] HOUSEKEEPING cannot create or cancel requests
- [ ] RECEPTION cannot fulfill or cancel requests

### Audit Log
- [ ] Creating a request generates an audit log entry
- [ ] Acknowledging generates an audit log entry
- [ ] Fulfilling generates an audit log entry
- [ ] Cancelling generates an audit log entry

---

## 11. Risk Matrix

| Risk | Impact | Likelihood | Mitigation | Test Coverage |
|---|---|---|---|---|
| Permission leak — HOUSEKEEPING fulfills via direct API without policy check | HIGH | LOW | `BookingSpecialRequestPolicy` enforced in controller via `authorize()`; not UI-only | Policy tests (§9.3) |
| Auto-link ambiguity — multi-stay bed_config request linked to wrong stay | MEDIUM | MEDIUM | Auto-link skipped when multiple stays exist; staff links manually | `SpecialRequestLifecycleTest` |
| Booking cancel leaves pending requests — financial confusion | HIGH | LOW | `autoCancelForBooking()` runs inside `cancelBooking()` DB::transaction; auto-cancels all pending and acknowledged | Lifecycle tests (§9.2) |
| HOUSEKEEPING cannot act — dead permission (original gap) | HIGH | N/A | **Resolved by ADR-81 Option A** — HOUSEKEEPING gets restricted tab access | UI tests (§9.4) |
| Direct model write bypassing service | HIGH | LOW | Code review gate; controller contract (§5.5) prohibits direct model writes | code-reviewer agent must flag |
| Financial regression — FolioEntry created by mistake | CRITICAL | LOW | No FolioService or FolioEntry import in SpecialRequestService; schema has no financial columns | Regression tests (§9.5) |
| Room Board query performance degradation | MEDIUM | LOW | Composite index `(booking_id, status)` + dedicated scope avoids N+1 | Manual QA on Room Board |
| Invalid `request_type` string drift via direct API call | MEDIUM | LOW | `StoreBookingSpecialRequestRequest` validates against `ALLOWED_REQUEST_TYPES` catalog | Crud tests (§9.2) |
| Stay creation failure due to auto-link | HIGH | LOW | `autoLinkSingleStayRequests()` is non-throwing; try/catch + Log::warning only | Lifecycle test: "succeeds even if autoLink throws" |
| `requested_by` null inserted via seeder or factory | MEDIUM | LOW | Column is NOT NULL (ADR-83); DB-level constraint rejects null | Migration schema validation |
| `cancelled_by/at` not set on auto-cancel | MEDIUM | LOW | `autoCancelForBooking()` explicitly sets `cancelled_by = $actorId, cancelled_at = now()` | Lifecycle tests |
| AuditObserver not registered — missing audit trail | MEDIUM | LOW | Explicit registration step in implementation order (§13 step 7) | Audit log regression test |

---

## 12. Rollback Strategy

### Database Rollback

```bash
php artisan migrate:rollback --step=1
```

Migration `create_booking_special_requests_table` has a `down()` method that calls `Schema::dropIfExists('booking_special_requests')`. This is safe because:
- No other table has a FK pointing into `booking_special_requests`
- Rolling back removes all request data — acceptable since the module is additive

### Permission Rollback

```php
// Remove special_request permissions from all roles
Permission::where('name', 'LIKE', 'special_request.%')->get()
    ->each(fn ($p) => $p->roles()->detach());
// Then delete the permissions
Permission::where('name', 'LIKE', 'special_request.%')->delete();
```

### Route Rollback

Remove the Phase 4.1 route group from `routes/web.php`. No other routes are modified.

### Frontend Rollback

Remove the "Yêu cầu" tab from `Booking/Show.vue` and the Room Board badge from the Room Board component. Git revert on the relevant Vue files.

### Integration Hook Rollback

Remove the `SpecialRequestService::autoCancelForBooking()` call from `BookingService::cancelBooking()` and the `autoLinkSingleStayRequests()` call from `StayService::createStayFromAssignment()`.

### AuditObserver Rollback

Remove `BookingSpecialRequest::observe(AuditObserver::class)` from `AppServiceProvider.php`.

### Financial Safety During Rollback

Rollback of Phase 4.1 **does not affect**:
- `folios` table
- `folio_entries` table
- `booking_payments` table
- `night_audit_runs` or `night_audit_booking_logs`
- `service_rates`
- Any Phase 3 service method signatures

The Phase 3 Financial Foundation is completely isolated from Phase 4.1. Rollback is safe at any point.

---

## 13. Implementation Order

Execute in this order to minimise risk at each step. Each step is independently testable and reversible before the next step begins.

| Step | Task | Risk if Skipped |
|---|---|---|
| **1** | Migration: run `create_booking_special_requests_table` | Blocks all backend steps |
| **2** | Enums: `RequestCategory`, `RequestStatus` | Blocks Model and Service |
| **3** | Model: `BookingSpecialRequest` (fillable, casts, scopes, relationships) | Blocks Service and Controller |
| **4** | Add relationships to `Booking` and `Stay` models | Blocks eager loading |
| **5** | Policy: `BookingSpecialRequestPolicy` | Blocks Controller authorization |
| **6** | Seeder: add `special_request.*` permissions and role assignments | Blocks permission tests |
| **7** | **AuditObserver**: register `BookingSpecialRequest::observe()` in `AppServiceProvider.php` | Audit trail missing |
| **8** | Service: `SpecialRequestService` — all 7 methods | Blocks Controller |
| **9** | Form Request: `StoreBookingSpecialRequestRequest` | Blocks store route |
| **10** | Controller: `BookingSpecialRequestController` | Blocks routes |
| **11** | Routes: add Phase 4.1 route group | Blocks HTTP access |
| **12** | Integration Hook 1: `BookingService::cancelBooking()` | Auto-cancel missing |
| **13** | Integration Hook 2: `StayService::createStayFromAssignment()` | Auto-link missing |
| **14** | Room Board backend query: `pendingCountByRoom()` scope | Blocks Room Board badge |
| **15** | **Backend tests**: Unit + Feature + Policy (§9.1–9.3) | Quality gate |
| **16** | ChatGPT backend review | Quality gate |
| **17** | Frontend: "Yêu cầu" tab in `Booking/Show.vue` | Blocks UI |
| **18** | Frontend: Add Request form with catalog (ADMIN, MANAGER, RECEPTION) | Blocks creation UX |
| **19** | Frontend: HOUSEKEEPING restricted mode — conditional rendering (ADR-81) | Compliance with ADR-81 |
| **20** | Frontend: Room Board pending badge | Optional — can be done last |
| **21** | Frontend: Stay summary inline request list | Optional — can be done last |
| **22** | **Frontend tests**: Inertia + manual QA (§9.4) | Quality gate |
| **23** | **Regression tests** (§9.5, §10 checklist) | Financial safety gate |
| **24** | ChatGPT frontend review | Quality gate |
| **25** | Final commit — only after all reviews pass | Per Definition of Done |

---

## 14. Definition of Done

Phase 4.1 is complete when **all** of the following are true:

### Backend
- [ ] Migration runs without error; rollback works
- [ ] `RequestCategory` and `RequestStatus` enums exist with correct string values
- [ ] `BookingSpecialRequest` model with all fillable, casts, relationships, scopes
- [ ] AuditObserver registered for `BookingSpecialRequest`
- [ ] `SpecialRequestService` — all 7 methods implemented; addRequest guards terminal booking; autoCancelForBooking runs inside cancelBooking transaction; autoLinkSingleStayRequests is non-throwing
- [ ] `BookingSpecialRequestPolicy` — create, fulfill, cancel, view per ADR-81 matrix
- [ ] `BookingSpecialRequestController` — delegates to service; no direct model writes
- [ ] `StoreBookingSpecialRequestRequest` — validates category, request_type, quantity, stay belongs to booking
- [ ] Routes registered and accessible
- [ ] Integration hooks wired in `cancelBooking()` and `createStayFromAssignment()`
- [ ] Seeder updated: `special_request.*` permissions; roles per ADR-81; idempotent
- [ ] Room Board backend query method exists and is used

### Testing
- [ ] All Phase 3 existing tests still pass (no new regressions in 553-test baseline)
- [ ] All new unit tests pass (RequestCategory, RequestStatus, SpecialRequestService)
- [ ] All new feature tests pass (CRUD, lifecycle, policy)
- [ ] All Inertia/UI tests pass

### Frontend
- [ ] "Yêu cầu" tab renders correctly for all 4 roles (ADMIN, MANAGER, RECEPTION, HOUSEKEEPING)
- [ ] Add Request form hidden for HOUSEKEEPING
- [ ] Acknowledge + Fulfill buttons visible for HOUSEKEEPING
- [ ] Cancel button hidden for HOUSEKEEPING and RECEPTION
- [ ] ACCOUNTANT has no tab access
- [ ] Status badges color-correct
- [ ] Room Board badge shows pending count
- [ ] Stay summary inline list with status icons

### Verification
- [ ] HOUSEKEEPING restricted mode manually verified in browser (ADR-81)
- [ ] Booking cancellation auto-cancel verified in browser
- [ ] Stay auto-link verified in browser
- [ ] Folio balance unchanged after creating requests (financial regression check)
- [ ] Night Audit run completes without errors
- [ ] Regression checklist (§10) all items checked

### Review
- [ ] ChatGPT backend review completed — no CRITICAL or HIGH issues
- [ ] ChatGPT frontend review completed — no CRITICAL or HIGH issues
- [ ] Code committed **only after both reviews pass**

---

## 15. Final Readiness

### Readiness Assessment

| Gate | Status |
|---|---|
| Phase 3 complete | ✅ |
| Architecture Review | ✅ PASS |
| ADR-80 through ADR-83 accepted | ✅ |
| Architecture blockers | ✅ NONE |
| Implementation Plan reviewed | ✅ (pending this document's review) |
| Backend implementation started | ❌ NOT YET |

### Next Step

This Implementation Plan must be reviewed (by ChatGPT or team lead) before backend implementation begins. After review approval, implement in the order specified in §13.

---

```
Implementation Plan Result: READY FOR BACKEND IMPLEMENTATION

Phase 4.1 — Room Setup Requests
Architecture Review: PASS
ADRs: ADR-80, ADR-81, ADR-82, ADR-83
No remaining architecture blockers.
No financial domain impact.
Proceed with §13 implementation order after plan review.
```
