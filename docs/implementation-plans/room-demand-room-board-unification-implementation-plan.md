# Room Demand and Room Board Unification — Implementation Plan

**Ngày viết ban đầu:** 2026-08-04
**Ngày sửa REVISION 2:** 2026-08-05
**Ngày sửa REVISION 3 (Final Correction):** 2026-08-05
**Căn cứ:** `docs/reviews/room-demand-room-board-unification-architecture-review.md` — **REVISION 3**
**Trạng thái:** **Milestone 1: COMPLETE. Milestone 2: NOT STARTED.**

---

## 0. Thay đổi so với REVISION 2

REVISION 2 ở trạng thái BLOCKED vì Locking Plan dùng 1 thứ tự chung sai cho cả assign/release, Milestone 1 còn ôm cả 2 method nghiệp vụ hoàn chỉnh (bao gồm bulk release), và một số quy tắc biên (legacy ambiguous, folio-locked, guest fields) chưa chốt nghiêm ngặt. Toàn bộ 19 quyết định Product Owner (Architecture Review REVISION 3 Mục 29) đã LOCKED, Locking Plan đã tách đúng theo nghiệp vụ (Mục A/B), Milestone 1 đã thu gọn chỉ còn data/locking/backfill/test. Trạng thái đổi sang **READY FOR MILESTONE 1 IMPLEMENTATION**.

**Cập nhật sau Commit Closure:** Milestone 1 đã triển khai xong (COMPLETE) và được commit — xem `docs/reports/room-demand-room-board-unification-milestone-1-report.md`. Milestone 1 **chỉ** là nền tảng dữ liệu/locking (migration `booking_requirement_id`, FK `restrictOnDelete()`, khoá `addRequirement`/`updateRequirement`/`deleteRequirement`, backfill command, allocation helper thuần túy) — **atomic assignment mapping vẫn chưa có** (đó là việc của Milestone 2, theo đúng Mục 5 Milestone 2 của tài liệu này: extract private helper dùng chung + gắn `booking_requirement_id` trong cùng transaction tạo assignment). **Milestone 2: NOT STARTED** — chưa refactor `assignRooms()`, chưa có `assignRoomsWithRequirementLink()`, chưa đổi controller.

---

## 1. Objective

Cho phép chọn phòng trực tiếp trên Room Board, tự động đồng bộ `booking_requirements` đúng (không double-count), hỗ trợ multi-select atomic cho cả assign và release, giảm demand đúng dòng giá khi gỡ phòng (không đoán, từ chối toàn bộ khi mơ hồ), trong khi giữ nguyên 100% luồng demand-first hiện có (chữ ký/hành vi public không đổi, chỉ nội bộ refactor).

## 2. Locked Architecture Decisions

Toàn bộ 19 quyết định tại Architecture Review REVISION 3 Mục 29 đã được Product Owner chốt — không lặp lại nội dung ở đây, chỉ tham chiếu. Quan trọng nhất cho việc bắt đầu code:
- **Quyết định #13:** `booking_requirement_id` nullable FK, `restrictOnDelete()`, assignment mới qua luồng mới không được NULL.
- **Quyết định #14:** legacy ambiguous + `reduce_demand=true` → từ chối TOÀN BỘ request, không giảm một phần.
- **Quyết định #17:** Assignment+demand+Stay atomic tuyệt đối cho luồng mới.

Nếu Product Owner đổi bất kỳ quyết định nào ở trên sau khi Milestone 1 đã bắt đầu code, phải dừng và viết lại phần liên quan trước khi tiếp tục — không "vá" ngầm.

## 3. Out of Scope

- Không đổi `assignRooms()`/`releaseAssignment()` **chữ ký và hành vi bên ngoài** (input/output/exception) — nội bộ được refactor để dùng chung 1 private helper với method mới (Architecture Review Mục 18), đây KHÔNG phải "0 thay đổi trong file" tuyệt đối như từng phát biểu ở REVISION 1/2.
- Không đổi luồng demand-first ở tầng route/entrypoint (`BookingRequirementController`, `RoomAssignmentController::store()` route) — chỉ đổi method service bên dưới được gọi.
- Không đổi `StayService::moveRoom()`.
- Không đổi Folio/FolioEntry/Night Audit — không bao giờ bypass `RequirementLockedAfterRoomChargeException`, không sửa ngược Folio.
- Không xây Generic Command/Application-Service layer mới.
- Không đổi permission hiện có.

## 4. Existing Components to Reuse

`RoomAvailabilityRuleService` (không đổi), `AuditObserver` (đăng ký thêm cho `ReleaseBatch` ở Milestone 4, đúng convention 24 model hiện có), `suggestedPriceForRoomType()`/`RoomRateService`, `StoreBookingRequirementRequest` (rule giá tái dùng), `RequirementLockedAfterRoomChargeException` (tái dùng nguyên vẹn, không bypass).

---

## 5. Milestone Breakdown (REVISION 3 — thu gọn Milestone 1, chuyển release_batches sang Milestone 4)

### Milestone 1 — Data relationship, locking, backfill (KHÔNG business logic release)

**Đúng 13 hạng mục theo yêu cầu Product Owner Mục III, không hơn không kém:**

1. Migration `add_booking_requirement_id_to_room_assignments_table` — nullable FK, `restrictOnDelete()` (Quyết định #13).
2. Model relationships: `RoomAssignment::bookingRequirement(): BelongsTo`, thêm cột vào `$fillable`.
3. FK + delete protection: `restrictOnDelete()` ở DB (mục 1) + guard tầng ứng dụng trong `deleteRequirement()` (mục 7).
4. Backfill command: dry-run trước, idempotent, KHÔNG gán row ambiguous (≥2 dòng khớp → NULL + báo cáo; 0 dòng khớp → NULL + báo cáo).
5. Khoá `Booking` cho `addRequirement()`, `updateRequirement()`, `deleteRequirement()` — **bọc `DB::transaction()` mới cho `addRequirement()`/`deleteRequirement()` (hiện chưa có transaction nào, đã xác nhận qua đọc code — Architecture Review Mục 10), thêm lock `Booking` đầu transaction cho cả 3**.
6. Lock `BookingRequirement` theo thứ tự `id` tăng dần — trong `addRequirement()` lock toàn bộ dòng cùng room_type (đóng cửa sổ race với các luồng mới); trong `updateRequirement()`/`deleteRequirement()` lock đúng dòng đang thao tác.
7. Guard không xoá `BookingRequirement` đã từng được BẤT KỲ `RoomAssignment` nào tham chiếu (kể cả `Released`) — check `RoomAssignment::where('booking_requirement_id', $id)->exists()` không lọc status, throw exception thân thiện TRƯỚC khi chạm `restrictOnDelete()` ở DB.
8. Allocation helper/internal contract: viết (và unit test) thuần tuý thuật toán Cấp A (room-type reconciliation) + Cấp B (line allocation, bao gồm quy tắc folio-locked tạo dòng mới — Architecture Review Mục 14b điểm 5) như 1 method nội bộ độc lập, **CHƯA nối vào bất kỳ orchestration assign/release nào** — Milestone 2/3 mới wire nó vào.
9. Test: schema (migration up/down), backfill (dry-run trên dataset có ambiguous), locking (5 cặp tình huống deadlock — Architecture Review Mục 20), concurrency (hai người cùng add, add đồng thời Room-Board-first giả lập, update đồng thời assign giả lập, delete đồng thời release giả lập, lost update, duplicate merge-key line).
10. **Chưa route mới.**
11. **Chưa UI mới.**
12. **Chưa `release_batches`** (bảng + migration + model — chuyển hẳn sang Milestone 4).
13. **Chưa viết `releaseAssignments()` (bulk release) hoàn chỉnh** — chuyển sang Milestone 4.

**Điều kiện bắt đầu:** không còn điều kiện chặn nào — Quyết định #13 đã LOCKED (Architecture Review Mục 29). Milestone 1 có thể bắt đầu code ngay sau khi tài liệu REVISION 3 được duyệt (ở 1 task riêng, không phải task này).

### Milestone 2 — Atomic mapping cho luồng demand-first hiện có (không phải "hardening" thụ động như REVISION 2)

**Đúng yêu cầu Product Owner Mục IV — không chấp nhận 2 transaction:**

- Extract 1 private helper trên `RoomAssignmentService` (`lockRoomRecheckAndCreateAssignment()` hoặc tên tương đương) chứa đúng logic lock Room + recheck conflict + tạo `RoomAssignment` hiện có trong `assignRooms()` — **là nơi duy nhất chứa logic này**, không copy-paste thành 2 phiên bản.
- Refactor `assignRooms()` (public, chữ ký KHÔNG đổi) để gọi helper trên trong vòng lặp — hành vi/test hiện có PASS không đổi (refactor thuần tuý).
- Method MỚI (ví dụ `assignRoomsWithRequirementLink()`) gọi CÙNG helper, trong CÙNG 1 `DB::transaction()`, resolve `booking_requirement_id` NGAY sau khi tạo từng assignment:
  - Room_type có đúng 1 dòng requirement active → tự động gắn.
  - Room_type có ≥2 dòng active → bắt buộc payload có `target_requirement_id` cho room_type đó, thiếu thì từ chối request bằng lỗi validate (fail fast, TRƯỚC khi vào transaction) — không tạo assignment `booking_requirement_id=NULL` qua luồng mới (Quyết định #13).
- `RoomAssignmentController::store()` (route KHÔNG đổi) chuyển sang gọi method mới thay vì `assignRooms()` trực tiếp.
- UI demand-first giữ nguyên cho trường hợp phổ biến (1 dòng requirement); chỉ thêm 1 lựa chọn tối thiểu (không phải panel đầy đủ của Milestone 3) khi backend trả lỗi "cần chọn target_requirement_id" cho trường hợp hiếm gặp (room_type có nhiều dòng).

**Điều kiện bắt đầu:** Milestone 1 hoàn tất (cần private helper + cột `booking_requirement_id` + allocation helper).

### Milestone 3 — Room-Board-first reverse synchronization

- Route/controller mới (mở rộng payload endpoint hiện có, không đổi route) nhận `price_groups` (giá/nguồn giá/ghi chú/`target_requirement_id`) theo room_type.
- UI: panel "Xác nhận giá/dòng nhu cầu theo nhóm" — điều kiện hiện: không khớp demand, vượt số lượng, HOẶC >1 dòng requirement active. Khi tạo dòng requirement MỚI: thêm 3 trường guest fields (`adults` mặc định = số phòng nhóm, `children_under_6`/`children_over_6` mặc định 0, cho sửa) — Quyết định #19.
- Thuật toán Cấp A+B đầy đủ (Architecture Review Mục 14), bao gồm quy tắc folio-locked (tạo dòng mới thay vì sửa dòng bị khoá — Mục 14b điểm 5).
- Assignment + demand + Stay atomic tuyệt đối trong cùng transaction (Quyết định #17) — dùng lại private helper Milestone 2, không viết lại logic lock Room.

**Điều kiện bắt đầu:** Milestone 1 + 2 hoàn tất.

### Milestone 4 — Bulk release (bao gồm `release_batches`, chuyển từ Milestone 1)

- Migration `create_release_batches_table` (`id`, `booking_id`, `actor_id`, `released_at`, `reason`, `note`, `reduce_demand`, timestamps).
- Migration `add_release_batch_id_to_room_assignments_table` (nullable FK).
- Model `ReleaseBatch` + đăng ký `ReleaseBatch::observe(AuditObserver::class)` trong `AppServiceProvider`.
- `RoomAssignmentService::releaseAssignments()`: lock theo thứ tự B (Booking → Stay theo `room_assignment_id` asc → RoomAssignment theo `id` asc → BookingRequirement theo `id` asc); kiểm tra all-or-nothing cho legacy ambiguous (`reduce_demand=true` + có row `NULL` → từ chối toàn bộ TRƯỚC khi ghi — Quyết định #14); kiểm tra all-or-nothing cho folio-locked requirement (Mục VIII); ghi `release_reason` trên từng assignment (tương thích màn hình cũ — Quyết định #16), tạo 1 `ReleaseBatch`, set `release_batch_id` cho tất cả; giảm đúng `booking_requirement_id` mỗi assignment khi `reduce_demand=true`.
- `BulkReleaseAssignmentRequest`, route + controller `bulkRelease()`.
- UI: multi-select bảng lịch sử phân phòng, popup bulk release (lý do + ghi chú cấp batch + checkbox giảm demand mặc định TẮT + cảnh báo ambiguous/folio-locked trước khi submit), thay `releaseAll()` cũ.
- Authorization: `room.unassign` luôn bắt buộc; `booking.update` chỉ bắt buộc khi `reduce_demand=true` — kiểm tra tách biệt, per-resource cho từng assignment.

**Điều kiện bắt đầu:** Milestone 1 hoàn tất (không phụ thuộc Milestone 2/3 về mặt kỹ thuật, nhưng nên làm sau M3 để UI nhất quán).

### Milestone 5 — Audit, concurrency, mobile, regression hardening

- Test concurrency thật cho toàn bộ 5 cặp tình huống Locking Plan (Architecture Review Mục 20).
- Test mobile (360×800/390×844/412×915).
- Full regression suite hiện có PASS nguyên trạng, đặc biệt `BookingManagementUiTest`.
- Test Authorization Matrix với role tuỳ chỉnh (`room.unassign` không có `booking.update`).
- Review chéo với `docs/implementation-reports/booking-table-collapsible-actions-report.md` (tính năng khác đang treo trên cùng `Show.vue`) để tránh xung đột merge.

---

## 6. API and Request Contracts

**Bulk assign atomic (Milestone 2, demand-first hardening):**
```
POST /admin/bookings/{booking}/assignments   (route không đổi)
{
  room_ids: number[],
  start_at, end_at,
  target_requirement_id?: { [room_type_id]: number }  // bắt buộc khi room_type có >1 dòng active
}
```

**Bulk assign với demand sync (Milestone 3):**
```
POST /admin/bookings/{booking}/assignments
{
  room_ids: number[],
  start_at, end_at,
  price_groups?: {
    [room_type_id]: {
      room_price, price_source, note,
      target_requirement_id?: number,
      adults?: number,              // mặc định = số phòng nhóm nếu tạo dòng mới
      children_under_6?: number,    // mặc định 0
      children_over_6?: number      // mặc định 0
    }
  }
}
```

**Bulk release (Milestone 4):**
```
POST /admin/bookings/{booking}/assignments/bulk-release
{
  assignment_ids: number[],
  release_reason?: string,
  release_note?: string,      // cấp batch, lưu vào release_batches.note
  reduce_demand: boolean
}
```

## 7. Transaction and Locking Plan

Xem đầy đủ Architecture Review Mục 20 — **2 thứ tự riêng biệt, không dùng chung 1 canonical order:**
- Assign (A): `Booking → Room [asc] → BookingRequirement [asc] → tạo RoomAssignment → tạo Stay`.
- Release (B): `Booking → Stay [by room_assignment_id asc] → RoomAssignment [by id asc] → BookingRequirement [asc]` — khớp đúng tiền lệ `checkIn()`/`releaseAssignment()` hiện có (Stay trước RoomAssignment).

## 8. Demand Synchronization Algorithm

Xem Architecture Review Mục 14 (Cấp A + Cấp B, bao gồm quy tắc folio-locked Mục 14b điểm 5 và guest fields điểm 6).

## 9. Bulk Assignment Algorithm

Xem Architecture Review Mục 18 — refactor bắt buộc dùng chung private helper, atomic mapping ngay từ Milestone 2.

## 10. Bulk Release Algorithm

Xem Architecture Review Mục 19 — all-or-nothing reject cho cả legacy ambiguous VÀ folio-locked requirement, không giảm một phần.

## 11. Pricing and Rate Source Handling

Không đổi so với REVISION 2: `PriceSource` enum hiện có, validate qua `StoreBookingRequirementRequest`. Khi tăng `quantity` dòng đã tồn tại, bắt buộc tái dùng guard `RequirementLockedAfterRoomChargeException`; nếu dòng khớp bị khoá, tạo dòng mới thay vì sửa (Mục 14b điểm 5) — không có ngoại lệ.

## 12. Audit Logging Plan

`AuditObserver` tự động cho `RoomAssignment`/`BookingRequirement`. Model mới `ReleaseBatch` (Milestone 4) đăng ký thêm 1 dòng trong `AppServiceProvider::boot()`.

## 13. Authorization Plan

Xem Architecture Review Mục 23. `bulkRelease()` kiểm tra `room.unassign` luôn, `booking.update` chỉ khi `reduce_demand=true`, tách biệt 2 permission, per-resource.

## 14. Migration and Data Backfill Plan

Milestone 1: `add_booking_requirement_id_to_room_assignments_table` (`restrictOnDelete()`) + backfill command. Milestone 4: `create_release_batches_table` + `add_release_batch_id_to_room_assignments_table`. Không gộp — xem Architecture Review Mục 16.

## 15. Deployment Considerations

Backfill (Milestone 1) là bước rủi ro cao hơn thuần migration — dry-run trên bản sao dữ liệu production trước, review báo cáo ambiguous/no-matching với vận hành trước khi chạy thật. Kế hoạch xử lý ambiguous thủ công (Architecture Review Mục 19b) nên hoàn tất trước khi Milestone 3/4 go-live, không bắt buộc trước Milestone 1/2 (vì Milestone 1/2 không phụ thuộc việc reconcile xong).

## 16. Manual QA Checklist

Theo 17 bước Prompt gốc Mục IV, bổ sung REVISION 3:
- Demand-first assign vào room_type có 1 dòng → xác nhận `booking_requirement_id` có giá trị ngay, không cần thao tác thêm.
- Demand-first assign vào room_type có 2 dòng, không truyền `target_requirement_id` → xác nhận bị từ chối rõ ràng, không tạo assignment NULL.
- Room-Board-first tạo dòng requirement mới → xác nhận panel có 3 trường guest fields, mặc định đúng `adults=quantity`, `children=0`.
- Room-Board-first excess khớp dòng đã bị khoá folio → xác nhận tạo dòng MỚI, không sửa dòng cũ, không lỗi ngầm.
- Bulk release có 1 assignment ambiguous + `reduce_demand=true` → xác nhận toàn bộ batch bị từ chối, không phòng nào được gỡ.
- Bulk release có assignment trỏ tới requirement đã khoá folio + `reduce_demand=true` → xác nhận toàn bộ batch bị từ chối.
- Thử xoá 1 `BookingRequirement` đã có assignment (kể cả đã Released) → xác nhận bị chặn với thông báo rõ, không throw lỗi DB thô.
- Kiểm tra mobile, Reception, role test tuỳ chỉnh.

## 17. Risks and Mitigations

Xem Architecture Review Mục 26. Rủi ro triển khai bổ sung: nếu Milestone 2 vô tình tạo mapping ở transaction thứ 2 (thay vì atomic trong cùng transaction tạo assignment) — đây là điều Product Owner đã TỪ CHỐI tường minh (Mục IV), phải coi là lỗi chặn merge, không phải "tối ưu sau".

## 18. Open Decisions

**Không còn open decision nào trong phạm vi đã duyệt** — 19 quyết định tại Architecture Review Mục 29 đã LOCKED. Nếu phát sinh quyết định mới trong lúc code Milestone 1, phải dừng và bổ sung vào Architecture Review trước khi tiếp tục (không tự quyết ngầm trong code).

## 19. Test Matrix

Đầy đủ 40 kịch bản Prompt gốc Mục XV, nhóm theo Milestone đã thu gọn:
- **M1:** migration up/down; backfill dry-run trên dataset ambiguous; lock 3 method requirement (test riêng từng method + test concurrency 6 kịch bản Mục VI); allocation helper unit test (Cấp A+B, bao gồm ví dụ số liệu 5/2/2 và trường hợp folio-locked); guard chặn hard-delete requirement có assignment tham chiếu (kể cả Released); 5 cặp deadlock (Architecture Review Mục 20).
- **M2:** kịch bản 1-8, 32-33, 39; atomic mapping (1 request, `booking_requirement_id` có giá trị ngay); từ chối khi thiếu `target_requirement_id` cho room_type ambiguous; refactor `assignRooms()` không đổi output (regression test).
- **M3:** kịch bản 3-9, 20-23, 34-36; demand không đổi khi chọn đúng số phòng còn thiếu; panel bắt chọn dòng khi >1 requirement line; guest fields mặc định đúng; excess khớp dòng khoá folio → tạo dòng mới.
- **M4:** kịch bản 10-19, 24-27, 37-38, 40; giảm đúng dòng qua `booking_requirement_id`; all-or-nothing reject cho ambiguous; all-or-nothing reject cho folio-locked; `release_reason` per-assignment đúng cho tương thích màn hình cũ; `release_batches` tạo đúng 1 row/batch, không mồ côi khi rollback.
- **M5:** 28-31; regression suite đầy đủ; concurrency thật; Authorization Matrix với role tuỳ chỉnh.

## 20. Rollback Plan

- M1: `migrate:rollback` migration mới; revert refactor `assignRooms()` nếu cần (giữ private helper không ảnh hưởng vì hành vi không đổi); backfill hỗ trợ revert (set lại `booking_requirement_id=NULL` hàng loạt).
- M2: revert method mới + controller call, `assignRooms()` gốc (đã refactor nhưng hành vi không đổi) không bị ảnh hưởng.
- M3/M4: revert route + controller method mới; method cũ không ảnh hưởng.

## 21. Definition of Done

Mỗi Milestone: test mới PASS, targeted suite PASS nguyên trạng, `npm run build` PASS, không migration/seeder chạy production ngoài quy trình đã duyệt, Manual QA theo Mục 16, báo cáo riêng theo format chuẩn dự án (Architecture Review → Implementation Plan → Implementation Report → Manual QA → Commit Closure → Push Closure).

## 22. Implementation Readiness

**Milestone 1: COMPLETE.** **Milestone 2: NOT STARTED.**

Milestone 1 đã triển khai và commit đúng phạm vi 13 hạng mục tại Mục 5 (data relationship, locking, backfill, test) — không route, không UI, không `release_batches`, không bulk release hoàn chỉnh, đúng như điều kiện đã đặt ra. ChatGPT đã review report Milestone 1: **APPROVED FOR MILESTONE 1 COMMIT**.

Điều kiện cho các Milestone tiếp theo (chưa bắt đầu):
- Milestone 2 bắt buộc atomic mapping trong cùng transaction — không có bước "gắn sau" ở transaction thứ hai. **Atomic assignment mapping vẫn chưa có ở Milestone 1** — đây là việc đầu tiên của Milestone 2.
- Milestone 3/4 vẫn phải tuân thủ đúng 19 quyết định đã khoá tại Architecture Review Mục 29, đặc biệt #14 (all-or-nothing reject) và #16 (schema `release_batches`).
- **Toàn bộ feature (Room Demand and Room Board Unification): NOT READY FOR PRODUCTION** — Milestone 1 chỉ là nền tảng dữ liệu/locking, không có bất kỳ chức năng người dùng cuối nào.

Chờ ChatGPT review kế hoạch Milestone 2 trước khi bắt đầu.
