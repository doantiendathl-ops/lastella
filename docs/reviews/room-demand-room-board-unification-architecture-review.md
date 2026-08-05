# Room Demand and Room Board Unification — Architecture Review

**Ngày viết ban đầu:** 2026-08-04
**Ngày sửa REVISION 2:** 2026-08-05
**Ngày sửa REVISION 3 (Final Correction):** 2026-08-05
**Nhánh:** `phase-3`
**Phạm vi:** Chỉ điều tra + thiết kế. Không sửa code, không sửa database, không migration, không seeder, không commit.
**Trạng thái tài liệu:** REVISION 3 — chốt theo 14 quyết định Product Owner (Mục I của yêu cầu sửa) + sửa Locking Plan (tách 2 thứ tự riêng cho assign/release), thu gọn Milestone 1, làm atomic mapping ở Milestone 2, đổi FK sang `restrictOnDelete`, khoá cả 3 method requirement, chốt xử lý legacy ambiguous/folio-locked nghiêm ngặt hơn, thêm guest fields cho requirement mới, chốt schema `release_batches`, hoàn thiện State Matrix (không còn ô "cần xác nhận").
**ChatGPT review:** APPROVED FOR MILESTONE 1 COMMIT.
**Milestone 1 implementation:** COMPLETE (xem `docs/reports/room-demand-room-board-unification-milestone-1-report.md`) — chỉ là nền tảng dữ liệu và locking (migration `booking_requirement_id`, FK `restrictOnDelete()`, khoá 3 method requirement, backfill command, allocation helper thuần túy). **Không được coi đây là chức năng Room-Board-first đã hoàn thành** — chưa có reverse synchronization, chưa có atomic mapping cho assignment mới, chưa có Stay atomic flow mới, chưa có UI chọn giá/dòng nhu cầu, chưa có bulk release, chưa có `release_batches`/`release_batch_id`. Không deploy production dựa trên Milestone 1. **Milestone 2 chưa bắt đầu.**

---

## 0. Revision Changelog

**REVISION 1 (2026-08-04)** có 2 lỗi thiết kế nền tảng (thuật toán demand sync double-count; không có liên kết Assignment↔Requirement) — đã sửa ở REVISION 2.

**REVISION 2 (2026-08-05)** sửa đúng 2 lỗi trên nhưng vẫn còn các thiếu sót ChatGPT review phát hiện lần 2, đã sửa ở REVISION 3 này:

1. **Locking Plan dùng 1 canonical order chung cho cả assign và release** — sai vì `releaseAssignment()` hiện tại lock `Stay → RoomAssignment` (không phải `RoomAssignment → Stay`). REVISION 3 tách riêng thứ tự A (assign) và B (release) — Mục 20.
2. **Milestone 1 REVISION 2 vẫn chứa 2 method nghiệp vụ hoàn chỉnh** (`assignRoomsWithDemandSync`, `releaseAssignments`) — quá rộng cho 1 milestone "chỉ chốt data/locking". REVISION 3 thu gọn Milestone 1 chỉ còn migration + model + FK + backfill + lock 3 method requirement + allocation helper thuần tuý (chưa nối orchestration) — Implementation Plan Mục 5.
3. **Milestone 2 REVISION 2 cho phép gắn `booking_requirement_id` ở transaction thứ hai SAU KHI `assignRooms()` đã commit** — vi phạm nguyên tắc atomic. REVISION 3 bắt buộc gắn trong CÙNG transaction tạo assignment, qua helper dùng chung — Mục 18.
4. **FK `nullOnDelete()`** — Product Owner chốt `restrictOnDelete()` thay vì `nullOnDelete()` để chặn cứng việc xoá requirement đã từng được tham chiếu — Mục 15.
5. **Chỉ khoá `updateRequirement()`** — Product Owner yêu cầu khoá cả 3 method (`addRequirement`, `updateRequirement`, `deleteRequirement`) — Mục 20/25.
6. **Xử lý legacy ambiguous khi release "bỏ qua riêng phần đó, giảm phần còn lại"** — Product Owner chốt nghiêm ngặt hơn: có bất kỳ assignment NULL nào trong batch mà `reduce_demand=true` → từ chối TOÀN BỘ request trước khi ghi, không giảm một phần — Mục 19.
7. **Chưa xử lý trường hợp dòng requirement khớp bị khoá bởi folio room charge khi cần tăng excess, hoặc khi release cần giảm đúng dòng đã khoá** — bổ sung Mục 14b/19.
8. **Chưa có guest fields (`adults`/`children_under_6`/`children_over_6`) cho dòng requirement MỚI tạo từ Room-Board-first** — bổ sung Mục 14b/24.
9. **State Matrix còn nhiều ô "cần Product Owner xác nhận"** — Product Owner đã chốt toàn bộ, không còn ô mở — Mục 22.

**Kết luận readiness đổi từ NOT READY (REVISION 2) sang READY FOR IMPLEMENTATION PLAN WITH CONDITIONS (REVISION 3) — xem Mục 32.**

---

## 1. Executive Summary

Phát hiện nền tảng không đổi qua 3 revision: hạ tầng multi-select room assignment đã tồn tại và đã có test (`RoomAssignmentController::store()`, `RoomAssignmentService::assignRooms()`, `Show.vue` `selectedRoomIds`). Khoảng trống thật sự vẫn là: (1) chọn phòng trên Room Board không đồng bộ ngược vào `booking_requirements`; (2) gỡ nhiều phòng không atomic, không truy vết đúng dòng demand bị giảm.

REVISION 1 sai ở thuật toán demand sync (double-count) và thiếu liên kết Assignment↔Requirement. REVISION 2 sửa 2 lỗi đó nhưng Locking Plan còn dùng 1 thứ tự chung sai cho cả assign/release, Milestone 1 còn ôm quá nhiều nghiệp vụ, và một số quy tắc xử lý biên (legacy ambiguous, folio-locked, guest fields) chưa được chốt nghiêm ngặt.

**REVISION 3 này chốt toàn bộ 14 quyết định Product Owner** (Mục I của yêu cầu sửa), tách Locking Plan thành 2 thứ tự riêng biệt cho assign và release (Mục 20), thu gọn Milestone 1 (Implementation Plan Mục 5), bắt buộc atomic mapping ngay từ Milestone 2 (Mục 18), đổi FK sang `restrictOnDelete` (Mục 15), khoá đủ 3 method requirement (Mục 20), và chốt cứng các quy tắc biên còn lại. Kết luận: **READY FOR IMPLEMENTATION PLAN WITH CONDITIONS** — Milestone 1 có thể bắt đầu ngay sau khi tài liệu này được duyệt.

---

## 2. Product Requirement

Hợp nhất 2 chiều giữa "Nhu cầu phòng" (room demand) và "Phân phòng/Sơ đồ phòng" (Room Board):
- Giữ nguyên luồng nhập nhu cầu trước → mở Room Board chọn phòng (đã có, không đổi).
- Cho phép chọn phòng trực tiếp trên Room Board trước, hệ thống tự tạo/cập nhật nhu cầu tương ứng.
- Cho phép multi-select cả lúc gán và lúc gỡ, với giá/nguồn giá/ghi chú theo nhóm loại phòng, atomic toàn batch.
- Có quy tắc rõ ràng cho việc nhu cầu có bị giảm theo khi gỡ phòng hay không, và giảm đúng dòng giá nào.

## 3. Current User Flows

**Luồng A — Nhu cầu trước (đã có, không đổi):**
1. `BookingRequirementController::store()` → `BookingService::addRequirement()` → tạo 1 dòng `booking_requirements`.
2. Mở tab `room_map`, xem `assignmentSummary`.
3. Chọn phòng trên Room Board (`selectedRoomIds` trong `Show.vue`).
4. Bấm "Lưu phân phòng" → `RoomAssignmentController::store()` → `RoomAssignmentService::assignRooms()`.

**Luồng B — Chọn phòng trước (một phần đã có, thiếu đồng bộ ngược):** `toggleRoomSelection()` không chặn chọn vượt/khác loại nhu cầu, chỉ cảnh báo `window.confirm()`. Sau khi xác nhận, `RoomAssignmentController::store()` chỉ tạo `RoomAssignment`, không tạo/cập nhật `BookingRequirement`.

**Luồng gỡ phòng:** gỡ 1 phòng qua `releaseAssignment()`. "Giải phóng tất cả" (`releaseAll()`) lặp tuần tự `router.post()`, không transaction, không atomic.

## 4. Current Architecture

Controller mỏng, service dày: `RoomAssignmentController` → `RoomAssignmentService` → `RoomAvailabilityRuleService`. `BookingRequirementController` → `BookingService`. Hai luồng không gọi nhau — độc lập ở tầng service.

## 5. Current Data Model

```
bookings 1───* booking_requirements *───1 room_types
bookings 1───* room_assignments *───1 rooms, *───1 room_types
room_assignments 1───1 stays
```

- `booking_requirements`: `booking_id`, `room_type_id`, `quantity`, `adults`, `children_under_6`, `children_over_6`, `room_price`, `price_source`, `note`. Index thường `(booking_id, room_type_id)`, KHÔNG unique.
- `room_assignments`: `booking_id`, `room_id`, `room_type_id`, `start_at`, `end_at`, `status`, `assigned_by`, `released_by`, `released_at`, `release_reason` (đã có sẵn). Không có cột giá, không có `booking_requirement_id`.
- `RoomAssignment`/`BookingRequirement` model: xác nhận không có relationship nào nối 2 bảng.

## 6. Demand and Assignment Source of Truth

Không có nguồn sự thật duy nhất — `getAssignmentSummary()`, `roomAssignmentMismatch()`, `updateBookingAssignmentStatus()` đều tự tính lại ở mức tổng room_type tại thời điểm đọc.

## 7. Current Pricing Model

Giá chỉ tồn tại ở `booking_requirements.room_price` + `price_source`. `room_assignments` không lưu giá. 1 room_type có thể có nhiều dòng nhu cầu khác giá — đã đúng schema hiện tại.

## 8. Current Assignment Flow

`assignRooms()`: sort `room_id` asc trước transaction → lock `Room`, recheck conflict, tạo `RoomAssignment` → `updateBookingAssignmentStatus()`. Sau khi transaction COMMIT, `RoomAssignmentController::store()` (dòng 50-52) lặp gọi `createStayFromAssignment()` **NGOÀI transaction** — xác nhận qua đọc code trực tiếp.

## 9. Current Release Flow

`releaseAssignment()` (đơn lẻ): lock `Stay` rồi lock `RoomAssignment` — **không lock `Booking`, không lock `Room`**. "Giải phóng tất cả" ở frontend không atomic.

## 10. Room Availability and Concurrency

`RoomAvailabilityRuleService` dùng chung bởi assign/release/room board/availability checker — không đổi.

**Locking hiện tại — đọc lại toàn bộ code, xác nhận KHÔNG nhất quán:**

| Method | Lock thực tế | Lock `Booking`? |
|---|---|---|
| `assignRooms()` | `Room` (sort asc) | Không |
| `releaseAssignment()` | `Stay` → `RoomAssignment` | Không |
| `addRequirement()` | Không lock gì, **không có `DB::transaction()`** | Không |
| `updateRequirement()` | `FolioEntry` (kiểm tra khoá charge), có `DB::transaction()` | Không |
| `deleteRequirement()` | Không lock gì, **không có `DB::transaction()`** | Không |
| `StayService::checkIn()` (ADR-38) | `Booking` → `Stay` → `RoomAssignment` | **Có, đầu tiên** |
| `StayService::moveRoom()` | `Room(mới)` → `Stay` → `RoomAssignment` → `Room(cũ)` | Không (cố ý) |

**Phát hiện quan trọng cho Mục 20:** `addRequirement()` và `deleteRequirement()` hiện **không hề có `DB::transaction()`** — khác với REVISION 2 giả định (REVISION 2 chỉ nói cần vá `updateRequirement()`). Muốn khoá `Booking` cho 2 method này, phải BỌC chúng trong `DB::transaction()` trước — đây là thay đổi lớn hơn REVISION 2 từng ước tính.

## 11. Permission and Audit Findings

Đọc trực tiếp `database/seeders/RolePermissionSeeder.php`:

| Permission | ADMIN | MANAGER | SALES | RECEPTION | HOUSEKEEPING | ACCOUNTANT |
|---|---|---|---|---|---|---|
| `room.assign` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| `room.unassign` | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| `booking.update` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |

3 role có `room.*` (ADMIN, MANAGER, RECEPTION) đều có `booking.update` — nhưng `RoleController` (`roles.manage`) cho phép tạo role tuỳ ý, nên thiết kế phân quyền không được giả định 2 quyền luôn đi cùng nhau (Mục 23).

Audit: `RoomAssignment`, `BookingRequirement`, `Booking`, `Stay` đã đăng ký `AuditObserver`. Model mới `ReleaseBatch` (Mục 17) cần đăng ký thêm — 1 dòng trong `AppServiceProvider::boot()`, đúng convention 24 model hiện có.

## 12. Gaps Against New Requirement

| Yêu cầu mới | Hiện trạng |
|---|---|
| Multi-select assign / atomic bulk assign | ✅ Đã có |
| Nhập giá/nguồn giá/ghi chú khi chọn trên Room Board | ❌ Chưa có |
| Đồng bộ ngược không double-count | ✅ Đã sửa Mục 14 (REVISION 2, giữ nguyên REVISION 3) |
| Truy vết Assignment ↔ Requirement | ✅ Đã thiết kế Mục 15, FK siết chặt hơn REVISION 2 |
| Atomic mapping ngay khi tạo (không 2 transaction) | ✅ Đã sửa Mục 18 (REVISION 3) |
| Bulk release atomic, giảm đúng dòng | ✅ Đã sửa Mục 19, nghiêm ngặt hơn REVISION 2 |
| `release_batches` đầy đủ trường | ✅ Đã chốt schema Mục 17 |
| Locking Plan tách đúng theo từng nghiệp vụ | ✅ Đã sửa Mục 20 (REVISION 3) |
| Khoá đủ 3 method requirement | ✅ Đã sửa Mục 20 (REVISION 3) |
| Guest fields cho requirement mới | ✅ Đã bổ sung Mục 14b/24 (REVISION 3) |
| Xử lý folio-locked khi excess/release | ✅ Đã bổ sung Mục 14b/19 (REVISION 3) |
| State Matrix không còn ô mở | ✅ Đã chốt Mục 22 (REVISION 3) |

---

## 13. Critical Design Corrections Across 3 Revisions (tóm tắt)

| # | Lỗi/thiếu sót | Revision phát hiện | Đã sửa ở |
|---|---|---|---|
| 1 | `quantity += selected_count` thay vì `+= excess` | REV1→REV2 | Mục 14 |
| 2 | Không liên kết Assignment↔Requirement | REV1→REV2 | Mục 15 |
| 3 | "Chỉ 1 migration" | REV1→REV2 | Mục 16 |
| 4 | Thiếu `release_note`, Locking Plan sơ sài, thiếu Stay atomicity/State/Authorization Matrix | REV1→REV2 | Mục 17, 20, 21, 22, 23 |
| 5 | 1 canonical lock order dùng chung sai cho assign/release | REV2→REV3 | Mục 20 |
| 6 | Milestone 1 ôm cả nghiệp vụ bulk release | REV2→REV3 | Implementation Plan Mục 5 |
| 7 | Mapping 2 transaction (không atomic) ở Milestone 2 | REV2→REV3 | Mục 18 |
| 8 | FK `nullOnDelete` quá lỏng | REV2→REV3 | Mục 15 |
| 9 | Chỉ khoá `updateRequirement()` | REV2→REV3 | Mục 20 |
| 10 | Giảm demand một phần khi có legacy ambiguous | REV2→REV3 | Mục 19 |
| 11 | Chưa xử lý folio-locked requirement khi excess/release | REV2→REV3 | Mục 14b/19 |
| 12 | Chưa có guest fields cho requirement mới | REV2→REV3 | Mục 14b/24 |
| 13 | State Matrix còn ô mở | REV2→REV3 | Mục 22 |

---

## 14. Corrected Demand Synchronization Algorithm

### 14a. Cấp A — Room-type total reconciliation (không đổi từ REVISION 2)

```
required        = SUM(booking_requirements.quantity WHERE booking_id, room_type_id)
assigned_active  = COUNT(room_assignments WHERE booking_id, room_type_id, status IN [Assigned, CheckedIn, CheckedOut])
remaining        = MAX(required - assigned_active, 0)
excess_to_add    = MAX(selected_count - remaining, 0)
```
Chỉ `excess_to_add` được phép tạo dòng mới/tăng `quantity` — không bao giờ dùng `selected_count` trực tiếp.

### 14b. Cấp B — Requirement-line allocation (giữ nguyên tắc REVISION 2, bổ sung 2 quy tắc mới REVISION 3)

1. Room_type chỉ có 1 dòng active: không mơ hồ, gắn trực tiếp, không hỏi UI.
2. Room_type có ≥ 2 dòng active còn `remaining line > 0`: **Product Owner đã chốt Quyết định #10 (Mục I) — bắt buộc người dùng chọn `target_requirement_id` qua UI, không có phương án "mặc định FIFO" nào khác được chấp nhận.** Đây không còn là khuyến nghị (REVISION 2) mà là quy tắc CỐ ĐỊNH.
3. Không bao giờ dùng thuật toán suy đoán "dòng lớn nhất trước".
4. Phần `excess_to_add`: nếu giá/nguồn giá khớp CHÍNH XÁC 1 dòng đã tồn tại → tăng `quantity` dòng đó. Nếu không khớp → tạo dòng mới.
5. **Bảo vệ khoá folio (REVISION 3 — chốt cứng, không còn là "phải tái dùng guard" chung chung):** nếu dòng khớp theo bước 4 đã bị khoá bởi `RequirementLockedAfterRoomChargeException` (đã có `FolioEntry` loại Room chưa void) → **KHÔNG được sửa dòng đó**. Thay vào đó, tạo 1 dòng `booking_requirements` MỚI, cùng `room_type_id`/`room_price`/`price_source` với dòng bị khoá, `quantity = excess_to_add`, gắn assignment mới vào dòng MỚI này (không phải dòng bị khoá). Kết quả: có thể tồn tại 2 dòng cùng khoá gộp `(room_type_id, room_price, price_source)` cho cùng 1 booking — 1 dòng cũ (đã khoá, đã tính tiền) và 1 dòng mới (chưa khoá) — đây là hệ quả CHẤP NHẬN ĐƯỢC, không coi là lỗi trùng lặp, vì mục đích là không bao giờ sửa ngược dữ liệu đã tính vào folio.
6. **Guest fields cho dòng requirement MỚI (REVISION 3 — mới, Mục IX của yêu cầu sửa):** khi bước 4 hoặc bước 5 tạo dòng MỚI, panel xác nhận (Mục 24) phải cho nhập `adults`/`children_under_6`/`children_over_6` — mặc định `adults = quantity` (= `excess_to_add`, số phòng mới), `children_under_6 = 0`, `children_over_6 = 0`, người dùng được sửa trước khi xác nhận. **Không bao giờ tự động đổi guest fields của dòng requirement ĐÃ TỒN TẠI** (bước 1, 2, 4-không-khoá chỉ tăng `quantity`, không đụng `adults`/`children_*` của dòng đó).
7. Mỗi assignment mới tạo qua các luồng MỚI (M2 atomic-linked demand-first, M3 Room-Board-first) **bắt buộc có `booking_requirement_id` khác NULL** — nếu không xác định được (trường hợp mơ hồ mà UI chưa cung cấp `target_requirement_id`), request bị từ chối bằng lỗi validate, KHÔNG được tạo assignment với `booking_requirement_id = NULL` (Quyết định #8, Mục I). Chỉ assignment LEGACY (tạo trước khi cột này tồn tại, qua `assignRooms()` gốc không qua luồng mới) mới được phép `NULL`.
8. Release với `reduce_demand=true`: xem Mục 19 — đã sửa nghiêm ngặt hơn REVISION 2.

### 14c. Khoá gộp (merge key)

`(room_type_id, room_price, price_source)` — không đổi, không gồm `note`.

---

## 15. Assignment–Requirement Mapping Design

**Product Owner đã chốt Quyết định #8 (Mục I): Phương án A.**

- Cột: `room_assignments.booking_requirement_id`, nullable (hỗ trợ legacy), FK.
- **FK on-delete: `restrictOnDelete()` — KHÔNG dùng `nullOnDelete()` như REVISION 2 khuyến nghị.** Lý do (Product Owner Mục V): không được phép hard-delete 1 `BookingRequirement` đang có bất kỳ `RoomAssignment` nào tham chiếu (kể cả đã `Released`) — `restrictOnDelete()` là lớp bảo vệ DB-level cuối cùng, khớp đúng với guard tầng ứng dụng (Mục 25) chặn hard delete SỚM HƠN với thông báo lỗi thân thiện. `nullOnDelete()` sẽ ÂM THẦM xoá liên kết lịch sử của các assignment đã release — vi phạm trực tiếp yêu cầu "không hard delete requirement đã có assignment tham chiếu, kể cả assignment đã released".
- **Assignment mới qua luồng mới (M2 atomic-linked, M3 Room-Board-first) không được NULL** (Mục 14b điểm 7) — khác REVISION 2 (chỉ khuyến nghị "nên set khi có thể").
- Backfill (Milestone 1, không đổi nguyên tắc từ REVISION 2): 1 dòng khớp → backfill; ≥2 dòng → NULL + báo cáo "ambiguous"; 0 dòng → NULL + báo cáo "no matching requirement". Không đoán.
- Move room (`StayService::moveRoom()`): xác nhận lại không cần sửa — không đụng `room_type_id`/"commercial requirement slot".
- Regression: `assignRooms()`/`releaseAssignment()` (public contract) giữ nguyên chữ ký và hành vi bên ngoài — nhưng nội bộ `assignRooms()` được refactor để dùng chung 1 private helper với method mới (Mục 18), không phải "không đổi 1 dòng nào trong file" như REVISION 2 từng nói cho `assignRooms()`.

---

## 16. Data Migration and Backfill Plan

Tối thiểu 2 migration, **tách theo đúng Milestone (Product Owner Mục III/X):**

| # | Migration | Milestone | Nội dung |
|---|---|---|---|
| 1 | `add_booking_requirement_id_to_room_assignments_table` | **Milestone 1** | Cột nullable FK, `restrictOnDelete()` (Mục 15) |
| 2 | `create_release_batches_table` | **Milestone 4** (không phải Milestone 1 như REVISION 2 từng gộp) | Xem schema đầy đủ Mục 17 |
| 3 | `add_release_batch_id_to_room_assignments_table` | **Milestone 4** | Cột nullable FK trỏ `release_batches.id` |

Backfill (Milestone 1, Artisan command riêng, KHÔNG phải Eloquent migration business-logic): idempotent, dry-run trước, không gán row ambiguous (quy tắc Mục 15/Mục 19 — legacy ambiguous xử lý ở tầng vận hành, xem Mục 19b).

---

## 17. Release Batch Design (chốt schema — Product Owner Mục X/XI)

**Bảng `release_batches` (Milestone 4):**
```
id
booking_id      -- FK bookings, để truy vấn "các lượt gỡ của booking X" không cần join qua room_assignments
actor_id        -- FK users, người thực hiện
released_at
reason          -- nullable, lý do chung cho batch
note            -- nullable, ghi chú chung cho batch
reduce_demand   -- boolean, có giảm demand hay không cho batch này
timestamps
```

`room_assignments.release_batch_id` — nullable FK trỏ `release_batches.id`.

**Tương thích màn hình cũ (Product Owner Mục XI, khác REVISION 2 — REVISION 2 từng đề xuất thêm cột `release_note` riêng trên `room_assignments`, REVISION 3 KHÔNG làm vậy):** mỗi assignment trong batch vẫn được ghi `release_reason` (cột ĐÃ CÓ SẴN trên `room_assignments`, không cần migration mới) = đúng giá trị `reason` của batch — để các màn hình cũ đọc `assignment.release_reason` trực tiếp (ví dụ bảng lịch sử phân phòng hiện có) tiếp tục hiển thị đúng mà không cần sửa. `release_note` (ghi chú) chỉ tồn tại ở cấp `release_batches.note`, KHÔNG lặp lại trên từng assignment — không có cột `room_assignments.release_note`.

**AuditObserver:** đăng ký `ReleaseBatch::observe(AuditObserver::class);` trong `AppServiceProvider::boot()` — đúng convention 24 model hiện có (Mục 11), không cần cơ chế audit riêng.

**Ràng buộc khi tạo batch (Milestone 4):**
- Batch được tạo TRONG CÙNG transaction với việc cập nhật N assignment — nếu transaction rollback, không có row `release_batches` mồ côi (đảm bảo tự nhiên bởi `DB::transaction()`, không cần dọn dẹp thủ công).
- Validate mọi `assignment_id` trong request thuộc đúng `booking` trên route (`abort_unless($assignment->booking_id === $booking->id, 404)` — đúng pattern đã có ở `releaseConflict()`).
- Authorize TỪNG assignment riêng biệt (`$this->authorize('release', $assignment)` cho mỗi phần tử batch, không check gộp 1 lần).

---

## 18. Corrected Bulk Assignment Design (REVISION 3 — atomic mapping từ Milestone 2, không phải Milestone 3)

**Sửa lỗi #7 (Mục 13):** REVISION 2 để ngỏ khả năng Milestone 2 gắn `booking_requirement_id` ở 1 transaction RIÊNG sau khi `assignRooms()` đã commit. Product Owner từ chối cách này (Mục IV) — không chấp nhận "assignRooms() commit → transaction thứ hai mới gắn requirement_id".

### Thiết kế refactor bắt buộc (không copy-paste logic thành 2 phiên bản)

1. Extract 1 private helper trên `RoomAssignmentService`, ví dụ `lockRoomRecheckAndCreateAssignment(Booking $booking, array $assignmentData): RoomAssignment` — chứa ĐÚNG logic hiện có của vòng lặp trong `assignRooms()` (lock Room, recheck conflict, tạo RoomAssignment). Đây là NƠI DUY NHẤT chứa logic lock/recheck/create — không có bản sao thứ hai.
2. `assignRooms()` (public, method CŨ) được refactor để GỌI private helper này trong vòng lặp — **hành vi bên ngoài (input/output/exception) giữ nguyên 100%**, test hiện có (`test_multiple_rooms_can_be_assigned_from_room_board_payload` và các test khác) PASS không đổi. Đây là thay đổi NỘI BỘ thuần refactor, không phải thay đổi nghiệp vụ.
3. Method MỚI (dùng cho demand-first đã hardening ở Milestone 2, ví dụ `assignRoomsWithRequirementLink()`) gọi CÙNG private helper đó, trong CÙNG 1 `DB::transaction()`, và ngay sau khi private helper trả về `RoomAssignment` vừa tạo, resolve `booking_requirement_id`:
   - Room_type chỉ có 1 dòng active → tự động gắn, không cần input thêm.
   - Room_type có ≥2 dòng active → bắt buộc payload có `target_requirement_id` cho room_type đó (Quyết định #10) — thiếu thì request bị từ chối bằng lỗi validate TRƯỚC KHI vào transaction (fail fast), không tạo assignment `NULL`.
   - Set `booking_requirement_id` ngay trên `RoomAssignment` vừa tạo, trong CÙNG transaction — không có bước "gắn sau".
4. `RoomAssignmentController::store()` (endpoint demand-first hiện có, route KHÔNG đổi) chuyển sang gọi method MỚI ở bước 3 thay vì `assignRooms()` cũ trực tiếp — đây là thay đổi Controller tối thiểu (đổi 1 lời gọi method), không đổi route/request shape cho trường hợp phổ biến (room_type có 1 dòng, không cần `target_requirement_id`).
5. Milestone 3 (Room-Board-first) mở rộng thêm: nhận `price_groups` (giá/nguồn giá/ghi chú/`target_requirement_id` theo từng room_type), áp đầy đủ thuật toán Cấp A+B (Mục 14) bao gồm tạo dòng mới cho phần excess, và Stay atomic (Mục 21) — dùng lại đúng private helper ở bước 1, không viết lại logic lock Room.

---

## 19. Corrected Bulk Release Design (REVISION 3 — nghiêm ngặt hơn REVISION 2)

**Milestone 4.** Method `RoomAssignmentService::releaseAssignments()`:

1. Input: `booking`, `assignment_id[]`, `release_reason`, `release_note` (chỉ ở cấp batch — Mục 17), `reduce_demand: bool`.
2. Transaction (thứ tự lock B — Mục 20):
   - Lock `Booking`.
   - Sort `assignment_id` asc, với mỗi assignment: lock `Stay` (theo `room_assignment_id` asc) rồi lock `RoomAssignment` (theo `id` asc), áp đúng kiểm tra hiện có (`status===Assigned`, chưa check-in thực tế `stay.actual_checkin_at===null`) — 1 phòng không đủ điều kiện → throw ngay, rollback toàn bộ.
3. **Kiểm tra legacy ambiguous — CHỐT NGHIÊM NGẶT (Product Owner Mục IX/VII, khác REVISION 2):**
   - Nếu `reduce_demand=false` → cho phép release bình thường, không quan tâm `booking_requirement_id` của từng assignment (NULL hay không đều được).
   - Nếu `reduce_demand=true`: kiểm tra TRƯỚC khi ghi bất kỳ thay đổi nào — nếu **BẤT KỲ** assignment nào trong batch có `booking_requirement_id === NULL` → **từ chối TOÀN BỘ request** (throw `ValidationException`, transaction rollback hoàn toàn, không release phòng nào, không đổi trạng thái gì) — người dùng có thể thử lại với `reduce_demand=false` (vẫn gỡ được phòng, chỉ không tự giảm demand), hoặc loại các phòng ambiguous khỏi batch trước. **Không giảm demand một phần. Không tự đoán dòng requirement. Không báo "thành công" khi chỉ giảm được một phần.** Đây là điểm khác biệt cốt lõi với REVISION 2 (REVISION 2 từng đề xuất "bỏ qua riêng phần ambiguous, giảm phần còn lại" — Product Owner đã bác bỏ phương án đó).
4. **Kiểm tra folio-locked requirement (REVISION 3 — mới, Mục VIII của yêu cầu sửa):** nếu `reduce_demand=true`, với mỗi assignment cần trừ vào `BookingRequirement` mà dòng đó đang bị khoá bởi `RequirementLockedAfterRoomChargeException` (đã có `FolioEntry` Room chưa void) → **từ chối TOÀN BỘ request TRƯỚC KHI GHI**, cùng cơ chế all-or-nothing như bước 3. Không bypass exception này. Không sửa ngược Folio. Người dùng có thể release lại với `reduce_demand=false`.
5. Nếu qua được cả 2 kiểm tra trên: ghi `status=Released` + `released_by/released_at/release_reason` (per-assignment, Mục 17) cho tất cả; tạo 1 `ReleaseBatch` record; set `release_batch_id` cho tất cả; với `reduce_demand=true`, lock đúng dòng `BookingRequirement` mà mỗi assignment tham chiếu (theo `id` asc — thứ tự lock B, Mục 20), trừ đúng `quantity` (Quyết định #13 — về 0 thì giữ dòng, ẩn mặc định, không hard delete).
6. Cập nhật `updateBookingAssignmentStatus()`/`updateBookingStayStatus()` 1 lần cuối batch.

### 19b. Kế hoạch vận hành cho Legacy Ambiguous trước Milestone 3-4

Sau backfill (Milestone 1), xuất báo cáo các `RoomAssignment` còn `booking_requirement_id = NULL` (dạng "ambiguous" — ≥2 dòng khớp, hoặc "no matching requirement" — 0 dòng khớp). Trước khi Milestone 3/4 vận hành thật:
- Đội vận hành/nghiệp vụ rà soát báo cáo, đối chiếu thủ công (lịch sử booking, ghi chú, trao đổi với khách/lễ tân) để xác định đúng dòng requirement cho từng assignment ambiguous.
- Với các trường hợp xác định được, chạy 1 lệnh chỉnh sửa thủ công có kiểm soát (ví dụ Artisan command nhận cặp `assignment_id → requirement_id` tường minh do người có thẩm quyền xác nhận, KHÔNG phải thuật toán tự đoán) để cập nhật `booking_requirement_id`.
- Các trường hợp không xác định được để nguyên `NULL` — theo đúng bước 3 ở Mục 19, các assignment này chỉ chặn `reduce_demand=true` cho CHÍNH batch chứa chúng, không ảnh hưởng các booking khác.

---

## 20. Transaction and Locking Plan (REVISION 3 — tách 2 thứ tự riêng, sửa lỗi #5 Mục 13)

**REVISION 2 dùng 1 canonical order chung `Booking → Room → BookingRequirement → RoomAssignment → Stay` cho cả assign và release — SAI cho release, vì `releaseAssignment()` hiện tại lock `Stay` TRƯỚC `RoomAssignment`, không phải ngược lại.** REVISION 3 chốt 2 thứ tự riêng biệt, không dùng chung 1 phát biểu "canonical order" cho cả 2 nghiệp vụ khác nhau:

### A. Room-Board-first assign (Milestone 2/3)

```
Booking → Room [sort id asc] → BookingRequirement [sort id asc] → tạo RoomAssignment → tạo Stay
```
`RoomAssignment` và `Stay` là bản ghi MỚI TẠO trong cùng transaction — không cần `lockForUpdate()` trước khi insert (không có row nào tồn tại để khoá; thứ tự "tạo RoomAssignment rồi tạo Stay" chỉ đơn thuần là thứ tự phụ thuộc dữ liệu bắt buộc, vì `Stay.room_assignment_id` cần `RoomAssignment.id` đã tồn tại — không phải một "lock order" theo nghĩa tranh chấp tài nguyên).

### B. Bulk release (Milestone 4)

```
Booking → Stay [sort by room_assignment_id asc] → RoomAssignment [sort id asc] → BookingRequirement [sort id asc]
```
Thứ tự `Stay → RoomAssignment` (không phải `RoomAssignment → Stay`) khớp đúng với 2 tiền lệ đã có trong code — đọc lại xác nhận:
- `StayService::checkIn()` (ADR-38): `Booking → Stay → RoomAssignment`.
- `RoomAssignmentService::releaseAssignment()` hiện tại: `Stay → RoomAssignment` (không có Booking).

Bulk release mới chỉ THÊM `Booking` ở đầu (khớp tiền lệ `checkIn()`) và `BookingRequirement` ở cuối (chỉ cần khi `reduce_demand=true`) — KHÔNG đảo ngược thứ tự `Stay`/`RoomAssignment` đã có, tránh đúng lỗi REVISION 2 mắc phải (mô tả 1 thứ tự `RoomAssignment → Stay` chung rồi lại nói release "tái dùng đúng thứ tự đã có" mà thứ tự đã có thực chất ngược lại).

**Vì sao 2 thứ tự khác nhau không gây deadlock lẫn nhau:** assign TẠO row mới (không lock Stay/RoomAssignment cũ nào), release KHOÁ row đã tồn tại theo Stay→RoomAssignment — 2 luồng không tranh chấp NGƯỢC CHIỀU nhau trên cùng cặp tài nguyên vì assign không lock RoomAssignment/Stay đã tồn tại (nó chỉ tạo mới), nên không có cạnh chờ ngược nào giữa "assign đang giữ RoomAssignment chờ Stay" và "release đang giữ Stay chờ RoomAssignment" — assign đơn giản không giữ khoá trên Stay/RoomAssignment CŨ nào cả.

### Phân tích tránh deadlock cho 5 cặp tình huống bắt buộc (Product Owner Mục II)

| Cặp tình huống | Vì sao không deadlock |
|---|---|
| **Check-in và bulk release** | Cả 2 đều lock `Booking` TRƯỚC TIÊN (checkIn: ADR-38; bulk release: thứ tự B). 2 giao dịch cùng bắt đầu bằng đúng 1 tài nguyên chung (`Booking`) → giao dịch đến sau bị chặn ngay tại bước đầu, không bao giờ tiến tới bước lock `Stay`/`RoomAssignment` để tạo chu trình chờ ngược. Sau khi qua được `Booking`, cả 2 đều tiếp tục theo đúng thứ tự con `Stay → RoomAssignment` giống hệt nhau — không có nhánh nào đảo chiều |
| **Update demand và assign** | `updateRequirement()`/`addRequirement()`/`deleteRequirement()` (sau khi vá — Mục 25) đều lock `Booking` trước; assign (thứ tự A) cũng lock `Booking` trước → cùng lý do trên, tuần tự hoá hoàn toàn tại điểm `Booking` |
| **Update demand và release** | Tương tự — cả 2 đều lock `Booking` đầu tiên |
| **Hai bulk assign** | Nếu cùng 1 booking: tuần tự hoá tại `Booking` lock. Nếu khác booking nhưng phòng trùng nhau: mỗi giao dịch lock `Booking` RIÊNG (không tranh chấp ở bước này), rồi lock `Room` theo đúng thứ tự TĂNG DẦN THEO ID TOÀN CỤC (không phải theo thứ tự "trong batch của tôi") — nguyên tắc chuẩn "luôn khoá theo 1 thứ tự toàn cục cố định" đảm bảo không có chu trình chờ dù 2 batch có tập phòng giao nhau bất kỳ |
| **Hai bulk release** | Tương tự — cùng booking thì tuần tự hoá tại `Booking`; khác booking nhưng assignment/stay giao nhau (hiếm, vì assignment thuộc về đúng 1 booking) thì vẫn khoá theo `room_assignment_id`/`id` tăng dần toàn cục, không theo thứ tự cục bộ của từng request |

---

## 21. Stay Creation Atomicity (không đổi từ REVISION 2 — Product Owner xác nhận Quyết định #12)

`createStayFromAssignment()` được gọi TRONG CÙNG transaction với việc tạo `RoomAssignment` + đồng bộ demand, cho 2 method MỚI (Mục 18). Method cũ `assignRooms()`/`RoomAssignmentController::store()` cho luồng demand-first CHƯA hardening (nếu còn tồn tại lời gọi trực tiếp nào ngoài phạm vi refactor Mục 18) giữ nguyên hiện trạng ngoài transaction — nhưng sau refactor Mục 18, endpoint demand-first ĐÃ chuyển sang gọi method mới có Stay atomic, nên trên thực tế hành vi "Stay ngoài transaction" chỉ còn tồn tại về mặt lý thuyết nếu có code path khác gọi thẳng `assignRooms()` (hiện tại chỉ có 1 call site, đã liệt kê Mục 8) — không có rủi ro thực tế nào bị bỏ sót.

---

## 22. State Matrix (REVISION 3 — chốt toàn bộ, không còn ô "cần xác nhận")

| BookingStatus | Bulk assign (Room-Board-first) | Bulk release | `reduce_demand` | Sửa demand |
|---|---|---|---|---|
| `Draft` | ✅ — đây chính là luồng tạo demand từ việc chọn phòng | N/A | N/A | ✅ |
| `PendingAssignment` | ✅ | N/A (chưa có assignment active) | N/A | ✅ |
| `PartiallyAssigned` | ✅ | ✅ | ✅ | ✅ |
| `FullyAssigned` | ✅ | ✅ | ✅ | ✅ |
| `Held` | ✅ | ✅ | ✅ | ✅ |
| `Deposited` | ✅ | ✅ | ✅ | ✅ |
| `PartiallyCheckedIn` | ✅ — cho phép thêm phòng nếu booking còn hiệu lực (chưa Cancelled/NoShow) | ✅ — CHỈ cho assignment CHƯA check-in thực tế (`stay.actual_checkin_at===null`) | ✅ (cho phần được release) | ✅ |
| `CheckedIn` | ✅ — cho phòng chưa gán, nếu booking còn hiệu lực | ✅ — CHỈ cho assignment chưa check-in thực tế | ✅ | ✅ |
| `PartiallyCheckedOut` | ❌ **Không cho assign phòng mới** — chỉ hoàn tất checkout hoặc workflow phục hồi riêng (ngoài phạm vi tính năng này) | ⚠️ Rule hiện có của `releaseAssignment()` (theo `stay.actual_checkin_at`) vẫn áp dụng cho phần CHƯA checkout — không có rule chặn thêm theo `BookingStatus` này ngoài việc không cho assign mới | ✅ (cho phần được release hợp lệ) | ❌ **Không sửa demand** khi booking đang ở trạng thái này |
| `CheckedOut` | ❌ **Không assign** | ❌ **Không bulk release nghiệp vụ** (booking đã hoàn tất lưu trú) | N/A | ❌ **Không sửa demand** |
| `Cancelled` | ❌ **Chặn** — method mới tự kiểm tra `booking.status` trước khi tạo assignment (bổ sung so với `assignRooms()` cũ vốn không tự kiểm tra) | ❌ **Chặn** — không có ý nghĩa nghiệp vụ | N/A | ❌ **Chặn** |
| `NoShow` | ❌ **Chặn** | ❌ **Chặn** | N/A | ❌ **Chặn** |

**Room `OutOfOrder`/`OutOfService`:** chặn ở cấp PHÒNG (không phải cấp Booking) qua `RoomAvailabilityRuleService::isRoomUnavailable()` — không đổi, áp dụng nguyên vẹn cho method mới, độc lập với bảng trên.

---

## 23. Authorization Matrix (không đổi nội dung từ REVISION 2 — Product Owner xác nhận Quyết định #6)

| Thao tác | Permission cần |
|---|---|
| Room-Board-first tạo `RoomAssignment` | `room.assign` |
| Room-Board-first tạo/cập nhật `BookingRequirement` | `booking.update` |
| Room-Board-first tổng thể (assign + demand sync) | **CẢ 2**: `room.assign` VÀ `booking.update` |
| Bulk release, `reduce_demand=false` | `room.unassign` |
| Bulk release, `reduce_demand=true` | **CẢ 2**: `room.unassign` VÀ `booking.update` |
| User chỉ có `room.unassign`, không có `booking.update` | Release được với `reduce_demand=false`; bị từ chối tường minh nếu gửi `reduce_demand=true` |
| Authorize | Per-resource — `$this->authorize('release', $assignment)` cho TỪNG assignment trong batch |

Với 6 role hiện tại (Mục 11): ADMIN/MANAGER/RECEPTION dùng được toàn bộ tính năng mới (Reception giữ nguyên quyền — Quyết định #6). SALES/HOUSEKEEPING/ACCOUNTANT không đổi gì, không truy cập tính năng này.

---

## 24. UI/UX Proposal (cập nhật — thêm guest fields, target_requirement_id)

- Giữ nguyên grid Room Board hiện có.
- Panel "Xác nhận giá/dòng nhu cầu theo nhóm" xuất hiện khi room_type không khớp demand, vượt số lượng, HOẶC có >1 dòng requirement active. Mỗi nhóm room_type_id có: giá, nguồn giá, ghi chú, và nếu >1 dòng requirement active → **bắt buộc** chọn `target_requirement_id` (Quyết định #10, không có lựa chọn "bỏ qua để hệ thống tự chọn").
- **Khi panel tạo dòng requirement MỚI (room_type chưa có demand, hoặc excess không khớp dòng nào, hoặc dòng khớp đã bị khoá folio — Mục 14b điểm 5):** hiển thị thêm 3 trường `adults`/`children_under_6`/`children_over_6`, mặc định `adults = số phòng trong nhóm này`, `children_* = 0`, cho sửa trước khi xác nhận (Mục 14b điểm 6, Quyết định guest fields).
- Popup gỡ nhiều phòng: tên phòng đang gỡ, 1 ô lý do, 1 ô ghi chú (cấp batch — Mục 17), 1 checkbox "Đồng thời giảm nhu cầu phòng tương ứng" (mặc định TẮT — Quyết định #1). Nếu có assignment `booking_requirement_id=NULL` trong lượt chọn VÀ người dùng tick giảm demand → hiển thị lỗi rõ ràng TRƯỚC KHI submit (validate phía client tối thiểu, backend vẫn là nguồn chặn thật sự — Mục 19 bước 3), không cho submit half-broken.

## 25. Backward Compatibility (REVISION 3 — cập nhật phạm vi thay đổi BookingService)

**Khác REVISION 2 (REVISION 2 nói "ngoại lệ DUY NHẤT là `updateRequirement()`"):** sau khi đọc lại code (Mục 10), cả `addRequirement()` VÀ `deleteRequirement()` hiện **không có `DB::transaction()`** — Milestone 1 phải bọc CẢ 3 method (`addRequirement`, `updateRequirement`, `deleteRequirement`) trong transaction có lock `Booking` đầu tiên, theo đúng yêu cầu Product Owner Mục VI:

- `addRequirement()`: bọc `DB::transaction()`, lock `Booking`, lock TẤT CẢ dòng `BookingRequirement` cùng room_type (dù không dùng để merge — mục đích chỉ là đóng cửa sổ race với các luồng mới đang tính `remaining`/allocation cho room_type đó), rồi mới `create()`. Hành vi nghiệp vụ bên ngoài (luôn tạo dòng mới, không merge) **không đổi** — chỉ xiết lock.
- `updateRequirement()`: giữ `DB::transaction()` đã có, thêm lock `Booking` ở đầu, thêm lock chính dòng `BookingRequirement` đang sửa (hiện tại chỉ lock `FolioEntry`, chưa lock chính requirement row) trước khi `update()`.
- `deleteRequirement()`: bọc `DB::transaction()` (mới), lock `Booking`, lock dòng `BookingRequirement` cần xoá, **thêm guard mới**: nếu `RoomAssignment::where('booking_requirement_id', $requirement->id)->exists()` (không lọc theo status — kể cả `Released`) → throw exception thân thiện, KHÔNG cho xoá (Mục V/15) — đây là lớp bảo vệ ở tầng ứng dụng, chạy TRƯỚC khi chạm constraint `restrictOnDelete()` ở DB.

`assignRooms()`, `releaseAssignment()` (method cũ, chữ ký public): **hành vi/chữ ký bên ngoài giữ nguyên 100%** (Mục 18) — chỉ nội bộ refactor để dùng chung private helper, không phải "0 thay đổi trong file" theo nghĩa tuyệt đối như REVISION 1/2 từng phát biểu. Test hiện có phải PASS không đổi vì input/output không đổi.

Endpoint cũ giữ nguyên route. `releaseAll()` frontend được thay bằng gọi 1 endpoint bulk mới.

## 26. Regression Risks (bổ sung REVISION 3)

- Rủi ro đụng nhầm logic bên trong `assignRooms()`/`releaseAssignment()` khi refactor Mục 18 — giảm thiểu bằng cách VIẾT TEST TRƯỚC cho `assignRooms()` hiện có (nếu coverage hiện tại chưa đủ) trước khi refactor, đảm bảo output không đổi.
- Rủi ro bọc `addRequirement()`/`deleteRequirement()` trong transaction mới làm lộ ra exception trước đây không xảy ra (ví dụ deadlock timeout nếu có transaction khác giữ lock `Booking` quá lâu) — cần test tải nhẹ ở Milestone 1, không chỉ test đơn lẻ.
- Rủi ro `restrictOnDelete()` chặn 1 thao tác xoá hợp lệ nào đó hiện có trong hệ thống — đã grep toàn bộ codebase (`app/Http/Controllers`, `app/Services`), xác nhận `deleteRequirement()` chỉ có 1 call site (`BookingRequirementController::destroy()`), không có nơi nào khác xoá `BookingRequirement` trực tiếp — rủi ro thấp.
- Rủi ro backfill gán sai cho row ambiguous — không đổi từ REVISION 2, vẫn phải dry-run trước.
- Rủi ro release chặn nhầm toàn batch vì 1 phòng ambiguous/folio-locked — đây là hành vi CÓ CHỦ ĐÍCH theo Quyết định #9/Mục VIII, không phải bug, nhưng cần thông báo UI đủ rõ để người dùng hiểu tại sao bị chặn (Mục 24).

## 27. Test Strategy

Test hiện có (`BookingManagementUiTest.php`) bắt buộc PASS nguyên trạng — đặc biệt sau refactor Mục 18 (không đổi input/output của `assignRooms()`). Bổ sung REVISION 3:
- Test riêng cho việc bọc `addRequirement()`/`deleteRequirement()` trong transaction (đảm bảo hành vi nghiệp vụ không đổi, chỉ thêm lock).
- Test guard `deleteRequirement()` chặn xoá khi có assignment tham chiếu (kể cả Released).
- Test atomic mapping Milestone 2: gọi 1 request tạo assignment, xác nhận `booking_requirement_id` đã có giá trị NGAY trong response, không cần request thứ 2.
- Test all-or-nothing reject khi có ambiguous/folio-locked trong batch release (Mục 19).
- Test guest fields mặc định đúng khi tạo dòng requirement mới từ panel (Mục 14b điểm 6).
- Test State Matrix đầy đủ 11 trạng thái (Mục 22) — không còn trạng thái nào "chưa test vì chưa chốt".

## 28. Recommended Architecture

Không đổi kết luận tổng thể qua 3 revision: mở rộng `RoomAssignmentService` bằng private helper dùng chung + method mới, không xây Application-Service layer mới.

## 29. Product Owner Decisions — LOCKED (REVISION 3, thay thế bảng "Open Decisions")

Toàn bộ 18 quyết định trước đây (REVISION 2 Mục 29) + 1 quyết định mới (#19, guest fields) đã được Product Owner chốt (Mục I-IX của yêu cầu sửa REVISION 3). Bảng dưới ghi lại quyết định CUỐI CÙNG — không còn "khuyến nghị chờ duyệt":

| # | Quyết định | Chốt |
|---|---|---|
| 1 | Gỡ phòng mặc định có giảm nhu cầu | **Checkbox tuỳ chọn, mặc định TẮT** |
| 2 | Cùng loại phòng nhiều dòng giá | **Cho phép** |
| 3 | Giá mặc định Room-Board-first | **RoomRateService gợi ý, người dùng sửa được** |
| 4 | Sửa giá từng phòng hay theo nhóm | **Theo nhóm loại phòng, không theo từng phòng** |
| 5 | Phòng đã check-in có vào bulk release không | **Không** |
| 6 | Đổi phòng sau check-in | **Dùng `moveRoom()` riêng, không dùng release** |
| 7 | 1 batch nhiều loại phòng | **Có** |
| 8 | Rollback toàn bộ hay partial khi lỗi | **Rollback toàn bộ** |
| 9 | Admin/Manager quyền gì | **Giữ như hiện tại** |
| 10 | Reception có bulk assign/release không | **Có, giữ quyền hiện có** |
| 11 | Batch ID trong DB | **Có** |
| 12 | Mở rộng audit schema | **Không cần bảng audit mới ngoài `release_batches` (đã đăng ký AuditObserver)** |
| **13** | **`booking_requirement_id`** | **Phương án A — nullable, FK `restrictOnDelete()`, assignment mới qua luồng mới không NULL** |
| **14** | **Legacy ambiguous khi release** | **`reduce_demand=false`: cho phép. `reduce_demand=true` + có row NULL: từ chối TOÀN BỘ request trước khi ghi, không giảm một phần, không đoán** |
| **15** | **Nhiều dòng requirement cùng room_type** | **Bắt buộc người dùng chọn `target_requirement_id`, không có mặc định tự động** |
| **16** | **Release metadata** | **Bảng `release_batches` đầy đủ trường (Mục 17), `release_reason` vẫn ghi trên từng assignment để tương thích cũ, không có cột `release_note` per-assignment** |
| **17** | **Assignment+demand+Stay atomic** | **Có, tuyệt đối, cùng transaction, cho luồng mới** |
| **18** | **Quantity về 0** | **Giữ dòng `quantity=0`, ẩn khỏi danh sách mặc định, không hard delete** |
| **19 (mới)** | **Guest fields cho requirement mới tạo từ Room-Board-first** | **Mặc định `adults=quantity` (số phòng mới), `children_*=0`, cho sửa trước khi xác nhận; không đụng guest fields của dòng đã tồn tại** |

Không còn quyết định nào ở trạng thái "mở"/"chờ Product Owner" trong phạm vi đã liệt kê ở Mục I-IX của yêu cầu sửa REVISION 3.

---

## 30. Affected Files (REVISION 3 — tách đúng theo Milestone đã thu gọn)

**Milestone 1 (chỉ data relationship, locking, backfill, test — KHÔNG business logic release):**
- Mới: `database/migrations/xxxx_add_booking_requirement_id_to_room_assignments_table.php` (FK `restrictOnDelete()`).
- Mới: Artisan command backfill (dry-run, idempotent).
- Sửa: `app/Models/RoomAssignment.php` (thêm `booking_requirement_id` vào `$fillable`, relationship `bookingRequirement(): BelongsTo`).
- Sửa: `app/Services/BookingService.php` — bọc `DB::transaction()` + lock `Booking` cho CẢ 3 method (`addRequirement`, `updateRequirement`, `deleteRequirement`), thêm guard chặn hard-delete trong `deleteRequirement()`.
- Sửa: `app/Services/RoomAssignmentService.php` — extract private helper `lockRoomRecheckAndCreateAssignment()`, refactor `assignRooms()` để gọi helper (hành vi không đổi), thêm allocation helper thuần tuý (Cấp A+B, chưa nối orchestration đầy đủ).
- Mới: test cho migration, backfill, lock 3 method requirement, allocation helper, concurrency (5 cặp tình huống Mục 20).
- **KHÔNG có trong Milestone 1:** route mới, UI mới, `release_batches`, `releaseAssignments()` hoàn chỉnh.

**Milestone 2:**
- Sửa: `app/Services/RoomAssignmentService.php` (thêm `assignRoomsWithRequirementLink()`, dùng chung helper Milestone 1).
- Sửa: `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php` (`store()` gọi method mới thay vì `assignRooms()` trực tiếp).

**Milestone 3:**
- Sửa: `app/Services/RoomAssignmentService.php` (`assignRoomsWithDemandSync()` đầy đủ Cấp A+B+Stay atomic).
- Sửa: `resources/js/Pages/Admin/Bookings/Show.vue` (panel giá + chọn dòng + guest fields).

**Milestone 4 (chuyển từ Milestone 1 theo yêu cầu sửa):**
- Mới: `database/migrations/xxxx_create_release_batches_table.php`.
- Mới: `database/migrations/xxxx_add_release_batch_id_to_room_assignments_table.php`.
- Mới: `app/Models/ReleaseBatch.php` + đăng ký `AuditObserver` trong `AppServiceProvider`.
- Mới: `app/Http/Requests/Booking/BulkReleaseAssignmentRequest.php`.
- Sửa: `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php` (`bulkRelease()`).
- Sửa: `routes/web.php`.
- Sửa: `resources/js/Pages/Admin/Bookings/Show.vue` (popup bulk release).

**Không đổi trong toàn bộ thiết kế:** `BookingRequirementController.php` (route/entrypoint giữ nguyên, chỉ service bên dưới thay đổi nội bộ), `RoomAvailabilityRuleService.php`, `StayService.php` (bao gồm `moveRoom()`), `FolioEntry`/`FolioService`, migration gốc `booking_requirements`/`room_assignments`.

## 31. Architecture Decision

Mở rộng `RoomAssignmentService` bằng private helper dùng chung (Mục 18) + method mới cho từng Milestone, thay vì Application-Service layer mới. Nền tảng bắt buộc trước khi code: `booking_requirement_id` FK `restrictOnDelete()` (Quyết định #13), Locking Plan tách A/B (Mục 20), Stay atomicity (Quyết định #17), toàn bộ 19 quyết định Mục 29 đã LOCKED.

## 32. Readiness Decision

**READY FOR IMPLEMENTATION PLAN WITH CONDITIONS.**

Toàn bộ 19 quyết định Product Owner (Mục 29) đã chốt — không còn quyết định nền tảng nào bị treo. Locking Plan đã tách đúng theo nghiệp vụ (Mục 20). Milestone 1 đã thu gọn đúng phạm vi (chỉ data/locking/backfill/test — Mục 30).

**Điều kiện:**
- Milestone 1 CHỈ được chứa: migration `booking_requirement_id`, model relationship, FK + delete protection, backfill, khoá 3 method requirement, allocation helper thuần tuý, test — **không route, không UI, không `release_batches`, không `releaseAssignments()` hoàn chỉnh** (theo đúng phạm vi đã thu gọn, xem Implementation Plan Mục 5).
- Milestone 2 bắt buộc atomic mapping trong CÙNG transaction tạo assignment — không có bước "gắn requirement_id ở transaction thứ hai".
- Milestone 3/4 vẫn phải tuân thủ đúng các quyết định đã khoá ở Mục 29, đặc biệt #14 (từ chối toàn bộ, không giảm một phần) và #16 (schema `release_batches`).
- **Không triển khai code trong phạm vi REVISION 3 này** — tài liệu này chỉ là điều kiện đủ để BẮT ĐẦU code Milestone 1 ở 1 task riêng sau khi được duyệt.
