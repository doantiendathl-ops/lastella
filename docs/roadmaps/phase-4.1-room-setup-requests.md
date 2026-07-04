# Room Setup Requests — Architecture Design

**Date:** 2026-06-27
**Status:** Design Proposal — Pending Review
**Proposed Phase:** 4.1 (first sub-phase of Housekeeping module)
**Prerequisite:** Phase 3.3 Service Charges complete

---

## Table of Contents

1. [Business Scenario](#1-business-scenario)
2. [Entity Analysis — Where Does This Belong?](#2-entity-analysis--where-does-this-belong)
3. [Proposed Architecture](#3-proposed-architecture)
4. [Data Model](#4-data-model)
5. [Request Categories and Types](#5-request-categories-and-types)
6. [Operational Lifecycle](#6-operational-lifecycle)
7. [UI Design](#7-ui-design)
8. [Future Extensibility](#8-future-extensibility)
9. [Relationship with Housekeeping Module](#9-relationship-with-housekeeping-module)
10. [Risks](#10-risks)
11. [Implementation Plan](#11-implementation-plan)
12. [Ready for Implementation](#12-ready-for-implementation)

---

## 1. Business Scenario

A guest books a Twin room but requests Housekeeping join the two beds together for a Double setup. Other requests follow the same operational pattern:

| Request | Category | Affects pricing? | Affects availability? | Affects inventory? |
|---|---|---|---|---|
| Twin → Join as Double | Bed config | No | No | No |
| King → Separate beds | Bed config | No | No | No |
| Extra baby cot | Extra item | No | No | No |
| Extra pillow | Extra item | No | No | No |
| Non-feather pillow | Extra item | No | No | No |
| Anniversary decoration | Decoration | No | No | No |
| Honeymoon decoration | Decoration | No | No | No |
| VIP amenities | Decoration | No | No | No |
| Wheelchair prep | Accessibility | No | No | No |
| Non-smoking preparation | General | No | No | No |

All of these are **pure operational instructions** — they exist only to tell the right staff member what to prepare before or during the guest's stay.

---

## 2. Entity Analysis — Where Does This Belong?

### Evaluation

| Entity | Available at booking time? | Has room context? | Appropriate semantic layer? | Verdict |
|---|---|---|---|---|
| `Booking` | ✅ Always | ❌ No specific room | Financial + status lifecycle | Too high-level for room-specific requests |
| `BookingRequirement` | ✅ Yes | ⚠️ Room type only (not physical room) | Inventory / price planning | Wrong layer — this is about room allocation, not operations |
| `RoomAssignment` | ❌ Created after booking | ✅ Has room_id | Availability / assignment lifecycle | Better, but created too late for early capture |
| `Stay` | ❌ Created after assignment | ✅ Has room_id + occupancy dates | Occupancy lifecycle | Right operational context; wrong timing |
| `Housekeeping module` | N/A — doesn't exist yet | N/A | Future operational module | Correct long-term home |

### Why None of the Existing Entities Fit

The core tension is timing vs context:
- Request timing: **at booking creation** (guest mentions setup preference on the phone)
- Request context: **a specific room** (Housekeeping needs to know which room to prepare)

`Stay` has the room context but doesn't exist at booking time. `Booking` exists early but has no room context. `RoomAssignment` is closer but still intermediate.

### Decision: Dedicated `booking_special_requests` Table

A purpose-built table with:
- `booking_id` — always present; captures the request as soon as it's made
- `stay_id` — nullable; filled when the room is assigned and stay is created

This is not a new concept — `folio_entries` uses the same pattern: `folio_id` (always) + `stay_id` (nullable, added in Phase 3.3). Room setup requests follow the identical bridge pattern.

**The request belongs to neither Booking nor Stay exclusively — it belongs to the guest's visit, which spans both.**

---

## 3. Proposed Architecture

### Core Design Principles

1. **No pricing impact** — setup requests never touch `FolioEntry`, `BookingPayment`, or `paymentSummary()`
2. **No availability impact** — setup requests never touch `RoomAssignment` conflict checks
3. **Operational only** — visible to Front Desk and Housekeeping; invisible to Finance and Sales
4. **Extensible without migration** — new request types added via frontend config, not DB schema changes
5. **Status-tracked** — requests have a lifecycle: pending → acknowledged → fulfilled

### Entity Relationship

```
Booking (1) ──────────────── (many) BookingSpecialRequest
                                           │
Stay (1) ──────── (many, nullable) ────────┘
```

When a guest request is recorded at booking time: `booking_id` is set, `stay_id` is null.
When the room is assigned and a stay is created: staff links (or the system auto-links) the request to the relevant stay.

### What This Is NOT

- Not a service charge — no `FolioEntry` is created
- Not a housekeeping task list — that belongs to a future Housekeeping Board
- Not a maintenance request — that is a separate Maintenance module concern
- Not a guest preference profile — that belongs to a future CRM module

---

## 4. Data Model

### `booking_special_requests` Table

```sql
CREATE TABLE booking_special_requests (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id       BIGINT UNSIGNED NOT NULL,
    stay_id          BIGINT UNSIGNED NULL,           -- nullable: set when room is known

    category         VARCHAR(32)     NOT NULL,       -- enum: see §5
    request_type     VARCHAR(64)     NOT NULL,       -- string code: 'twin_to_double', 'baby_cot', etc.
    quantity         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    note             TEXT            NULL,           -- freeform detail for non-standard requests

    status           VARCHAR(32)     NOT NULL DEFAULT 'pending',
                                                    -- pending | acknowledged | fulfilled | cancelled

    requested_by     BIGINT UNSIGNED NOT NULL,        -- user who recorded the request (always staff; ADR-83)
    acknowledged_by  BIGINT UNSIGNED NULL,
    acknowledged_at  TIMESTAMP       NULL,
    fulfilled_by     BIGINT UNSIGNED NULL,
    fulfilled_at     TIMESTAMP       NULL,
    cancelled_by     BIGINT UNSIGNED NULL,            -- actor tracking for terminal cancel state (ADR-80)
    cancelled_at     TIMESTAMP       NULL,

    created_at       TIMESTAMP       NULL,
    updated_at       TIMESTAMP       NULL,

    CONSTRAINT fk_bsr_booking  FOREIGN KEY (booking_id)    REFERENCES bookings(id) ON DELETE RESTRICT,  -- ADR-82
    CONSTRAINT fk_bsr_stay     FOREIGN KEY (stay_id)       REFERENCES stays(id)    ON DELETE SET NULL,
    CONSTRAINT fk_bsr_req_by   FOREIGN KEY (requested_by)  REFERENCES users(id)    ON DELETE RESTRICT,
    CONSTRAINT fk_bsr_ack_by   FOREIGN KEY (acknowledged_by) REFERENCES users(id)  ON DELETE SET NULL,
    CONSTRAINT fk_bsr_ful_by   FOREIGN KEY (fulfilled_by)  REFERENCES users(id)    ON DELETE SET NULL,
    CONSTRAINT fk_bsr_can_by   FOREIGN KEY (cancelled_by)  REFERENCES users(id)    ON DELETE SET NULL,

    INDEX idx_bsr_booking        (booking_id),
    INDEX idx_bsr_stay           (stay_id),
    INDEX idx_bsr_status         (status),
    INDEX idx_bsr_category       (category),
    INDEX idx_bsr_booking_status (booking_id, status)
);
```

### Design Decisions

**`category` as VARCHAR(32) with enum validation at application layer**
Categories are stable and meaningful for grouping in UI. A small finite set (≤ 6) makes VARCHAR appropriate — application enforces valid values via a PHP enum `RequestCategory`. New categories require a code change but NOT a migration.

**`request_type` as VARCHAR(64) — deliberately not an enum**
This is the extensibility key. New request types (birthday decoration, airport pickup, welcome fruit) must be addable without any migration. The frontend holds a hardcoded catalog of known types per category. The database stores the string and passes it through. An admin-configurable catalog table can be added in Phase 4.2 if the list grows unwieldy.

**`quantity` as SMALLINT**
Most requests are binary (baby cot: 1, wheelchair: 1) but some are countable (extra pillows: 3, extra towels: 2). SMALLINT UNSIGNED supports up to 65,535 — more than sufficient.

**`stay_id` ON DELETE SET NULL**
If a stay is cancelled or released, the request should remain on the booking unattributed rather than be deleted. The request may be re-linked to the new stay when a replacement assignment is made.

**`booking_id` ON DELETE RESTRICT** *(ADR-82)*
Consistent with `folios.booking_id` convention. Hard-deleting a booking is blocked while requests exist. Bookings in production are always cancelled (status change), never hard-deleted.

**`requested_by` NOT NULL** *(ADR-83)*
Every request is always created by a logged-in staff member (ADMIN, MANAGER, RECEPTION). No system-automated creation path exists in Phase 4.1. `Auth::id()` is always available at creation time.

**`cancelled_by` / `cancelled_at`** *(ADR-80)*
Cancel is a terminal state that must track who cancelled and when — consistent with Phase 3 actor-tracking pattern (`folio_entries.voided_by/voided_at`, `room_assignments.released_by/released_at`).

**No `amount` or pricing fields**
These are operational requests, not billable items. If a request incurs a charge (e.g., a flower decoration that costs money), the charge is separately entered via `FolioService::addCharge()`. The request and the charge are independent records.

### Eloquent Model

**`BookingSpecialRequest`**
```php
fillable:      booking_id, stay_id, category, request_type, quantity, note, status,
               requested_by, acknowledged_by, acknowledged_at, fulfilled_by, fulfilled_at,
               cancelled_by, cancelled_at
casts:         category → RequestCategory enum; status → RequestStatus enum;
               acknowledged_at, fulfilled_at, cancelled_at → datetime
relationships: booking (BelongsTo), stay (BelongsTo, nullable),
               requestedBy (BelongsTo User), acknowledgedBy (BelongsTo User),
               fulfilledBy (BelongsTo User), cancelledBy (BelongsTo User)
scopes:        scopePending() → WHERE status = 'pending'
               scopeActive()  → WHERE status NOT IN (fulfilled, cancelled)
observers:     AuditObserver — register in AppServiceProvider.php after Stay::observe()
```

---

## 5. Request Categories and Types

### `RequestCategory` Enum (application-enforced)

| Value | Label (VI) | Description |
|---|---|---|
| `bed_config` | Cấu hình giường | Physical bed arrangement in the room |
| `extra_item` | Thêm đồ dùng | Countable physical items to place in the room |
| `decoration` | Trang trí | Event decorations and special room presentation |
| `accessibility` | Hỗ trợ đặc biệt | Accessibility and mobility accommodations |
| `general` | Yêu cầu khác | Catch-all for operational requests that don't fit above |

### Predefined `request_type` Codes (frontend catalog — no migration needed to extend)

**`bed_config`**
| Code | Label (VI) |
|---|---|
| `twin_keep` | Giữ nguyên giường đôi |
| `twin_to_double` | Ghép giường thành đôi |
| `separate_beds` | Tách giường |
| `extra_bed` | Thêm giường phụ |

**`extra_item`**
| Code | Label (VI) |
|---|---|
| `baby_cot` | Cũi em bé |
| `extra_pillow` | Gối thêm |
| `non_feather_pillow` | Gối không lông vũ |
| `extra_blanket` | Chăn thêm |
| `extra_towel` | Khăn thêm |
| `welcome_fruit` | Trái cây chào đón |
| `welcome_amenity` | Đồ dùng VIP |

**`decoration`**
| Code | Label (VI) |
|---|---|
| `anniversary` | Trang trí kỷ niệm |
| `honeymoon` | Trang trí tuần trăng mật |
| `birthday` | Trang trí sinh nhật |
| `vip_setup` | Thiết lập VIP |
| `flower_arrangement` | Cắm hoa |

**`accessibility`**
| Code | Label (VI) |
|---|---|
| `wheelchair` | Xe lăn |
| `non_smoking_prep` | Dọn phòng không hút thuốc |
| `ground_floor` | Tầng trệt (ưu tiên) |
| `near_elevator` | Gần thang máy |

**`general`**
| Code | Label (VI) |
|---|---|
| `late_arrival` | Đến muộn |
| `airport_pickup` | Đón sân bay |
| `connecting_room` | Phòng liên thông |
| `other` | Khác (xem ghi chú) |

Adding a new type requires only: (1) add a row to the frontend catalog array, (2) no migration, no enum change.

### `RequestStatus` Enum

| Value | Label (VI) | Transitions |
|---|---|---|
| `pending` | Chờ xử lý | → acknowledged, cancelled |
| `acknowledged` | Đã tiếp nhận | → fulfilled, cancelled |
| `fulfilled` | Đã hoàn thành | terminal |
| `cancelled` | Đã hủy | terminal |

---

## 6. Operational Lifecycle

### Request Flow

```
1. Guest communicates request (phone / at check-in / booking note)
   └── Staff records via Booking Detail → Yêu cầu tab
       booking_id set, stay_id NULL (room not yet assigned)

2. Room Assignment
   └── Staff may optionally link request to specific stay
       (or system auto-links based on room type / bed count match)
       stay_id → stay.id

3. Pre-arrival housekeeping
   └── Housekeeping views pending requests for rooms checking in today
       [Future: Housekeeping Board]
       Status → acknowledged

4. Room preparation
   └── Housekeeping completes setup (twin joined, cot placed, etc.)
       Status → fulfilled, fulfilled_by, fulfilled_at set

5. Check-in
   └── Front desk confirms setup with guest
       If fulfilled → proceed normally
       If still pending → alert front desk to arrange immediately
```

### Auto-link to Stay

When `StayService::createStayFromAssignment()` is called:
- Look for `BookingSpecialRequest` records where `booking_id = $booking->id AND stay_id IS NULL`
- For `bed_config` category: auto-link to the stay matching the room type (if unambiguous)
- For all other categories: leave `stay_id = null` unless the booking has exactly one active stay (auto-link in that case)

This logic is simple and avoids over-engineering. For multi-room bookings with ambiguous requests, staff links manually via the UI.

---

## 7. UI Design

### 7.1 Booking Detail — Yêu cầu Tab

New tab in the existing Booking Detail (`Show.vue`). Tab badge shows pending request count.

```
┌──────────────────────────────────────────────────────────────────┐
│  THÔNG TIN │ PHÒNG │ TÀI CHÍNH │ YÊU CẦU (2)                    │
│                                                                  │
│  Yêu cầu đặc biệt                    [ + Thêm yêu cầu ]         │
│                                                                  │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │ # │ Loại         │ Yêu cầu          │ SL │ Phòng │ Trạng thái │ │
│  │ 1 │ Cấu hình giường│ Ghép giường đôi │ 1  │ --    │ Chờ xử lý│ │
│  │ 2 │ Trang trí     │ Tuần trăng mật  │ 1  │ 301   │ Đã tiếp nhận│ │
│  └────────────────────────────────────────────────────────────┘  │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

"Phòng" column shows room number when `stay_id` is linked; `--` when unattributed.

**Add Request form:**
```
Loại:        [Cấu hình giường ▾]
Yêu cầu:     [Ghép giường thành đôi ▾]   (filtered by category)
Số lượng:    [1]
Phòng:       [Tùy chọn ▾]               (stays dropdown, shown only if stays exist)
Ghi chú:     [                      ]   (free text)
             [Hủy]  [Thêm yêu cầu]
```

### 7.2 Room Board — Visual Indicator

In the existing Room Board (already built in Phase 2.x), show a small indicator badge on rooms that have pending/acknowledged requests:

```
┌──────────────────┐
│  301  TWIN  ■ 2  │   ← "■ 2" = 2 pending setup requests
│  Nguyễn Văn A    │
│  01/07 - 03/07   │
└──────────────────┘
```

Clicking the room card shows a tooltip or modal that includes the request list. No separate page needed.

### 7.3 Stay Detail (within Booking)

When viewing a Stay card inside the Booking Detail (existing stay section), show an inline request list:

```
Phòng 301 — TWIN
Nhận phòng: 01/07  |  Trả phòng: 03/07
Yêu cầu: Ghép giường thành đôi ✓  •  Tuần trăng mật ⏳
```

Icons: ✓ = fulfilled, ⏳ = pending/acknowledged, ✗ = cancelled.

### 7.4 Future: Housekeeping Board

A dedicated `/admin/housekeeping` page (Phase 4.2) will show:
- Rooms checking in today, grouped by floor
- Setup requests per room with fulfillment status
- Quick "Mark as fulfilled" action

This page is outside Phase 4.1 scope. Phase 4.1 provides the data foundation for it.

### 7.5 Visibility Rules

| UI Surface | Visible to |
|---|---|
| Yêu cầu tab — full access (create, acknowledge, fulfill, cancel) | ADMIN, MANAGER |
| Yêu cầu tab — create + cancel (not fulfill) | RECEPTION |
| Yêu cầu tab — restricted mode (view, acknowledge, fulfill only) *(ADR-81)* | HOUSEKEEPING |
| Room Board indicator | ADMIN, MANAGER, RECEPTION, HOUSEKEEPING |
| Finance / Folio tab | Not shown (requests are not financial) |
| Payment section | Not shown |
| Sales / external reports | Not shown |

**HOUSEKEEPING Restricted Mode** *(ADR-81 — Option A)*

HOUSEKEEPING accesses the "Yêu cầu" tab in read + fulfill mode:
- ✅ View all requests for the booking
- ✅ Acknowledge a pending request (`pending → acknowledged`)
- ✅ Fulfill an acknowledged request (`acknowledged → fulfilled`)
- ❌ Cannot create requests
- ❌ Cannot cancel requests
- ❌ Cannot edit request details

UI renders action buttons conditionally via `hasPermission()` check. Backend `BookingSpecialRequestPolicy` enforces all permission gates server-side regardless of UI state.

New permissions:
- `special_request.create` → ADMIN, MANAGER, RECEPTION
- `special_request.fulfill` → ADMIN, MANAGER, HOUSEKEEPING *(covers both acknowledge and fulfill transitions; no separate `special_request.acknowledge` permission)*
- `special_request.cancel` → ADMIN, MANAGER

---

## 8. Future Extensibility

### Adding New Request Types — Zero Migration Required

To add "Birthday decoration with balloon":
1. Add `{ code: 'birthday_balloon', label: 'Trang trí bong bóng sinh nhật' }` to the frontend `decoration` array in the Vue component
2. Deploy frontend. Done.

The database stores the code string; it has no opinion on what valid codes are. The frontend catalog controls what staff can select. Free-text `note` handles anything not in the catalog.

### Adding New Categories — One Code Change, No Migration

To add a `maintenance` category:
1. Add `MaintenanceRequest` case to `RequestCategory` enum
2. Add the new category to the frontend category selector

No migration needed — `category` is VARCHAR(32), not a DB enum.

### Handling Complex Future Requests

| Future Request | How It Fits |
|---|---|
| Birthday decoration with guest name | `{category: decoration, type: birthday, note: "Họ tên: Nguyễn Văn A, 30 tuổi"}` |
| Airport pickup with flight details | `{category: general, type: airport_pickup, note: "Chuyến VN123, đến 14:30"}` |
| Extra towels (3 sets) | `{category: extra_item, type: extra_towel, quantity: 3}` |
| Wheelchair + ground floor | Two separate requests: `wheelchair` + `ground_floor` |
| Connecting rooms | `{category: general, type: connecting_room, note: "Phòng 301-302"}` |
| Welcome fruit basket | `{category: extra_item, type: welcome_fruit, quantity: 1}` |
| Late arrival after 23:00 | `{category: general, type: late_arrival, note: "Dự kiến 23:30"}` |

### Future Catalog Table (Phase 4.2 optional)

If the list of request types grows long enough that hardcoding in the frontend becomes unmanageable, a `special_request_types` catalog table can be added:

```sql
CREATE TABLE special_request_types (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category      VARCHAR(32) NOT NULL,
    code          VARCHAR(64) NOT NULL UNIQUE,
    label_vi      VARCHAR(100) NOT NULL,
    is_active     BOOLEAN NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0
);
```

This is an optional upgrade. The core `booking_special_requests` schema does not change when this catalog is added. Existing records are unaffected — they already store `category + request_type` as strings.

---

## 9. Relationship with Housekeeping Module

Phase 4.1 (this module) is the **data foundation** for a future Housekeeping module. It introduces:
- The `booking_special_requests` table — what needs to be done
- The `status` lifecycle — pending → acknowledged → fulfilled
- The `stay_id` link — which room to prepare

Future Housekeeping module phases can build on top:

| Phase | Capability | Depends on |
|---|---|---|
| 4.1 | Room Setup Requests (this doc) | Phase 3.3 complete ✅ |
| 4.2 | Housekeeping Board — daily task view per room | Phase 4.1 data |
| 4.3 | Room Cleaning Status — clean/dirty/inspected per room | Phase 4.1 data |
| ~~4.4~~ | ~~Night Audit — nightly room charge posting~~ | **DELIVERED IN PHASE 3.2** — not a Phase 4 item |
| 4.4 | Housekeeping Assignments — assign tasks to staff | Phase 4.2 Board |

Phase 4.1 does NOT implement the Housekeeping Board, cleaning status, or task assignment. It provides the requests data that those features display.

---

## 10. Risks

### R1 — Request-to-Stay Auto-Link Ambiguity

**Risk:** A booking has 2 TWIN rooms. Guest requests "twin → double." The auto-link logic cannot determine which of the two stays to assign the request to.

**Mitigation:** For `bed_config` requests, do NOT auto-link when multiple stays exist. Leave `stay_id = null` and require staff to link manually. Show a UI prompt: "Yêu cầu chưa được gán cho phòng cụ thể — vui lòng chọn phòng."

### R2 — Request Status Not Reflecting Reality

**Risk:** A request is marked `fulfilled` by Housekeeping but the setup was actually not done (miscommunication). Guest arrives to an unprepared room.

**Mitigation:** At check-in, Front Desk sees fulfilled request status in the Stay summary. Staff is expected to verify. A future "Guest Confirmation" step (Phase 4.x) could add a `confirmed_by_guest` flag.

### R3 — Requests Orphaned on Cancellation

**Risk:** Booking is cancelled after requests are recorded. Requests remain in `pending` state on a cancelled booking.

**Mitigation:** When `BookingService::cancelBooking()` is called, auto-cancel all pending/acknowledged requests on that booking. A single `UPDATE booking_special_requests SET status = 'cancelled' WHERE booking_id = ? AND status NOT IN ('fulfilled', 'cancelled')` inside the cancel transaction.

### R4 — Finance Team Sees Operational Clutter

**Risk:** Finance staff (ACCOUNTANT role) starts using the request tab, confusing operational data with financial data.

**Mitigation:** The Yêu cầu tab is not shown to ACCOUNTANT role. `special_request.create` and `special_request.fulfill` permissions are not granted to ACCOUNTANT. Finance remains in the Tài chính tab only.

### R5 — `request_type` String Drift

**Risk:** Staff somehow enters an invalid `request_type` code that doesn't match the frontend catalog (e.g., via direct API call). The database accepts any string.

**Mitigation:** `StoreBookingSpecialRequestRequest` validates `request_type` against the known catalog (a PHP constant array in the Request class). Unknown codes are rejected with a validation error. The database stores only validated strings.

---

## 11. Implementation Plan

### Pre-conditions

- [x] Phase 3.3 Service Charges complete and committed *(done: commit 9935423, tag phase-3.3.6.3)*
- [x] All Phase 3.x tests passing *(553 tests / 26 files / 311 assertions)*
- [x] Architecture review approved *(PASS — 2026-07-04)*

### Phase 4.1.1 — Backend Foundation (1 day)

1. Migration: `create_booking_special_requests_table` — use finalised DDL from §4 (ADR-80, ADR-82, ADR-83)
2. Enum: `RequestCategory` (bed_config, extra_item, decoration, accessibility, general)
3. Enum: `RequestStatus` (pending, acknowledged, fulfilled, cancelled)
4. Model: `BookingSpecialRequest` — fillable, casts, relationships, scopes
5. **Register AuditObserver:** add `BookingSpecialRequest::observe(AuditObserver::class)` in `AppServiceProvider.php` after `Stay::observe()` (line 92)
6. Model: `Booking` — add `hasMany(BookingSpecialRequest::class)` relationship
7. Model: `Stay` — add `hasMany(BookingSpecialRequest::class)` relationship
8. Policy: `BookingSpecialRequestPolicy` (create, fulfill, cancel per ADR-81 permission matrix)
9. Service: `SpecialRequestService` — `addRequest()`, `linkToStay()`, `acknowledge()`, `fulfill()`, `cancel()`, `autoCancelForBooking()`, `autoLinkSingleStayRequests()`
   - `addRequest()` MUST guard terminal booking statuses (CHECKED_OUT, CANCELLED, NO_SHOW) — follow `BookingPaymentService` pattern
   - `autoLinkSingleStayRequests()` MUST be non-throwing: wrap in try/catch, log on failure, never propagate exception to `StayService::createStayFromAssignment()`
10. Controller: `BookingSpecialRequestController` — store, update (status change: acknowledge/fulfill), destroy (cancel)
11. Requests: `StoreBookingSpecialRequestRequest` (validates category, request_type against catalog, quantity ≥ 1, terminal booking guard)
12. Routes: nested under `/bookings/{booking}/special-requests`
13. Wire cancel hook: `BookingService::cancelBooking()` calls `SpecialRequestService::autoCancelForBooking()` **inside the existing DB::transaction**
14. Wire auto-link hook: `StayService::createStayFromAssignment()` calls `SpecialRequestService::autoLinkSingleStayRequests()` after `firstOrCreate` — non-throwing (see item 9)
15. Seed: `special_request.create` → ADMIN, MANAGER, RECEPTION; `special_request.fulfill` → ADMIN, MANAGER, HOUSEKEEPING; `special_request.cancel` → ADMIN, MANAGER
16. Backend: add pending request count query for Room Board — a scope or query method that returns `[room_id => pending_count]` for all rooms with active stays
17. Tests: `SpecialRequestCrudTest` (≥ 12 tests)

### Phase 4.1.2 — Frontend (1 day)

1. New Yêu cầu tab in `Show.vue` — visible to ADMIN, MANAGER, RECEPTION, HOUSEKEEPING (all roles)
   - Render "Add Request" form only if `hasPermission('special_request.create')` (ADMIN, MANAGER, RECEPTION)
   - Render acknowledge/fulfill actions only if `hasPermission('special_request.fulfill')` (ADMIN, MANAGER, HOUSEKEEPING)
   - Render cancel action only if `hasPermission('special_request.cancel')` (ADMIN, MANAGER)
   - HOUSEKEEPING sees restricted mode: view + acknowledge + fulfill only (ADR-81)
2. Request list table (category, type label, quantity, room, status, actions)
3. Add Request form (category selector → filtered type selector, quantity, optional stay, note)
4. Request status badges: pending (yellow), acknowledged (blue), fulfilled (green), cancelled (gray)
5. Room Board: indicator badge showing pending request count per room — consumed from item 16 backend query
6. Stay summary line: inline request list with status icons (✓ fulfilled, ⏳ pending/acknowledged, ✗ cancelled)

### Phase 4.1.3 — Tests and Polish (0.5 day)

1. Policy unit tests: `BookingSpecialRequestPolicyTest`
   - HOUSEKEEPING can fulfill/acknowledge; cannot create/cancel (ADR-81)
   - RECEPTION can create; cannot cancel/fulfill
2. Feature tests: cancellation auto-cancel, auto-link single stay, multi-stay no-auto-link
3. Feature test: `autoLinkSingleStayRequests()` failure does not abort stay creation
4. E2E: add request (RECEPTION) → acknowledge (HOUSEKEEPING) → fulfill (HOUSEKEEPING) → verify status in Room Board

### Total Estimated Effort: 2.5 days

---

## 12. Ready for Implementation

**YES — Phase 4.1 is ready for implementation.**

All prerequisites are met as of 2026-07-04:

- [x] Phase 3.1 (Payment Foundation) — complete, commit `68abffa`, tag `phase-3.1`
- [x] Phase 3.2 (Folio Foundation + Night Audit Pipeline) — complete, commit `e1ac762`, tag `phase-3.2`
- [x] Phase 3.3 (Service Charges + Extra Charges Pipeline) — complete, commit `9935423`, tag `phase-3.3.6.3`
- [x] Architecture Review — **PASS** (2026-07-04)
- [x] ADR-80 through ADR-83 — accepted
- [x] All architecture blockers resolved

Phase 4.1 is self-contained, non-breaking, and adds no risk to existing financial flows. It may begin immediately.
