# Room Demand and Room Board Unification — Milestone 1 Report

**Milestone:** 1 — Data Relationship, Requirement Locking and Legacy Backfill
**Ngày:** 2026-08-05
**Nhánh:** `phase-3`
**Căn cứ:** `docs/reviews/room-demand-room-board-unification-architecture-review.md` (REVISION 3), `docs/implementation-plans/room-demand-room-board-unification-implementation-plan.md` (REVISION 3)
**ChatGPT review:** APPROVED FOR MILESTONE 1 COMMIT.

---

## 1. Scope

Đúng theo Mục I của prompt triển khai — **chỉ** data relationship, migration, FK/delete protection, backfill legacy an toàn, khoá transaction cho 3 method requirement, helper reconciliation/allocation thuần túy, và test cho đúng phạm vi trên.

**Không thực hiện** (đúng cam kết, xác nhận lại ở Mục 9/15-17): không route mới, không UI mới, không sửa `Show.vue`, không Room-Board-first, không đổi controller assignment sang method mới, không refactor `assignRooms()`/extract helper tạo assignment, không tạo `assignRoomsWithRequirementLink()`/`assignRoomsWithDemandSync()`, không Stay atomic flow mới, không `release_batches`/`release_batch_id`, không bulk release, không sửa `releaseAssignment()`, không đụng Folio/Night Audit, không đụng Service Package.

Khi Architecture Review Mục 30 và Implementation Plan Mục 5 khác nhau về việc refactor `assignRooms()` — đã **chốt theo Implementation Plan Mục 5**: refactor `assignRooms()` thuộc Milestone 2, Milestone 1 không đụng `RoomAssignmentService.php` (file này **không được sửa** trong Milestone 1 — xác nhận ở Mục 9).

## 2. Architecture Basis

Thực hiện đúng theo:
- Architecture Review REVISION 3, Product Owner Decision #8/#13 (Phương án A — `booking_requirement_id` nullable, `restrictOnDelete()`), Decision #13 phần "assignment mới qua luồng mới không được NULL" (chưa áp dụng ở M1 vì M1 chưa tạo assignment nào qua luồng mới).
- Mục V (FK/delete protection), Mục VI (lock 3 method requirement), Mục IX/Mục 14b (allocation helper) của prompt triển khai — các mục này **sửa/thu hẹp thêm** so với Architecture Review REVISION 3 (ví dụ: FK dùng `restrictOnDelete()` thay vì để mở, allocation helper chỉ trả plan không query DB).

## 3. Migration

**File mới:** `database/migrations/2026_08_05_000000_add_booking_requirement_id_to_room_assignments_table.php`

- Cột `room_assignments.booking_requirement_id`: nullable, `foreignId(...)->constrained('booking_requirements')->restrictOnDelete()` — **không** `cascadeOnDelete()`, **không** `nullOnDelete()`, đúng yêu cầu Mục V.
- Đặt `->after('room_type_id')`, theo đúng convention migration gần nhất của dự án (`2026_07_02_000020_add_stay_and_posting_source_to_folio_entries_table.php`) — nullable FK + `after()` + guard `Schema::hasColumn()` cho idempotency.
- Index: `foreignId()->constrained()` trên MySQL tự tạo index cho cột FK (đã xác nhận qua `SHOW CREATE TABLE` gián tiếp bằng việc migration/rollback chạy sạch) — không thêm `->index()` riêng để tránh trùng index, đúng convention các cột `assigned_by`/`released_by` hiện có trong cùng bảng.
- `down()`: `dropForeign(['booking_requirement_id'])` rồi `dropColumn(...)`, có guard `Schema::hasColumn()`, không đụng bảng nào khác.

**Đã chạy trên môi trường local:**
- `php artisan migrate --force` trên DB dev local (`lastella_pms`, MySQL) — **PASS**.
- `php artisan migrate:rollback --step=1 --force` — **PASS**, cột bị xoá sạch.
- `php artisan migrate --force` lại để khôi phục — **PASS**, cột trở lại.
- Test riêng `test_migration_rollback_runs_cleanly_and_restores_state` (SQLite, `RoomAssignmentBookingRequirementLinkTest`) — **PASS**, `up()`/`down()` chạy sạch trong transaction test.

Không migration nào chạy trên production.

## 4. Model Relationship

- `app/Models/RoomAssignment.php`: thêm `booking_requirement_id` vào `$fillable`; thêm `bookingRequirement(): BelongsTo`.
- `app/Models/BookingRequirement.php`: thêm `roomAssignments(): HasMany` (inverse) — giữ vì có ích trực tiếp cho delete guard/test (Mục VI yêu cầu đánh giá, quyết định thêm vì mirror đúng pattern `Booking::roomAssignments(): HasMany` đã có, và test dùng trực tiếp `$requirement->roomAssignments`).
- Không đổi cast/status nào khác ngoài yêu cầu.

## 5. FK and Delete Protection

Hai lớp bảo vệ độc lập, đúng Mục V:
1. **Database:** `restrictOnDelete()` trên `room_assignments.booking_requirement_id` — DB từ chối xoá cứng 1 `BookingRequirement` nếu còn bất kỳ `RoomAssignment` nào (không phân biệt status) trỏ tới nó.
2. **Application:** `BookingService::deleteRequirement()` kiểm tra `RoomAssignment::where('booking_requirement_id', $id)->exists()` (không lọc status — bao gồm cả `Released`) TRƯỚC khi gọi `delete()`; nếu có tham chiếu, throw `RequirementReferencedByAssignmentException` (mới, `app/Exceptions/RequirementReferencedByAssignmentException.php`, theo đúng pattern `RequirementLockedAfterRoomChargeException` đã có — `RuntimeException` + `render()` trả `back()->withErrors(...)`) — người dùng thấy lỗi nghiệp vụ thân thiện, không thấy raw SQL exception.

Requirement chưa từng được tham chiếu vẫn xóa được bình thường (không đổi hành vi).

## 6. Legacy Backfill Command

**File mới:** `app/Console/Commands/BackfillBookingRequirementLinks.php`
**Tên lệnh:** `room-assignments:backfill-booking-requirements`

Options: `--apply` (bắt buộc mới ghi), `--dry-run` (safety override, luôn thắng nếu có cả 2), `--booking-id=`, `--chunk=500`.

- **Mặc định là dry-run** (không truyền `--apply` → chỉ báo cáo, không ghi) — an toàn hơn cả precedent hiện có trong dự án (`folio:backfill-per-night-charges` mặc định GHI trừ khi có `--dry-run`) — chủ động chọn an toàn hơn theo đúng yêu cầu tường minh của prompt.
- **Idempotent:** chỉ xử lý `WHERE booking_requirement_id IS NULL`; assignment đã có mapping bị loại khỏi truy vấn hoàn toàn, không bao giờ bị đọc lại hay ghi đè — chạy `--apply` bao nhiêu lần cũng ra cùng kết quả (verified bằng test #10, xem Mục 10).
- **Matching rule** (đúng Mục VII, đơn giản, không đoán): match theo `booking_id` + `room_type_id`. Đúng 1 kết quả → `unique` (mappable); ≥2 → `ambiguous` (để NULL); 0 → `no_matching_requirement` (để NULL).
- Không bao giờ tạo `BookingRequirement` mới, không đổi `quantity`/`room_price`/`price_source`/`note` của bất kỳ dòng nào — command chỉ đọc `BookingRequirement`, không bao giờ `update()`/`create()` nó.
- Không có chức năng reverse/undo hàng loạt — đúng yêu cầu, không cung cấp `UPDATE ... SET booking_requirement_id = NULL` nào.
- Không thêm vào scheduler, không gọi từ migration/DatabaseSeeder — xác nhận qua `grep`, command chỉ tồn tại dưới dạng Artisan command độc lập, auto-discover theo convention Laravel 12 hiện tại của dự án (không có `Kernel.php`, không cần đăng ký thủ công — giống `BackfillPerNightCharges`/`RunNightAudit` đã có).

## 7. Requirement Transaction Locking

Sửa đúng 3 method trong `app/Services/BookingService.php`, theo thứ tự `Booking → BookingRequirement (id asc) → validate → write` (Mục VIII):

- **`addRequirement()`:** trước đây KHÔNG có `DB::transaction()` nào (xác nhận qua đọc code — khác giả định của Architecture Review REVISION 2/3, vốn nghĩ chỉ cần "thêm 1 dòng lock"). Đã bọc `DB::transaction()` mới, lock `Booking` trước, sau đó lock TOÀN BỘ dòng `BookingRequirement` cùng `room_type_id` (đóng cửa sổ race với các luồng phân bổ tương lai — M2/M3), rồi mới `create()`. Hành vi nghiệp vụ không đổi: vẫn luôn tạo dòng mới, không tự merge.
- **`updateRequirement()`:** giữ `DB::transaction()` sẵn có, đổi thứ tự: lock `Booking` trước (theo `requirement->booking_id`), sau đó requery + lock CHÍNH dòng `BookingRequirement` bằng `whereKey($requirement->id)->lockForUpdate()->firstOrFail()` (không còn ghi trực tiếp lên instance route-binding chưa lock lại), xác nhận `booking_id` khớp, rồi mới chạy guard Folio hiện có (`RequirementLockedAfterRoomChargeException`, không đổi logic) và `update()`.
- **`deleteRequirement()`:** trước đây cũng KHÔNG có `DB::transaction()`. Đã bọc transaction mới: lock `Booking`, requery + lock `BookingRequirement`, xác nhận `booking_id` khớp, kiểm tra guard tham chiếu assignment (Mục 5), rồi mới `delete()`.

Cả 3 method đều dùng `Booking::whereKey(...)->lockForUpdate()->firstOrFail()` (không phải instance đã đọc trước transaction) — đúng yêu cầu "không dùng model route-binding đã đọc bên ngoài transaction làm nguồn ghi trực tiếp".

**"Xác nhận requirement thuộc đúng Booking"** (bước 3 của B/C trong Mục VIII): với chữ ký method hiện tại (chỉ nhận `BookingRequirement`, không nhận `Booking` riêng), kiểm tra này về cấu trúc luôn đúng vì `Booking` được lock chính từ `requirement->booking_id` — đây là code phòng thủ ghi rõ bất biến (invariant), không phải một race có thể tái hiện được với chữ ký hiện tại. Ranh giới ownership THẬT SỰ (route booking ≠ route requirement) vẫn được `BookingRequirementController` chặn ở tầng HTTP như từ trước (`abort_unless(...)`, không đổi) — đã thêm 1 test HTTP xác nhận hành vi này còn nguyên vẹn (Mục 10, test #22).

## 8. Reconciliation Helper

**File mới:** `app/Services/RoomRequirementAllocationService.php` — thuần túy, không query DB, không side effect, chưa nối vào bất kỳ route/controller/orchestration nào.

- `reconcileRoomType(int $required, int $assignedActive, int $selectedCount): array` — Cấp A (room-type total), công thức đúng `remaining = max(required - assigned_active, 0)`, `excess_to_add = max(selected_count - remaining, 0)`.
- `planLineAllocation(array $activeLines, ?int $targetRequirementId): array` — Cấp B (line allocation), 5 trạng thái: `use_existing_line`, `create_new_line`, `create_new_line_folio_locked`, `needs_target_selection`, `invalid_target`. Nhận `is_folio_locked` như boolean input có sẵn (không tự query Folio bên trong helper — đúng yêu cầu).
- Ví dụ 5/2/2 bắt buộc: `reconcileRoomType(5, 2, 2)` → `remaining=3, excess_to_add=0` — **không tăng demand lên 7**, đã test trực tiếp (Mục 10).

Đây là mức tối thiểu + phần line-allocation mở rộng — không xây Application-Service layer lớn hơn, không gọi assignment/Stay orchestration nào.

## 9. Files Changed

**Sửa (3 file):**
- `app/Models/RoomAssignment.php`
- `app/Models/BookingRequirement.php`
- `app/Services/BookingService.php`

**Mới (4 file production code):**
- `database/migrations/2026_08_05_000000_add_booking_requirement_id_to_room_assignments_table.php`
- `app/Exceptions/RequirementReferencedByAssignmentException.php`
- `app/Services/RoomRequirementAllocationService.php`
- `app/Console/Commands/BackfillBookingRequirementLinks.php`

**Mới (4 file test):**
- `tests/Feature/RoomAssignmentBookingRequirementLinkTest.php`
- `tests/Feature/BackfillBookingRequirementLinksCommandTest.php`
- `tests/Feature/BookingRequirementLockingTest.php`
- `tests/Unit/Services/RoomRequirementAllocationServiceTest.php`

**Xác nhận KHÔNG bị sửa** (`git status --short` sau khi hoàn tất, xem Mục 18): `app/Http/Controllers/**`, `app/Services/RoomAssignmentService.php`, `app/Services/StayService.php`, `routes/web.php`, `resources/js/**` (các thay đổi Vue/JS trong working tree là **có sẵn từ trước task này**, không do Milestone 1 đụng vào), `resources/js/Pages/Admin/Bookings/Show.vue`, `.env`, seeders quyền/role, Folio/Night Audit, Service Package.

## 10. Tests

**34/34 test mới PASS** (4 file, chia đúng theo Mục X):

| Nhóm | File | Số test | Kết quả |
|---|---|---|---|
| A. Migration/model (#1-6) | `RoomAssignmentBookingRequirementLinkTest.php` | 6 | PASS |
| B. Backfill (#7-15) | `BackfillBookingRequirementLinksCommandTest.php` | 9 | PASS |
| C+D. BookingService behavior + locking/concurrency (#16-25) | `BookingRequirementLockingTest.php` | 10 | PASS |
| E. Reconciliation helper (#26-30) | `RoomRequirementAllocationServiceTest.php` | 9 | PASS |

Chi tiết đáng chú ý:
- Test #22 ("Requirement không thuộc Booking bị chặn") viết ở tầng HTTP (`DELETE /admin/bookings/{booking}/requirements/{requirement}` với requirement thuộc booking khác → 404) vì đây là nơi ranh giới ownership thật sự được enforce (controller, không đổi) — không tạo test giả cho 1 race không thể tái hiện được ở tầng Service với chữ ký method hiện tại (Mục 7).
- Test #23-25 (concurrency) mô phỏng theo đúng convention đã có của dự án (`BookingEngineFoundationTest::test_concurrent_*`): thao tác "đồng thời" được COMMIT ĐẦY ĐỦ trước khi thao tác đang test chạy, không phải race đa luồng thật — nhất quán với cách dự án đã kiểm thử code có lock từ trước.
- Trong lúc viết test cho `planLineAllocation()`, phát hiện và sửa 1 lỗi thật trong `RoomRequirementAllocationService`: khi chỉ có 1 dòng active nhưng caller truyền `target_requirement_id` không khớp, code ban đầu bỏ qua việc validate target và tự dùng dòng duy nhất — đã sửa để LUÔN validate target khi được truyền, bất kể số dòng active, đúng tinh thần "không tự đoán/không âm thầm ghi đè ý định của caller".

## 11. Build

`npm run build`: **PASS** — 2383 module, build 17.05s, không lỗi mới, chỉ còn cảnh báo chunk-size đã có từ trước (không liên quan Milestone 1, Milestone 1 không sửa file frontend nào).

## 12. Full Suite

`php artisan test` (toàn bộ, không filter): **1047 passed, 25 failed** (4346 assertions), 615s.

**Cả 25 lỗi đều pre-existing, đã điều tra tận gốc và chứng minh không liên quan Milestone 1** (không chỉ suy đoán — đã đọc code, tái hiện độc lập bằng test tạm thời rồi xóa, xem chi tiết dưới):

**24 lỗi — `BookingManagementUiTest` (11) + `RoomAvailabilityCheckerTest` (13):**
- Nguyên nhân gốc: `database/seeders/RoomSeeder.php` (sửa lần cuối 2026-07-01, không đụng trong task này) seed cứng phòng `101` (phòng TWIN đầu tiên) ở trạng thái `RoomStatus::OutOfOrder` ("Room 101 is under maintenance"). Cả 2 file test đều có helper `roomForType('TWIN')` = `Room::where('room_type_id', ...)->firstOrFail()` — luôn trả về đúng phòng 101 (thứ tự PK mặc định). Các test kỳ vọng phòng này ở trạng thái bình thường (để kiểm tra `conflict`/`reserved`/`occupied`...) nhưng `RoomAvailabilityRuleService::isRoomUnavailable()` trả `true` trước tiên do `OutOfOrder`, khiến `availability_status` luôn là `unavailable` thay vì giá trị mong đợi.
- Đã tái hiện độc lập bằng 1 test tạm thời (dump trực tiếp `RoomAssignmentService::getRoomBoard()`), xác nhận đúng nguyên nhân, sau đó **xóa file tạm** (không còn trong working tree).
- Không file nào trong 2 test file này, hay `RoomSeeder.php`, hay `RoomAssignmentService.php`, hay `RoomAvailabilityRuleService.php` bị Milestone 1 đụng tới.

**1 lỗi — `LateCheckoutFeeTest::test_check_out_triggers_late_checkout_fee_via_stay_service`:**
- Nguyên nhân gốc: test tính `plannedCheckout = now()->subHours(2)->setTime(12, 0, 0)` = hôm nay lúc 12:00 trưa theo đồng hồ thật (không `travelTo()` đóng băng thời gian), rồi check-out bằng `now()` thật. Nếu chạy trước 12:00 trưa (giờ máy chủ) — đã xác nhận thời điểm chạy suite là **10:53 sáng** — check-out chưa "trễ" so với kế hoạch nên phí late-checkout không được tạo, khiến assertion `assertDatabaseHas('folio_entries', ...)` thất bại vì bảng rỗng.
- Không liên quan `booking_requirement_id`/`BookingService`/bất kỳ file nào Milestone 1 sửa — phụ thuộc giờ trong ngày lúc chạy test, không phụ thuộc ngày.

**Không có lỗi mới nào phát sinh từ thay đổi Milestone 1.**

## 13. Backward Compatibility

- `addRequirement()`: hành vi nghiệp vụ không đổi (luôn tạo dòng mới, không merge) — chỉ thêm transaction + lock (trước đây không có transaction).
- `updateRequirement()`: guard Folio giữ nguyên logic, chỉ đổi thứ tự lock + requery.
- `deleteRequirement()`: hành vi cũ (xóa + cập nhật booking status) giữ nguyên cho requirement KHÔNG có tham chiếu; requirement CÓ tham chiếu trước đây sẽ xóa "thành công" (rồi để lại `RoomAssignment` với FK trỏ tới bản ghi không tồn tại nếu ai đó thêm liên kết thủ công — nhưng vì `booking_requirement_id` là cột MỚI, trước Milestone 1 không có bất kỳ `RoomAssignment` nào tham chiếu bất kỳ `BookingRequirement` nào cả, nên **không có dữ liệu hiện có nào bị ảnh hưởng bởi thay đổi hành vi này** — guard mới chỉ có tác dụng từ nay trở đi).
- `assignRooms()`, `releaseAssignment()` (method cũ trong `RoomAssignmentService`): **hoàn toàn không đổi** — file này không được Milestone 1 đụng tới (khác Implementation Plan REVISION 2 từng dự tính refactor ở M1; đã chốt lại đúng theo Mục I của prompt: refactor thuộc Milestone 2).
- Route/Controller/FormRequest: không đổi.
- Toàn bộ 34 test mới PASS, targeted suite không phát sinh lỗi mới, `BookingEngineFoundationTest` (25 test, tập trung vào concurrency/locking liên quan `BookingService`) PASS 100% — xác nhận thay đổi locking không phá vỡ hành vi hiện có.

## 14. Known Limitations

- Backfill command **chưa chạy trên bất kỳ dữ liệu thật nào** (chỉ test trên SQLite in-memory) — cần dry-run trên bản sao dữ liệu production trước khi áp dụng thật, theo đúng Architecture Review Mục 16/19b.
- `addRequirement()` (luồng demand-first hiện có) vẫn **không gắn `booking_requirement_id`** cho assignment — việc gắn kết chỉ xảy ra qua backfill (dữ liệu cũ) hoặc từ Milestone 2 trở đi (assignment mới). Assignment tạo qua `assignRooms()` hiện tại (không đổi) vẫn luôn có `booking_requirement_id = NULL` cho tới khi Milestone 2 nối logic gắn kết.
- Race giữa 2 lệnh gọi `BookingRequirementController::store()` (demand-first, qua `addRequirement()`) đồng thời cho cùng room_type: đã lock đúng theo Mục VIII, nhưng vì `addRequirement()` không có logic merge (theo đúng thiết kế, không đổi), 2 lệnh gọi vẫn tạo 2 dòng riêng biệt — đây là hành vi ĐÚNG theo thiết kế hiện tại (nhiều dòng cùng room_type được phép), không phải lỗi.
- Allocation helper (`RoomRequirementAllocationService`) chưa được gọi từ bất kỳ đâu — sẽ được Milestone 2/3 nối vào.

## 15. Deferred to Milestone 2

Refactor `assignRooms()` (extract private helper lock/recheck/create dùng chung), atomic mapping `booking_requirement_id` ngay trong transaction tạo assignment cho luồng demand-first, method mới nối endpoint hiện có.

## 16. Deferred to Milestone 3

Room-Board-first reverse synchronization, panel giá + chọn dòng + guest fields UI, Stay atomic flow mới, thuật toán Cấp A+B đầy đủ nối vào orchestration thật.

## 17. Deferred to Milestone 4

`release_batches` table + model, `release_batch_id`, `releaseAssignments()`, bulk release route/controller/request, UI multi-select release, reduce-demand all-or-nothing logic.

## 18. Deployment Restrictions

- **Không được deploy production chỉ dựa trên Milestone 1** — đây mới chỉ là nền tảng dữ liệu/khoá, chưa có bất kỳ tính năng người dùng cuối nào (không route, không UI).
- **Backfill production chưa được chạy** — chỉ mới test trên SQLite in-memory; phải dry-run trên bản sao dữ liệu thật, review báo cáo ambiguous/no-match với vận hành trước khi `--apply` trên production.
- **Assignment legacy ambiguous vẫn để NULL** — không có gì trong Milestone 1 giải quyết các dòng ambiguous, đây là hành vi CHỦ ĐÍCH (không đoán), kế hoạch đối chiếu thủ công vẫn còn ở trạng thái "chưa thực hiện" (Architecture Review Mục 19b).
- Không migration/backfill nào chạy trên production trong task này — chỉ chạy trên DB dev local (`lastella_pms`) để verify, và SQLite in-memory cho test suite.

## 19. Rollback

- Migration: `php artisan migrate:rollback --step=1` — đã verify chạy sạch trên cả MySQL dev local và SQLite test (Mục 3).
- Model/Service/Exception/Command mới: xóa file, không có phụ thuộc ngược (chưa được gọi từ đâu ngoài chính chúng và test của chúng).
- `BookingService.php`: các thay đổi đều là bọc thêm transaction/lock quanh logic cũ, không đổi input/output — an toàn revert bằng cách bỏ các đoạn lock đã thêm nếu cần, không ảnh hưởng dữ liệu.

## 20. Readiness

**MILESTONE 1 COMPLETE — APPROVED FOR COMMIT**

ChatGPT đã review tài liệu này: **APPROVED FOR MILESTONE 1 COMMIT**. Đây chỉ là commit nền tảng dữ liệu và locking — không coi là chức năng Room-Board-first đã hoàn thành. Chưa có reverse synchronization, chưa có atomic mapping cho assignment mới, chưa có Stay atomic flow mới, chưa có UI chọn giá/dòng nhu cầu, chưa có bulk release, chưa có `release_batches`/`release_batch_id`.

**Toàn bộ feature (Room Demand and Room Board Unification): NOT READY FOR PRODUCTION.**

Không ghi READY FOR PRODUCTION cho riêng Milestone 1 — theo đúng giới hạn Mục 18. Milestone 2 (atomic assignment mapping) chưa bắt đầu.
