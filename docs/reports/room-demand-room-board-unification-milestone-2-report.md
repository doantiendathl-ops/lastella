# Room Demand and Room Board Unification — Milestone 2 Report

**Milestone:** 2 — Atomic Requirement Mapping for Existing Demand-First Assignment
**Ngày:** 2026-08-05
**Nhánh:** `phase-3`
**Căn cứ:** `docs/reviews/room-demand-room-board-unification-architecture-review.md` (REVISION 3), `docs/implementation-plans/room-demand-room-board-unification-implementation-plan.md`, `docs/reports/room-demand-room-board-unification-milestone-1-report.md`
**ChatGPT review:** APPROVED FOR MILESTONE 2 COMMIT.
**Milestone 2:** COMPLETE. **Milestone 3:** NOT STARTED. Chưa deploy production.

---

## 1. Scope

Đúng theo Mục II của prompt triển khai: mọi `RoomAssignment` mới tạo qua luồng phân phòng demand-first hiện tại (`RoomAssignmentController::store()`) phải được liên kết chính xác với 1 `BookingRequirement` bằng `booking_requirement_id` **ngay trong cùng transaction** tạo assignment — không tạo assignment trước rồi chạy transaction thứ hai để gắn mapping. Luồng người dùng (nhập nhu cầu → mở Sơ đồ phòng → chọn phòng → xác nhận) giữ nguyên tối đa; chỉ thêm bước chọn dòng nhu cầu khi thật sự mơ hồ (room_type có >1 dòng hợp lệ).

**Không thực hiện** (đúng cam kết, xác nhận lại ở Mục 20-21): không Room-Board-first reverse synchronization, không tự tạo `BookingRequirement`, không tự tăng `quantity`, không áp dụng `excess_to_add` vào DB, không panel giá đầy đủ, không guest fields UI, không Stay atomic flow mới, không `release_batches`/`release_batch_id`, không bulk release, không sửa `releaseAssignment()`, không sửa `moveRoom()`, không đụng Folio/Night Audit, không backfill `--apply`, không migration mới, không seeder, không đổi permission.

## 2. Milestone 1 Checkpoint

Commit `3a1d0985ccff1ffdc76b55d500d28a22a5676620` (đã push `origin/phase-3`) cung cấp: `room_assignments.booking_requirement_id` (nullable, FK `restrictOnDelete()`), `RoomAssignment::bookingRequirement()`, `BookingRequirement::roomAssignments()`, backfill command an toàn, khoá transaction cho `addRequirement/updateRequirement/deleteRequirement`, `RoomRequirementAllocationService` thuần túy (chưa nối vào đâu). HEAD trước khi bắt đầu Milestone 2 đúng bằng commit này (xác nhận ở Mục 6 phần Preflight).

## 3. Current Assignment Flow Before Change

Trả lời đúng 15 câu hỏi bắt buộc (Mục VI), dựa trên đọc code thực tế, không suy đoán từ tài liệu:

1. **`assignRooms()` nhận tham số gì:** `(Booking $booking, array $assignments)` — mỗi phần tử `$assignments` có `room_id`, `room_type_id` (tuỳ chọn), `start_at`/`end_at` (tuỳ chọn, mặc định theo `$booking`).
2. **Transaction ở đâu:** mở 1 `DB::transaction()` duy nhất bao quanh toàn bộ vòng lặp, ngay trong `assignRooms()`.
3. **Booking được lock chưa:** **Không.** `assignRooms()` (trước M2) không hề `lockForUpdate()` trên `Booking`.
4. **Room lock theo thứ tự nào:** `usort($assignments, ...room_id <=> ...)` sắp tăng dần **trước khi** vào transaction, sau đó lock từng `Room` theo đúng thứ tự đó bên trong vòng lặp (không lock hàng loạt trước).
5. **Availability recheck ở đâu:** `$this->rules->hasConflict($room->id, $startAt, $endAt)` — gọi ngay sau khi lock Room, trước khi tạo assignment, bên trong vòng lặp (per-room, không tách rời).
6. **RoomAssignment được create ở đâu:** `RoomAssignment::create([...])` ngay trong vòng lặp, ngay sau bước recheck, cùng 1 lệnh (không update sau).
7. **Stay tạo trong hay ngoài transaction:** **Ngoài.** `RoomAssignmentController::store()` gọi `assignRooms()` (transaction đã COMMIT), rồi lặp gọi `$this->stays->createStayFromAssignment($assignment)` cho từng assignment — xác nhận lại bằng đọc code, không đổi trong M2 (xem Mục 13/19).
8. **Booking status update ở đâu:** `$this->bookings->updateBookingAssignmentStatus($booking)` — gọi 1 lần cuối, bên trong transaction của `assignRooms()`.
9. **Controller store gọi service thế nào:** build `$roomIds` từ `room_id`/`room_ids` trong request, fetch `Room` model để lấy `room_type_id` xác thực (không tin giá trị client gửi), gọi `assignRooms($booking, [...])`, rồi loop tạo Stay, redirect về tab `room_map`.
10. **Frontend gửi payload gì:** `{ room_ids: number[], start_at, end_at }` — qua `assignmentForm` (Inertia `useForm`) tới `POST /admin/bookings/{booking}/assignments`.
11. **UI có biết dòng requirement nào đang dùng không:** **Không**, trước M2. `assignmentSummaryWithSelection` chỉ tổng hợp ở mức room_type (required/assigned/remaining), không phân biệt từng dòng `BookingRequirement`.
12. **1 request có nhiều room_type không:** **Có** — `room_ids` có thể chứa phòng thuộc nhiều room_type khác nhau trong cùng 1 lượt submit; `room_type_id` của từng phòng được controller tự suy ra từ `Room` model tương ứng.
13. **`BookingRequirement` có active/status/soft-delete thật không:** **Không.** Đọc trực tiếp model + migration: không có cột `status`/`active`/`is_active`, không dùng trait `SoftDeletes`.
14. **Nếu không có `active`, dòng hợp lệ xác định bằng gì:** `quantity > 0` — theo đúng Architecture Review REVISION 3 Quyết định #18 ("quantity về 0 → giữ dòng, ẩn khỏi danh sách mặc định, không hard delete"); đây là tín hiệu "hợp lệ" DUY NHẤT tồn tại trong schema hiện tại.
15. **`Show.vue` có local diff sẵn hay không:** **Có** — 2 hunk từ phiên làm việc trước (chưa commit, thuộc tính năng "booking table collapsible actions" khác): (a) đổi class header cho responsive mobile; (b) bọc khung `overflow-x-auto` quanh Sơ đồ phòng cho mobile. Đã đọc `git diff` trước khi sửa, không ghi đè — xem Mục 9 để phân biệt rõ hunk cũ/mới.

Không phát hiện mâu thuẫn kiến trúc nào giữa code thực tế và 3 tài liệu — không có Architecture Gap cần báo cáo.

## 4. Refactored Assignment Core

Trong `app/Services/RoomAssignmentService.php`:
- Extract **1 private helper duy nhất** `lockRoomRecheckAndCreateAssignment(Booking $booking, array $assignment, ?int $bookingRequirementId = null): RoomAssignment` — chứa đúng nguyên khối logic cũ (lock Room, recheck `hasConflict()`, tạo `RoomAssignment`), nhận thêm `booking_requirement_id` (mặc định `null`) và ghi nó **trong cùng lệnh `create()`** — không có bước update sau.
- `assignRooms()` (public, method cũ) **refactor nội bộ để gọi helper này**, KHÔNG đổi chữ ký, KHÔNG đổi hành vi: vẫn không lock Booking, vẫn không nhận/ghi `booking_requirement_id` (helper được gọi không truyền tham số thứ 3, mặc định `null`) — mọi call site cũ (30+ file test, 2 seeder QA, các service khác) tiếp tục hoạt động y hệt, không đổi 1 dòng.
- Không có method nào khác bị đổi sang gọi method mới — chỉ `RoomAssignmentController::store()` chuyển sang `assignRoomsWithRequirementLink()` (Mục 8).

## 5. Atomic Requirement Mapping

Method mới `assignRoomsWithRequirementLink(Booking $booking, array $assignments, array $targetRequirementIdByRoomType = []): array`:
- Sort `$assignments` theo `room_id` tăng dần (như cũ) trước khi vào `DB::transaction()`.
- Trong transaction: lock `Booking` → lock toàn bộ `Room` liên quan (sắp tăng dần) → lock toàn bộ `BookingRequirement` hợp lệ liên quan (sắp tăng dần) → **resolve mapping cho từng room_type** (Mục 6) → **chỉ sau khi TẤT CẢ room_type resolve thành công**, mới lặp tạo từng `RoomAssignment` qua `lockRoomRecheckAndCreateAssignment()` với `booking_requirement_id` đã resolve → `updateBookingAssignmentStatus()` 1 lần cuối.
- `booking_requirement_id` được ghi **đúng tại thời điểm `RoomAssignment::create()`** — không có transaction thứ hai, không có bước `update()` sau khi assignment đã tồn tại (xem Mục 7).

## 6. Requirement Resolution Rules

Đúng theo Mục VII của prompt, hiện thực trong `assignRoomsWithRequirementLink()`:

- **A — 1 dòng hợp lệ:** tự động chọn, không cần `target_requirement_id`.
- **B — Nhiều dòng hợp lệ:** bắt buộc `target_requirement_id[room_type_id]`; thiếu → `ValidationException` khoá `target_requirement_id.{room_type_id}`, không tạo assignment nào.
- **C — 0 dòng hợp lệ:** `ValidationException` với đúng thông báo "Loại phòng {code} chưa có dòng nhu cầu phù hợp. Hãy thêm nhu cầu phòng trước khi phân phòng." — không tự tạo demand, không tạo assignment NULL.
- **D — Validate target:** truy vấn `BookingRequirement::where('booking_id', $lockedBooking->id)->whereIn('room_type_id', ...)->where('quantity', '>', 0)->lockForUpdate()->get()` **encode đồng thời cả 4 điều kiện** (tồn tại + đúng booking + đúng room_type + `quantity > 0`) — target không nằm trong tập đã lock này (dù vì sai booking, sai room_type, không tồn tại, hay `quantity=0`) đều bị từ chối đồng nhất bằng 1 message rõ ràng, không đoán, không chỉ tin ID frontend gửi. `BookingRequirement` không có soft-delete nên không cần lọc riêng.
- **E — Nhiều room_type:** resolve độc lập từng room_type, **toàn bộ phải thành công trước khi tạo bất kỳ assignment nào** — 1 room_type lỗi làm rollback toàn bộ request (đã test, Mục 15 test #13).

## 7. Transaction and Locking

Đúng thứ tự Mục X: **`Booking` → `Room` (tất cả, id asc) → `BookingRequirement` (tất cả, id asc) → tạo `RoomAssignment`.** Room và BookingRequirement được lock cho **toàn bộ batch trước khi** bất kỳ quyết định resolve hay ghi nào xảy ra — cùng khuôn mẫu "lock hết rồi mới validate rồi mới ghi" đã có sẵn trong `BookingService::validateTimeChange()` (Step 5 → Step 6 trong code hiện tại), không phải khuôn mẫu mới. `lockRoomRecheckAndCreateAssignment()` vẫn tự lock lại từng `Room` khi tạo (idempotent — cùng transaction re-acquire lock đã giữ, không phải deadlock) để giữ đúng cam kết "1 helper duy nhất, không 2 phiên bản logic availability". Không có transaction thứ hai ở bất kỳ đâu.

## 8. Controller and Request Changes

- `RoomAssignmentController::store()`: route, authorization (`$this->authorize('create', RoomAssignment::class)`), response (redirect) **không đổi**. Chỉ đổi 1 lời gọi: `assignRooms()` → `assignRoomsWithRequirementLink()`, truyền thêm `$data['target_requirement_id'] ?? []`. Không endpoint song song.
- `StoreRoomAssignmentRequest`: thêm `target_requirement_id` (`sometimes|array`) và `target_requirement_id.*` (`integer|exists:booking_requirements,id`) — validate cấu trúc/tồn tại cơ bản (fail fast), **không** validate ownership/room_type-match/eligibility ở tầng này — việc đó bắt buộc recheck trong transaction (Mục 6), đúng yêu cầu "không dùng frontend-only guard".

## 9. Frontend Changes

`resources/js/Pages/Admin/Bookings/Show.vue` có 2 loại hunk khác nhau trong `git diff` — phân biệt rõ:

**Hunk có sẵn từ trước (KHÔNG thuộc Milestone 2, không đụng tới):**
- Đổi class `<div class="flex min-w-0 items-center...">` → responsive mobile ở phần header trang.
- Bọc `<div class="overflow-x-auto pb-72 sm:overflow-visible sm:pb-0">` quanh Sơ đồ phòng cho mobile (kèm comment giải thích gốc).

**Hunk mới thuộc Milestone 2 (đúng phạm vi Mục XII):**
- `assignmentForm` thêm field `target_requirement_id: {}`.
- Computed mới: `selectedRoomTypeIds`, `roomTypeChoiceGroups`, `groupsNeedingChoice`, `groupsWithNoRequirement`, `hasUnresolvedRequirementChoice`, `canSubmitAssignment` — mirror đúng quy tắc backend Mục 6 ở phía client (chỉ để UX, backend luôn là nguồn sự thật).
- `submitAssignment()`: thêm guard đầu hàm (không submit nếu `!canSubmitAssignment`), build `target_requirement_id` payload từ lựa chọn người dùng, reset state sau khi thành công.
- Nút "Lưu phân phòng": `:disabled` đổi từ `selectedRoomIds.length === 0` sang `!canSubmitAssignment` (bao gồm điều kiện cũ).
- 2 block UI mới, chèn giữa dòng lỗi và Sơ đồ phòng (Mục XII.B/C): (a) cảnh báo "chưa có dòng nhu cầu phù hợp" khi room_type không có demand; (b) panel tối thiểu (không phải panel giá đầy đủ M3) hiện `<select>` liệt kê từng dòng hợp lệ (số lượng, giá, nguồn giá, ghi chú) khi room_type có >1 dòng — ẩn hoàn toàn khi chỉ có 0 hoặc 1 dòng.

Không rewrite file, không format lại toàn file, không đụng bất kỳ dòng nào thuộc 2 hunk có sẵn.

## 10. Multiple Requirement Handling

Xem Mục 6 (B) + Mục 9. UI chỉ hiện panel chọn dòng khi thật sự có >1 dòng `quantity > 0` cho room_type đang chọn; nút submit bị khoá tới khi chọn đủ; backend luôn re-validate độc lập với UI.

## 11. No-Requirement Handling

Xem Mục 6 (C) + Mục 9. Không có bất kỳ code nào (frontend lẫn backend) tạo `BookingRequirement` mới trong Milestone 2 — đã xác nhận bằng test (Mục 15, test #11 và cleanup check MySQL thật, Mục 18).

## 12. Atomic Rollback

Toàn bộ resolve + tạo assignment nằm trong 1 `DB::transaction()`; bất kỳ `ValidationException` nào (room conflict, target sai, room_type thiếu demand) đều làm Laravel tự rollback toàn bộ — đã test trực tiếp bằng cách đếm `RoomAssignment` = 0 sau mỗi exception, kể cả khi 1 trong nhiều room_type đã resolve đúng (test #13, Mục 15).

## 13. Backward Compatibility

- `assignRooms()`: chữ ký, input/output, exception behavior **không đổi 1 chi tiết nào** — xác nhận bằng test trực tiếp (booking_requirement_id luôn NULL, không cần bất kỳ BookingRequirement nào tồn tại) chạy PASS trên cả SQLite test DB và MySQL dev thật (Mục 18).
- `releaseAssignment()`, `moveRoom()`, `StayService`, Folio, Night Audit, Service Package: **không đụng file nào** — xác nhận bằng `git status` (Mục 22).
- Stay creation: **giữ nguyên ngoài transaction**, đúng vị trí cũ trong controller — không âm thầm đưa vào transaction mới (đúng giới hạn Mục XIII).
- Authorization, route, FormRequest cũ (`room_id`/`room_ids`/`start_at`/`end_at`): không đổi.

## 14. Files Changed

**Sửa (4 file):**
- `app/Services/RoomAssignmentService.php`
- `app/Http/Controllers/Admin/Booking/RoomAssignmentController.php`
- `app/Http/Requests/Booking/StoreRoomAssignmentRequest.php`
- `resources/js/Pages/Admin/Bookings/Show.vue` (chỉ hunk M2 — xem Mục 9)

**Mới (1 file test):**
- `tests/Feature/RoomAssignmentAtomicMappingTest.php`

**Mới (1 file report — file này):**
- `docs/reports/room-demand-room-board-unification-milestone-2-report.md`

Không migration, không seeder, không file Vue/JS nào khác.

## 15. Tests

19 test mới trong `RoomAssignmentAtomicMappingTest.php`, **19/19 PASS** (46 assertions), nhóm đúng theo Mục XIV:

| Nhóm | Số test |
|---|---|
| A. Mapping cơ bản (#1-4) | 4 |
| B. Multiple requirement lines (#5-10) | 6 |
| C. No requirement (#11) | 1 |
| D. Atomicity (#12-15) | 4 |
| E. Regression — legacy `assignRooms()` (#16-17) | 1 |
| F. Frontend/Inertia — props/payload/validation qua HTTP (#26, #29, #30) | 3 |

Mục F items #27/#28/#31 (ẩn/hiện panel đúng lúc, mobile layout) là hành vi UI thuần túy — dự án này không có JS test framework (đã xác nhận `package.json` không có vitest/jest/@vue/test-utils/playwright, nhất quán với Milestone 1) — được xác nhận qua Manual QA (Mục 18), không tạo test giả cho hạ tầng chưa tồn tại.

Regression #18-25 (existing single/multi-room flow, booking status transition, availability/overlap checks, authorization, check-in, Stay creation) xác nhận qua targeted suite + full suite không có lỗi mới (Mục 17).

## 16. Build

`npm run build`: **PASS** — 2383 module, ~14s, không lỗi mới, chỉ cảnh báo chunk-size đã có từ trước.

## 17. Full Suite

Chạy tuần tự theo đúng Mục XV:

- **34/34 Milestone 1 test:** PASS (không regression).
- **19/19 Milestone 2 test:** PASS.
- **Targeted suite** (`RoomAssignment|BookingRequirement|RoomAvailability|BookingManagement|BookingEngineFoundation|CheckIn|Stay`): **358 passed, 24 failed** — đúng 24 lỗi pre-existing đã biết từ Milestone 1 (11 `BookingManagementUiTest` + 13 `RoomAvailabilityCheckerTest`, gốc rễ `RoomSeeder` seed cứng phòng 101 = `OutOfOrder`, sửa lần cuối 2026-07-01, không liên quan Milestone 2). Không có lỗi mới.
- **Full suite** (`php artisan test`, không filter): **1067 passed, 24 failed** (4392 assertions). So với baseline Milestone 1 (1047 passed, 25 failed): +19 (test M2 mới) +1 (test `LateCheckoutFeeTest::test_check_out_triggers_late_checkout_fee_via_stay_service` — trước đây fail vì phụ thuộc giờ chạy thật (< 12:00 trưa), lần này chạy lúc 16:09 nên PASS, đúng dự đoán đã ghi trong report Milestone 1) −1 lỗi khỏi danh sách fail = 1047+19+1=1067 passed, 25−1=24 failed. Khớp hoàn toàn, không có lỗi mới nào liên quan Milestone 2.

**Phát hiện bổ sung ở bước Commit Closure (chạy lại targeted suite trước khi commit, đúng Mục IX):** xuất hiện 1 lỗi **không nằm trong danh sách 24 lỗi đã biết** — `Tests\Feature\PerStayAttributionTest` — `UniqueConstraintViolationException` trên `resources.code` (ví dụ `RM-303`). Đã điều tra kỹ trước khi kết luận, không bỏ qua:
- Chạy lại đúng targeted suite lần 2: lỗi **vẫn xuất hiện nhưng ở 1 test method KHÁC** trong cùng file (`http store with valid stay id` lần 1 → `charge type room rejected from...` lần 2), cùng dạng `UniqueConstraintViolationException` cùng bảng `resources.code` — bằng chứng trực tiếp đây là **lỗi không xác định (flaky)**, không phải lỗi tất định do code M2 (nếu là lỗi logic thật, nó sẽ luôn fail đúng 1 chỗ cố định).
- Chạy `PerStayAttributionTest` **riêng lẻ**: **6/6 PASS** sạch, không lỗi.
- Nguyên nhân gốc: `database/factories/RoomFactory.php` dùng `fake()->unique()->numberBetween(100, 999)` cho `room_number` — pool chỉ có 900 giá trị, dùng chung cho TOÀN BỘ factory Room trong một tiến trình PHPUnit. Khi chạy chung nhiều file test (bao gồm 19 test mới của `RoomAssignmentAtomicMappingTest`, mỗi test tạo thêm vài `Room::factory()`), tổng số lần gọi factory Room trong 1 tiến trình tăng lên, làm tăng xác suất trùng số phòng ngẫu nhiên với 1 phòng đã tồn tại từ trước trong cùng tiến trình — va chạm unique constraint ở bảng `resources` (không phải `room_assignments`/`booking_requirements`, không liên quan trực tiếp logic Milestone 2).
- **Kết luận: đây là fragility có sẵn từ trước trong hạ tầng test (giới hạn pool `RoomFactory`, không phải lỗi M2), Milestone 2 chỉ vô tình làm tăng nhẹ xác suất xảy ra do thêm test mới tạo thêm Room factory.** Không phải "lỗi mới liên quan Milestone 2" theo đúng nghĩa lỗi logic — logic Milestone 2 (`RoomAssignmentAtomicMappingTest`, 19/19) chạy đúng và ổn định 100% ở cả 2 lần chạy lại. Không sửa `RoomFactory.php` trong task này (ngoài phạm vi Milestone 2, không được yêu cầu, rủi ro mở rộng phạm vi ngoài ý muốn) — ghi nhận như 1 khuyến nghị kỹ thuật riêng cho tương lai (mở rộng pool `numberBetween` hoặc dùng sequence thay vì random).

## 18. Manual QA

**Browser Manual QA: PASS.** Thực hiện trực tiếp trên trình duyệt thật, **Environment: Local/development**, không sử dụng dữ liệu/production nào. Đây là bằng chứng QA chính thức cho Milestone 2 — các case HTTP feature test và Tinker/MySQL ở dưới (Mục A/B) chỉ đóng vai trò **supplementary QA** (chạy trước đó trong cùng phiên, khi Chrome extension chưa kết nối được), không thay thế kết quả trình duyệt thật.

**Kết quả Browser Manual QA (theo đúng checklist Mục XVI):**

| # | Kịch bản | Kết quả |
|---|---|---|
| 1 | Room_type có đúng 1 requirement — không hiện lựa chọn thừa, phân phòng thành công, `booking_requirement_id` đúng | PASS |
| 2 | Room_type có nhiều requirement — UI yêu cầu chọn dòng, hiển thị đúng giá/nguồn giá/ghi chú/số lượng, không chọn thì bị chặn, chọn target đúng thì thành công, map đúng target | PASS |
| 3 | Room_type không có requirement — hiển thị cảnh báo rõ, không tự tạo demand, không tạo assignment | PASS |
| 4 | Chọn nhiều room_type cùng lúc — mapping riêng theo từng loại, không dùng nhầm target giữa các nhóm | PASS |
| 5 | Existing multi-select — chọn/bỏ chọn phòng, tổng số phòng chọn đúng, batch assignment hoạt động | PASS |
| 6 | Responsive/mobile — panel không tràn màn hình, select và nút xác nhận thao tác được, không hỏng layout Booking Show | PASS |

**A. HTTP feature test chạy full stack (supplementary)** — 3 test trong nhóm F (Mục 15) gọi đúng endpoint `POST /admin/bookings/{id}/assignments` thật (SQLite test DB), xác nhận props Inertia, validation error key, và assignment tạo đúng qua HTTP.

**B. Xác minh qua Tinker trên MySQL dev thật (supplementary)** — tạo 4 booking QA rõ ràng (`QA Single Line`/`QA Multi Line`/`QA No Requirement`/`QA Legacy`, id 448-451) + 1 user QA (`m2-qa@example.test`, id 336), gọi thẳng `assignRoomsWithRequirementLink()`/`assignRooms()`, cả 5 kịch bản PASS (chi tiết xem lịch sử report trước khi Browser Manual QA hoàn tất).

**Dữ liệu QA (id booking 448-451, user id 336): GIỮ LẠI, không xoá** — xem Mục 19 để biết lý do kỹ thuật.

**Không dùng dữ liệu production, không chạy backfill `--apply`, không migration.**

## 19. Known Limitations

- `addRequirement()` (luồng demand-first tạo nhu cầu) vẫn không đụng gì — nhiều dòng cùng room_type vẫn được tạo tự do như trước, đúng thiết kế hiện tại, không phải lỗi M2.
- Panel chọn dòng nhu cầu là tối thiểu (số lượng/giá/nguồn giá/ghi chú/select) — không có sửa giá, không guest fields, không tạo dòng mới — đúng giới hạn M2, đầy đủ hơn thuộc Milestone 3.
- "Số đã phân" hiển thị trong panel là tổng theo room_type (từ `assignmentSummary` có sẵn), **không phải** theo từng dòng riêng lẻ — dữ liệu per-line assigned count chưa tồn tại trong hệ thống trước Milestone 3.
- **4 booking QA (id 448-451) và 1 user QA (`m2-qa@example.test`, id 336) vẫn còn trên DB dev local — quyết định GIỮ LẠI, không xoá.** Lý do kỹ thuật: đã thử xoá thật (bọc trong transaction, rollback ngay sau khi xác nhận) — `$booking->delete()` cho booking 448 bị chặn bởi chính constraint `restrictOnDelete()` mà Milestone 1 thêm vào (`room_assignments_booking_requirement_id_foreign`), vì cascade delete của Laravel/MySQL cố xoá `booking_requirements` trong khi `room_assignments` (cùng đang cascade xoá theo `booking_id`) vẫn còn tham chiếu tới nó tại thời điểm kiểm tra ràng buộc. Xoá "sạch" đòi hỏi tự tay xoá `room_assignments` liên quan TRƯỚC khi xoá `booking` (đúng thứ tự thủ công) — vượt quá 1 lệnh xoá đơn giản và bắt đầu giống "code cleanup" (bị cấm theo yêu cầu). Vì không chắc chắn tuyệt đối việc xoá nhiều bước thủ công đó không có tác dụng phụ, đã chọn phương án an toàn hơn: giữ nguyên dữ liệu. Dữ liệu hoàn toàn vô hại (đặt tên rõ ràng "QA ...", định danh email `qa-*@example.test`/`m2-qa@example.test`, không có payment/folio charge nào), không ảnh hưởng vận hành hay dữ liệu thật khác. Có thể dọn thủ công sau này nếu cần, theo đúng thứ tự: xoá `room_assignments` của 4 booking này → xoá `booking_requirements` → xoá `bookings` → xoá user QA.

## 20. Deferred to Milestone 3

Room-Board-first reverse synchronization, tạo `BookingRequirement` mới từ Room Board, thuật toán Cấp A/B đầy đủ (`excess_to_add` ghi vào DB), panel giá đầy đủ, guest fields UI, Stay atomic flow tuyệt đối cho luồng mới.

## 21. Deferred to Milestone 4

`release_batches` table + model, `release_batch_id`, `releaseAssignments()` bulk, route/controller/request bulk release, UI multi-select release, reduce-demand all-or-nothing logic.

## 22. Deployment Restrictions

- **Không deploy production dựa trên Milestone 2** — vẫn chưa có Room-Board-first, chưa có bulk release.
- **Không chạy backfill `--apply`** trong task này (chỉ dry-run đã có từ Milestone 1).
- **Không migration** — Milestone 2 không thêm cột/bảng nào.
- Xác nhận qua `git status --short` (Mục 24 dưới): không có diff nào trong `app/Services/StayService.php`, `app/Services/RoomAssignmentService.php::releaseAssignment/moveRoom` (không sửa), `database/migrations`, `database/seeders`, Folio, Night Audit, Service Package, `.env`, `public/build`, `storage/logs`, `storage/backups`.

## 23. Readiness

**MILESTONE 2 COMPLETE — APPROVED FOR COMMIT**

ChatGPT đã review kết quả triển khai và Manual QA: **APPROVED FOR MILESTONE 2 COMMIT**. Browser Manual QA thật (Environment: Local/development) đã PASS toàn bộ 6 nhóm kịch bản (Mục 18). Milestone 2: COMPLETE. Milestone 3: NOT STARTED. Chưa deploy production.

**Toàn bộ feature (Room Demand and Room Board Unification): NOT READY FOR PRODUCTION** — mới hoàn thành 2/5 milestone, chưa có Room-Board-first, chưa có bulk release.

Không ghi READY FOR PRODUCTION cho riêng Milestone 2.
