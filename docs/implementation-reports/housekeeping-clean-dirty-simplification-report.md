# Housekeeping Clean/Dirty Simplification — Implementation Report

- Repository: LASTELLA PMS
- Branch: `phase-3`
- Vai trò: Claude Code = người triển khai; ChatGPT = Product Architect / Reviewer
- Nguyên tắc: Minimal Change, Regression Safety — không phá vỡ booking, checkout, folio, room availability, checkout inspection

---

## 1. Tóm tắt thay đổi

Đơn giản hóa toàn bộ luồng Dọn phòng (Housekeeping) trên UI xuống còn đúng 2 trạng thái vệ sinh — **SẠCH / BẨN** — thao tác một chạm (`markClean` / `markDirty`), tách bạch rõ khỏi trạng thái vận hành (Trống / Có khách / Bảo trì / Ngừng sử dụng). Checkout tự động đánh dấu phòng BẨN, không còn tạo `HousekeepingAssignment` bắt buộc. Luồng nhiều bước cũ (Chờ dọn → Đang dọn → Đã xong → Đạt/Không đạt/Bỏ qua) **không bị xóa** — toàn bộ backend/route/test giữ nguyên và vẫn hoạt động, chỉ được dời khỏi card chính vào mục "Nâng cao" trong Popup Chi tiết.

## 2. Workflow cũ

- Card dọn phòng hiển thị nhiều nút hành động tùy trạng thái: Chờ dọn, Đang dọn, Đã xong, Đạt, Không đạt, Bỏ qua, Khóa bảo trì, Mở khóa — mỗi nút mở `HousekeepingActionDialog` (popup xác nhận + form).
- `Room.status` (enum `RoomStatus`) là **nguồn duy nhất** vừa biểu diễn trạng thái vận hành vừa biểu diễn trạng thái vệ sinh: `VACANT_CLEAN`, `VACANT_DIRTY`, `CLEANING`, `INSPECTED`, `OCCUPIED`, `RESERVED`, `OUT_OF_ORDER`, `OUT_OF_SERVICE`.
- Checkout (`StayService::checkOut()` → `HousekeepingService::autoMarkDirtyOnCheckout()`) tự động set `status = VACANT_DIRTY` **và** tạo một `HousekeepingAssignment` `Pending` bắt buộc.
- Dọn phòng trong kỳ lưu trú (khách còn ở, `OCCUPIED`) không có đường đi hợp lệ: `assignRoom()` chỉ chấp nhận phòng đang `VACANT_DIRTY`.

## 3. Workflow mới

- **Lớp A — Trạng thái vận hành** (`RoomStatus`, không đổi enum): Trống / Có khách / Đã đặt / Bảo trì / Ngừng sử dụng — hiển thị badge phụ trên card (`operational_status_label`, method mới `RoomStatus::operationalLabel()`).
- **Lớp B — Trạng thái vệ sinh** (`CleaningStatus`, enum mới): `CLEAN` / `DIRTY` — badge chính trên card.
- Nút hành động chính trên card: đúng 1 nút — **"ĐÁNH DẤU SẠCH"** (nổi bật, khi BẨN) hoặc **"Đánh dấu bẩn"** (phụ, khi SẠCH). Gửi request ngay, không popup xác nhận, disable trong lúc xử lý (chống double-submit), toast ngắn 3s khi xong, giữ nguyên trạng thái + thông báo lỗi rõ khi thất bại/mất mạng.
- Checkout: `autoMarkDirtyOnCheckout()` chỉ còn `status = VACANT_DIRTY` + `cleaning_status = DIRTY`, **không** tạo `HousekeepingAssignment` nữa.
- Phòng `OCCUPIED`: `markClean()`/`markDirty()` **chỉ đổi `cleaning_status`**, không đụng `status` (không ảnh hưởng stay/booking) — cho phép dọn theo yêu cầu khách trong kỳ lưu trú, đúng mục II.5.
- Phòng bảo trì (`OUT_OF_ORDER`/`OUT_OF_SERVICE`): backend re-check và từ chối (`ValidationException`) bất kể frontend gửi gì.
- Bulk action trên board rút gọn còn đúng 2 nút: Đánh dấu sạch / Đánh dấu bẩn, xử lý per-room (`RunsPerRoomBulkAction`), một phòng lỗi không ảnh hưởng phòng khác.
- Luồng nhiều bước cũ vẫn còn nguyên vẹn, di chuyển vào mục **"Nâng cao"** trong Popup Chi tiết (Chờ dọn/Đang dọn/Đã xong/Đạt/Không đạt/Bỏ qua/Khóa bảo trì/Mở khóa), gọi qua `HousekeepingActionDialog` đã có sẵn.

## 4. Mapping trạng thái cũ → Sạch/Bẩn

Migration backfill một lần (không sửa dữ liệu lịch sử khác, không xóa cột `status`):

| `RoomStatus` cũ | `cleaning_status` mới |
|---|---|
| `VACANT_DIRTY`, `CLEANING` | `DIRTY` |
| `VACANT_CLEAN`, `INSPECTED`, `OCCUPIED`, `RESERVED`, `OUT_OF_ORDER`, `OUT_OF_SERVICE` | `CLEAN` |

`Room::normalizedCleaningStatus()` còn có fallback runtime (map lại từ `status` nếu `cleaning_status` null) để phòng hờ dữ liệu thiếu, không phụ thuộc tuyệt đối vào migration.

## 5. File tạo mới

- `app/Enums/CleaningStatus.php`
- `database/migrations/2026_07_28_000000_add_cleaning_status_to_rooms_table.php`
- `app/Http/Requests/Housekeeping/MarkCleanRequest.php`
- `app/Http/Requests/Housekeeping/MarkDirtyRequest.php`
- `app/Http/Requests/Admin/BulkMarkCleaningRequest.php`

## 6. File sửa

Backend: `app/Enums/RoomStatus.php` (+`operationalLabel()`), `app/Enums/CleaningReason.php` (+case `MANUAL`), `app/Models/Room.php` (+`cleaning_status` cast/fillable, +`normalizedCleaningStatus()`/`isRoomClean()`/`isRoomDirty()`), `app/Services/HousekeepingService.php` (+`markClean`/`markDirty`, sửa `autoMarkDirtyOnCheckout`), `app/Policies/HousekeepingPolicy.php` (+`markCleaning`), `app/Http/Controllers/Admin/HousekeepingController.php`, `app/Http/Controllers/Admin/HousekeepingBulkActionController.php`, `database/seeders/RolePermissionSeeder.php`, `routes/web.php`.

Frontend: `resources/js/Pages/Admin/Housekeeping/Index.vue` (viết lại card + bulk bar), `resources/js/Pages/Admin/Housekeeping/Partials/HousekeepingDetailModal.vue` (+mục Nâng cao, +cleaning_status), `resources/js/Support/roomStatusBadges.js` (+`cleaningStatusBadge()`), `resources/js/Support/vietnameseLabels.js` (+`cleaningStatusLabels`, +label `MANUAL`).

Test: `tests/Unit/Services/HousekeepingServiceTest.php`, `tests/Feature/HousekeepingControllerTest.php`, `tests/Feature/HousekeepingBulkActionTest.php`, `tests/Unit/Policies/HousekeepingPolicyTest.php`, `tests/Unit/Seeders/RolePermissionSeederTest.php`, `tests/Feature/StayServiceHousekeepingHookTest.php`, `tests/Feature/HousekeepingWorkflowIntegrationTest.php`, `tests/Unit/Services/StayServiceMoveRoomTest.php`.

## 7. Backend service

`HousekeepingService::markClean(Room, User, ?notes)` / `markDirty(Room, User, ?notes)`:
- Row-lock (`lockForUpdate`), re-check trạng thái bảo trì (`guardAgainstMaintenance()`), không dựa vào frontend.
- Phòng `OCCUPIED` → chỉ đổi `cleaning_status`; các trạng thái khác → đổi cả `status` (collapse `VACANT_DIRTY`/`CLEANING`/`INSPECTED` → `VACANT_CLEAN`, hoặc ngược lại → `VACANT_DIRTY`) và `cleaning_status`.
- Ghi `CleaningRecord` (audit: `cleaned_by`, `room_status_before/after`, `reason = MANUAL`, `cleaning_notes`).
- Không tạo `HousekeepingAssignment`, không đổi `Stay`/`Booking`, không tạo folio entry.
- `autoMarkDirtyOnCheckout()` (hook không throw, gọi từ `StayService::checkOut()` và `moveRoom()`) rút gọn: chỉ update `status`/`cleaning_status`, bỏ đoạn tạo `HousekeepingAssignment`.

## 8. Route/API

Đơn phòng: `PATCH admin/housekeeping/{room}/mark-clean`, `PATCH admin/housekeeping/{room}/mark-dirty`.
Bulk: `PATCH admin/housekeeping/bulk/mark-clean`, `PATCH admin/housekeeping/bulk/mark-dirty`.

Toàn bộ route cũ (`assign`, `start`, `complete`, `inspect.*`, `out-of-order`, `release`, `bulk.assign/start/complete`) giữ nguyên, không đổi.

**Lưu ý kỹ thuật quan trọng:** route `bulk/...` phải khai báo **trước** route `{room}/...` cùng method PATCH trong cùng group — nếu không, `PATCH bulk/mark-clean` sẽ khớp nhầm vào pattern `{room}/mark-clean` với `{room}="bulk"`, gây 404 do route-model-binding không tìm thấy Room. Lỗi này được test bulk phát hiện và đã sửa (`routes/web.php`).

## 9. UI desktop

Card: số phòng, badge SẠCH/BẨN (nổi bật) + badge phụ trạng thái vận hành, khách/ngày đến-trả, yêu cầu đặc biệt, đúng 1 nút hành động chính (SẠCH khi bẩn / BẨN khi sạch, disable + "Đang xử lý..." khi request đang chạy) + nút "Chi tiết". Toast góc dưới màn hình, tự ẩn sau 3s, không chặn thao tác tiếp theo.

## 10. UI mobile

Không thay đổi lưới responsive của `RoomBoardGrid.vue` (đã có sẵn `grid-cols-2 sm:grid-cols-3 md:...`, không đụng). Nút hành động trong card dùng `min-h-11` (44px) theo đúng convention hiện có của dự án, xếp dọc (`flex-col`) tối đa 2 nút/card — không popup xác nhận cho thao tác sạch/bẩn thường xuyên, Chi tiết mở modal full-width dạng bottom-sheet trên mobile (kế thừa style modal có sẵn).

## 11. Bulk action

`bulkMarkClean`/`bulkMarkDirty` tái sử dụng `RunsPerRoomBulkAction` — mỗi phòng gọi `HousekeepingService::markClean/markDirty` độc lập, trả `succeeded`/`failed` per-room kèm lý do; phòng bảo trì bị từ chối độc lập không ảnh hưởng phòng khác trong cùng request. Giữ nguyên "Chọn nhanh" (Tất cả phòng đủ điều kiện / theo tầng) — không đổi logic `is_eligible_for_bulk`.

## 12. Checkout integration

`StayService::checkOut()` không đổi transaction/guard — vẫn gọi `autoMarkDirtyOnCheckout()` (đã sửa nội dung hook, không sửa điểm gọi). Phòng chuyển `VACANT_DIRTY` + `cleaning_status = DIRTY` ngay sau checkout, không có bước trung gian bắt buộc, không tạo assignment.

## 13. Partial checkout

Không sửa `checkOutMany()`/logic chọn từng stay trong booking nhiều phòng — mỗi `checkOut()` độc lập vẫn chỉ tác động đúng phòng của `Stay` đó qua `autoMarkDirtyOnCheckout($lockedStay)`. Có test regression riêng cho move-room (đổi phòng cũng dùng chung hook này khi trả phòng cũ) xác nhận không tạo assignment và không ảnh hưởng phòng khác.

## 14. Phân quyền

Permission mới `room.cleaning.update` (Spatie), **tách biệt** khỏi `room.status.update` (ability cũ vẫn gate toàn bộ luồng nhiều bước) — để RECEPTION/HOUSEKEEPING có quyền một chạm mà không mở rộng quyền vào luồng cũ. Policy method mới `HousekeepingPolicy::markCleaning()`.

| Role | markCleaning (Sạch/Bẩn) | assign/updateStatus (luồng cũ) | inspect | maintenance |
|---|---|---|---|---|
| ADMIN | ✓ | ✓ | ✓ | ✓ |
| MANAGER | ✓ | ✓ | ✓ | ✓ |
| RECEPTION | ✓ (mới) | ✗ | ✗ | ✗ |
| HOUSEKEEPING | ✓ (mới) | ✓ | ✗ | ✗ |
| SALES / ACCOUNTANT | ✗ | ✗ | ✗ | ✗ |

`RolePermissionSeeder` đã re-seed trên DB dev cục bộ (`php artisan db:seed --class=RolePermissionSeeder`) — **môi trường khác (staging/Pilot) cần re-run seeder này để nhận quyền mới**, seeder vẫn idempotent (verified bằng test `test_seeder_is_idempotent`/`test_seeder_does_not_create_duplicate_pivot_rows`).

## 15. Audit/history

Tái sử dụng `CleaningRecord` (bảng đã có) làm audit trail cho `markClean`/`markDirty` — ghi người thao tác (`cleaned_by`), trạng thái trước/sau, ghi chú tùy chọn, `reason = MANUAL` (case enum mới, không đổi case cũ). Popup Chi tiết hiển thị lịch sử này kèm `reason_label`.

## 16. Test mới

29 test case mới, bao gồm (không liệt kê hết):
- `HousekeepingServiceTest`: DIRTY→CLEAN, CLEAN→DIRTY, phòng OCCUPIED chỉ đổi cleaning_status, từ chối phòng bảo trì (2 case), không tạo assignment.
- `HousekeepingControllerTest`: mark-clean/mark-dirty success (Reception), forbidden (Accountant), reject phòng OUT_OF_SERVICE, không đổi status phòng OCCUPIED, `can.markCleaning` đúng theo role.
- `HousekeepingBulkActionTest`: bulk mark-clean thành công/thất bại độc lập với phòng bảo trì, bulk mark-dirty cho Reception, accountant bị 403.
- `HousekeepingPolicyTest`: `markCleaning` cho cả 6 role.
- `RolePermissionSeederTest`: `room.cleaning.update` đúng cho 4 role được cấp, không cấp cho SALES/ACCOUNTANT, cập nhật `assertCount` idempotency (6→7 permission cho HOUSEKEEPING).

## 17. Test regression

- `StayServiceHousekeepingHookTest`: checkout đánh dấu BẨN, **không** còn tạo assignment (đổi tên/nội dung test).
- `HousekeepingWorkflowIntegrationTest`: full lifecycle check-in→checkout→(assign thủ công qua "Nâng cao")→start→complete→pass-inspection — xác nhận luồng cũ vẫn chạy trọn vẹn end-to-end sau khi bỏ auto-assign.
- `StayServiceMoveRoomTest`: đổi phòng vẫn set phòng cũ `VACANT_DIRTY`, không còn tạo assignment.
- Đã chạy **toàn bộ test suite của repo** (`php artisan test`, 956 test): **932 passed, 24 failed**.
- Đã điều tra từng lỗi trong 24 lỗi đó bằng `git stash` (chạy lại đúng test đó trên code baseline trước khi có thay đổi này):
  - `RoomAvailabilityCheckerTest` (12 lỗi) — **giống hệt kết quả trên baseline** (lỗi có sẵn, liên quan đến ngày giờ hardcode trong test so với ngày hệ thống hiện tại 2026-07-27; đã có ghi chú "hardcoded-date drift" từ trước trong `HousekeepingAvailabilityIntegrationTest`).
  - `BookingManagementUiTest` (11 lỗi) — **giống hệt kết quả trên baseline**, cùng nguyên nhân date-drift.
  - `PerStayAttributionTest > add charge accepts system auto posting source` (1 lỗi, `UniqueConstraintViolationException`) — **pass sạch khi chạy riêng lẻ** (`--filter=PerStayAttributionTest`, 6/6 pass); đây là flaky test phụ thuộc thứ tự chạy trong suite lớn (khả năng cao là Faker sinh trùng giá trị unique khi chạy chung hàng nghìn factory), không đụng đến module Housekeeping.
- **Kết luận: cả 24 lỗi đều không do thay đổi trong patch này gây ra** — không có test nào trong Booking/Checkout/Folio/Check-in/Room Availability bị regression thật sự bởi tính năng Sạch/Bẩn.

## 18. Build result

- `npm run build`: **PASS** (2377 modules, không lỗi; cảnh báo kích thước chunk >500kB đã tồn tại từ trước, không phát sinh mới).
- `php -l` toàn bộ file PHP mới/sửa: **PASS**, không lỗi cú pháp.
- `php artisan migrate --pretend` + chạy thật trên DB dev: **PASS**, additive-only, backfill đúng như thiết kế.

## 19. UI QA

Đã QA trực tiếp qua trình duyệt (tài khoản RECEPTION tạm thời, chỉ tồn tại trong DB dev cục bộ, đã soft-delete sau khi test — không commit, không ảnh hưởng git):
- Card hiển thị đúng badge SẠCH/BẨN tách biệt badge vận hành (Trống/Có khách/Ngừng sử dụng).
- One-tap "Đánh dấu bẩn" → "Đang xử lý..." (disable) → BẨN + nút "ĐÁNH DẤU SẠCH" nổi bật → bấm lại → về SẠCH. Round-trip xác nhận đúng.
- Phòng bảo trì (101, `OUT_OF_ORDER`) không có nút sạch/bẩn, chỉ có Chi tiết — đúng yêu cầu.
- Popup Chi tiết: Reception (không có quyền luồng cũ) không thấy mục "Nâng cao" — đúng permission gating; hiển thị đúng "Trạng thái vận hành" / "Sạch/Bẩn" tách riêng + lịch sử.
- Bulk: chọn 1 phòng → bar hiện "Đánh dấu sạch"/"Đánh dấu bẩn" → bấm → "Thành công 1 phòng." → card cập nhật.
- Chưa QA trực tiếp bằng thiết bị mobile thật (viewport resize qua tool không phản ánh chính xác trong môi trường này) — CSS kế thừa nguyên vẹn convention `min-h-11`/grid responsive đã có sẵn của dự án, không có class mới rủi ro.

## 20. Known limitations

- HOUSEKEEPING role được cấp `room.cleaning.update` nhưng **vẫn giữ** `room.status.update` (đã có từ trước) nên vẫn truy cập được luồng cũ qua "Nâng cao" — đúng ý đồ (không mất năng lực).
- RECEPTION được cấp `room.cleaning.update` **mới**, không đụng `room.status.update`/`housekeeping.assign` — Reception không thấy mục "Nâng cao" trong Popup, đúng yêu cầu VI.
- `RoomStatus::Cleaning`/`Inspected` vẫn còn trong enum (không xóa) nhưng từ nay không còn được luồng chính tạo ra nữa — chỉ còn khả năng xuất hiện qua luồng "Nâng cao" (start/complete) nếu người có quyền chủ động dùng.
- CleaningRecord ghi nhận markDirty với `reason = MANUAL` — không có ngữ cảnh chi tiết hơn (vd. "khách yêu cầu") trừ khi người dùng tự nhập vào ô ghi chú tùy chọn.
- Toast tự ẩn sau 3 giây, không có lịch sử toast — nếu người dùng thao tác nhiều phòng liên tiếp nhanh, toast trước có thể bị toast sau che một phần trên màn hình rất nhỏ (chưa kiểm thử thiết bị thật).

## 21. Rủi ro còn lại

- `room.cleaning.update` cần được **re-seed thủ công** trên mọi môi trường khác ngoài DB dev cục bộ này trước khi Reception/Housekeeping có thể dùng nút một chạm ở đó (xem mục 14).
- Migration `2026_07_28_000000_add_cleaning_status_to_rooms_table` đã chạy trên DB dev cục bộ (không phải Pilot) — cần chạy lại (`php artisan migrate`) trên bất kỳ database nào khác trước khi deploy tính năng này.
- Chưa có kiểm thử tự động (Playwright) cho toast/one-tap trên trình duyệt thật — QA hiện tại là thủ công qua Claude-in-Chrome.

## 22. Trạng thái (trước Final Consistency Review)

**READY FOR REVIEW**

- Build: PASS. Test suite toàn bộ: 932 passed / 24 failed — cả 24 lỗi đã xác minh là pre-existing/flaky, không liên quan đến patch này (chi tiết mục 17).
- Tất cả tiêu chí ở mục XVII của yêu cầu đã đạt: UI chỉ còn SẠCH/BẨN, one-tap không popup xác nhận, checkout tự làm bẩn, bulk hoạt động, backend re-check trạng thái, audit/history còn nguyên, không ảnh hưởng booking/stay, mobile dùng đúng convention 44px có sẵn.

---

## 23. Final Consistency Review

### I. Kết quả rà soát nguồn trạng thái

Rà soát toàn bộ codebase (grep `RoomStatus::` + `->status` + `cleaning_status` trên `app/`) cho mọi nơi có thể ghi `Room.status`/`cleaning_status`. Danh sách đầy đủ các điểm ghi và kết luận:

| # | Nơi ghi | Ghi `status` | Ghi `cleaning_status` (trước review) | Kết luận |
|---|---|---|---|---|
| 1 | `HousekeepingService::markClean/markDirty` | ✓ | ✓ | Đã đúng từ đầu (nguồn one-tap mới) |
| 2 | `HousekeepingService::assignRoom` | ✗ | ✗ | Đúng theo thiết kế — assign không đổi cleanliness |
| 3 | `HousekeepingService::startCleaning` | ✓ (→CLEANING) | ✗ **(lỗi)** | **Đã sửa** — nay ghi DIRTY tường minh |
| 4 | `HousekeepingService::completeCleaning` | ✓ (→INSPECTED) | ✗ **(lỗi)** | **Đã sửa** — nay ghi CLEAN tường minh (quyết định nghiệp vụ, xem mục IV) |
| 5 | `HousekeepingService::passInspection` | ✓ (→VACANT_CLEAN) | ✗ **(lỗi)** | **Đã sửa** — nay ghi CLEAN tường minh |
| 6 | `HousekeepingService::failInspection` | ✓ (→VACANT_DIRTY) | ✗ **(lỗi)** | **Đã sửa** — nay ghi DIRTY tường minh |
| 7 | `HousekeepingService::skipInspection` | ✓ (→VACANT_CLEAN) | ✗ **(lỗi)** | **Đã sửa** — nay ghi CLEAN tường minh |
| 8 | `HousekeepingService::markOutOfOrder` | ✓ (→OUT_OF_ORDER) | ✗ (chủ ý) | Giữ nguyên chủ ý — không ghi đè cleanliness trước đó, đã bổ sung comment giải thích |
| 9 | `HousekeepingService::releaseFromOutOfOrder` | ✓ (→target) | ✗ **(lỗi)** | **Đã sửa** — nay sync theo `target_status->impliedCleaningStatus()` |
| 10 | `HousekeepingService::autoMarkOccupied` | ✓ (→OCCUPIED) | ✗ (chủ ý) | Đúng theo thiết kế — check-in không tự khai báo sạch/bẩn |
| 11 | `HousekeepingService::autoMarkDirtyOnCheckout` | ✓ (→VACANT_DIRTY) | ✓ | Đã đúng từ đầu |
| 12 | `RoomService::create/update` (CRUD `/rooms`) | ✓ (bất kỳ giá trị) | ✗ **(lỗi nghiêm trọng nhất)** | **Đã sửa** — thêm `syncCleaningStatusForVacantCluster()` |
| 13 | `RoomSeeder`, `LastellaQaSeeder(V2)`, `RoomFactory` | ✓ (raw `->update()`/factory default) | ✗ (không cần) | Không sửa — `cleaning_status` để `null`, fallback `normalizedCleaningStatus()` tự suy ra đúng tại thời điểm đọc |
| 14 | `RoomBulkActionController` | — (gọi qua #8, #9) | — | Không cần sửa riêng, đã theo #8/#9 |
| 15 | `HousekeepingBulkActionController` | — (gọi qua #1, #3, #4) | — | Không cần sửa riêng |

**2 lỗi thực sự được tìm thấy và đã sửa:**
- **#12 (nghiêm trọng nhất):** Form CRUD `/rooms` (Admin quản lý phòng) cho phép đổi `status` sang bất kỳ giá trị nào mà hoàn toàn không đụng `cleaning_status` — admin có thể set `status=VACANT_DIRTY` qua form này trong khi `cleaning_status` cũ vẫn còn `CLEAN`, gây lệch trực tiếp đúng kịch bản ví dụ nêu trong yêu cầu.
- **#3–#7, #9 (luồng "Nâng cao"):** toàn bộ pipeline cũ (start/complete/pass/fail/skip/release) chưa từng ghi `cleaning_status` — nếu một phòng đi qua `markDirty()` (DIRTY) rồi sau đó ai đó dùng "Nâng cao" → `passInspection()` (status→VACANT_CLEAN), cột `cleaning_status` persisted vẫn giữ nguyên `DIRTY` cũ, và vì `normalizedCleaningStatus()` ưu tiên giá trị persisted khi non-null, badge sẽ hiển thị sai (**BẨN** trong khi phòng đã được xác nhận sạch qua kiểm tra).

### II. Source of truth — chốt nguyên tắc

- **`cleaning_status` là source of truth duy nhất cho SẠCH/BẨN.** `Room.status` (`RoomStatus`) chỉ còn ý nghĩa vận hành/khả dụng + tương thích lịch sử (legacy VACANT_CLEAN/VACANT_DIRTY/CLEANING/INSPECTED vẫn tồn tại cho các luồng cũ, nhưng UI/badge SẠCH-BẨN không bao giờ đọc trực tiếp từ `status`).
- **Helper trung tâm duy nhất:** `RoomStatus::impliedCleaningStatus(): CleaningStatus` (trong `app/Enums/RoomStatus.php`) — map tường minh cho **cả 8 case**, không có `default` wildcard (để một case mới trong tương lai không bao giờ "lọt lưới" âm thầm; có test `test_every_room_status_case_has_an_explicit_mapping` khóa việc này).
  - Dùng bởi `Room::normalizedCleaningStatus()` (fallback đọc khi `cleaning_status` null).
  - Dùng bởi `RoomService::syncCleaningStatusForVacantCluster()` (đồng bộ khi admin đổi status qua CRUD).
  - Dùng bởi `HousekeepingService::releaseFromOutOfOrder()` (đồng bộ theo `target_status`).
  - Migration backfill dùng **giá trị literal giống hệt** (không gọi thẳng vào enum, đúng convention "migration tự chứa" của Laravel) nhưng mapping phải khớp 100% với enum — đã đối chiếu thủ công.
- **Helper ghi tập trung:** `HousekeepingService::applyCleaningState()` (private) — điểm ghi DUY NHẤT phía sau `markClean()`/`markDirty()`, khóa row, re-check bảo trì, ghi cả `status`+`cleaning_status`+audit trong một transaction. Không còn 2 khối code trùng lặp như bản trước review.
- **Frontend không suy luận:** `HousekeepingController::index()`/`show()` trả `cleaning_status`/`cleaning_status_label` tường minh; `Index.vue`/`HousekeepingDetailModal.vue` đọc thẳng field này, không có bất kỳ biểu thức nào tự map từ `room.status` sang sạch/bẩn (đã audit lại toàn bộ `resources/js/Pages/Admin/Housekeeping/`). Có test `test_index_cleaning_status_is_decoupled_from_operational_status_label` khóa hành vi này ở tầng backend (props).

### III. Migration/backfill — quyết định cuối

Migration `2026_07_28_000000_add_cleaning_status_to_rooms_table.php` đã sửa lại (migration này **chưa từng commit/deploy**, xác nhận local-only nên sửa trực tiếp thay vì viết migration hiệu chỉnh chồng lên — đã rollback + re-migrate trên DB dev để áp dụng):

| RoomStatus | Trước review | Sau review | Lý do |
|---|---|---|---|
| VACANT_DIRTY | DIRTY | DIRTY | Không đổi — rõ ràng |
| CLEANING | DIRTY | DIRTY | Không đổi — rõ ràng |
| VACANT_CLEAN | CLEAN | CLEAN | Không đổi — rõ ràng |
| INSPECTED | CLEAN | CLEAN | Không đổi — đã dọn xong, chỉ chờ QC |
| OCCUPIED | CLEAN | CLEAN | Giữ nguyên — không có tín hiệu ngược; check-in trong hệ thống này luôn ngầm định phòng đã sạch trước đó, đây là baseline thực tế vận hành, không phải default tùy tiện |
| RESERVED | CLEAN | CLEAN | Giữ nguyên — xác nhận qua audit: **không có bất kỳ write-path nào trong code hiện tại từng gán `status=RESERVED`** (trạng thái "đã đặt" được suy ra từ `RoomAssignment`, không từ `Room.status`) — mapping này không ảnh hưởng dữ liệu thật |
| **OUT_OF_ORDER** | ~~CLEAN~~ | **DIRTY** | **Đã đổi** — không được mặc định phòng khóa bảo trì là sạch chỉ vì đang khóa; an toàn hơn là buộc người mở khóa phải xác nhận sạch |
| **OUT_OF_SERVICE** | ~~CLEAN~~ | **DIRTY** | **Đã đổi**, lý do như trên |

Đã kiểm tra không có rủi ro nào từ việc đổi OUT_OF_ORDER/OUT_OF_SERVICE sang DIRTY: cả `is_maintenance` badge, `is_eligible_for_bulk`, và `RoomAvailabilityRuleService::isRoomUnavailable()` đều dựa trên `status`, không dựa trên `cleaning_status` — đổi giá trị backfill này không ảnh hưởng gì khác ngoài field `cleaning_status`/`cleaning_status_label` hiển thị trong Popup Chi tiết.

### IV. Đồng bộ luồng "Nâng cao"

| Hành động | `cleaning_status` sau khi sửa | Quyết định |
|---|---|---|
| `assign` | không đổi | Đúng yêu cầu — assign là workflow/ownership, không phải tuyên bố sạch/bẩn |
| `start` (→CLEANING) | **DIRTY** (ghi tường minh, không kế thừa) | Phòng đang dọn dở luôn là bẩn |
| `complete` (→INSPECTED) | **CLEAN** | **Quyết định nghiệp vụ:** "complete" nghĩa là đã dọn xong vật lý, chỉ còn chờ QC — coi là sạch, khớp với mapping INSPECTED→CLEAN dùng ở mọi nơi khác. Nếu ChatGPT muốn complete KHÔNG tự động = sạch (chờ pass riêng), đây là điểm cần review lại rõ ràng nhất — đã document + test riêng (`test_complete_cleaning_transitions_room_to_inspected` khẳng định CLEAN) để dễ đảo ngược nếu cần |
| `inspect pass` | **CLEAN** | Theo yêu cầu |
| `inspect fail` | **DIRTY** | Theo yêu cầu |
| `inspect skip` | **CLEAN** | Skip hiện coi như pass (giữ hành vi cũ: status→VACANT_CLEAN), nay đồng bộ cleaning_status khớp theo |
| `maintenance` (out-of-order) | không đổi (giữ nguyên giá trị trước đó) | Theo yêu cầu — không mất tín hiệu sạch/bẩn cũ ngoài ý muốn |
| `release` (khỏi bảo trì) | = `target_status->impliedCleaningStatus()` | Lựa chọn tường minh của người thao tác tại thời điểm mở khóa (VacantDirty/VacantClean) luôn thắng, không "khôi phục" giá trị đông cứng trước bảo trì — đảm bảo status/cleaning_status không bao giờ mâu thuẫn ngay sau release |

### V. Quyền UI cuối cùng

Backend permission **không bị thu hẹp** (giữ nguyên `room.status.update`/`housekeeping.assign` cho HOUSEKEEPING — tránh regression cho bất kỳ API/route nào đang phụ thuộc) — chỉ **UI bị gate chặt hơn**:

- `Index.vue` thêm `canSeeAdvanced = can.inspect || can.maintenance` (cả hai chỉ true cho ADMIN/MANAGER). Mục "Nâng cao" trong Popup Chi tiết chỉ hiển thị khi `canSeeAdvanced` — với HOUSEKEEPING (vốn có `can.assign`/`can.updateStatus` = true nhưng `can.inspect`/`can.maintenance` = false), mục này nay **ẩn hoàn toàn**, đã QA trực tiếp bằng tài khoản HOUSEKEEPING thật.

| Role | Đánh dấu sạch/bẩn | Bulk sạch/bẩn | "Nâng cao" (assign/start/complete/inspect) | Bảo trì | Tài chính |
|---|---|---|---|---|---|
| ADMIN | ✓ | ✓ | ✓ | ✓ | ✓ (theo quyền sẵn có) |
| MANAGER | ✓ | ✓ | ✓ | ✓ | ✓ (theo quyền sẵn có) |
| HOUSEKEEPING | ✓ | ✓ | **✗ (đã ẩn)** | ✗ | ✗ |
| RECEPTION | ✓ | ✓ | ✗ (đã ẩn từ trước) | ✗ | ✗ |

QA trực tiếp (browser, tài khoản HOUSEKEEPING/RECEPTION thật, tạo tạm và soft-delete sau khi test) xác nhận: card chỉ có đúng 1 nút hành động chính + Chi tiết; Popup Chi tiết không còn mục Nâng cao cho cả 2 role; sidebar không có menu tài chính/báo cáo cho HOUSEKEEPING; double-tap bị chặn (nút disable + "Đang xử lý..." ngay từ lần bấm đầu, lần bấm thứ hai rơi vào phần tử đã disabled).

### VI. Test bổ sung

29 test case mới trong lượt review này (không tính lượt implement ban đầu):

- `tests/Unit/Enums/RoomStatusTest.php` **(file mới)** — 8/8 case của `impliedCleaningStatus()` + guard "không case nào lọt lưới".
- `HousekeepingServiceTest`: `startCleaning` ép DIRTY kể cả khi cleaning_status cũ là CLEAN; `completeCleaning`/`passInspection`/`failInspection`/`skipInspection` đúng mapping; `markOutOfOrder` giữ nguyên cleaning_status cũ; `releaseFromOutOfOrder` sync theo cả 2 target.
- `RoomCrudTest`: tạo phòng VACANT_DIRTY qua CRUD sync đúng; sửa status→VACANT_CLEAN qua CRUD sync đúng; sửa status→OCCUPIED qua CRUD **không** đụng cleaning_status cũ.
- `StayServiceHousekeepingHookTest`: checkout thất bại (chưa check-in) không làm bẩn phòng (rollback safety).
- `StayServiceMoveRoomTest`: phòng mới giữ nguyên cleaning_status cũ (kể cả khi đang DIRTY) sau khi khách chuyển vào.
- `HousekeepingControllerTest`: props `cleaning_status`/`cleaning_status_label` tách biệt hoàn toàn khỏi `status_label` (case INSPECTED chứng minh rõ nhất).

### VII. UI/mobile QA

QA trực tiếp qua Claude-in-Chrome với tài khoản HOUSEKEEPING và RECEPTION thật: card/Popup/bulk/toast/double-tap-guard đều đúng thiết kế (chi tiết mục V ở trên). **Hạn chế công cụ:** `resize_window` trong môi trường này không phản ánh đúng vào ảnh chụp màn hình (viewport ảnh chụp không đổi theo kích thước cửa sổ yêu cầu), nên không xác nhận trực quan được đúng 360×800/390×844/412×915 — bù lại bằng review code: layout dùng `min-h-11` (44px, không đổi từ bản trước), `flex-col` tối đa 2 nút/card, không có `overflow-x`/width cố định nào mới được thêm, kế thừa nguyên vẹn `RoomBoardGrid.vue` (không sửa) vốn đã responsive qua `grid-cols-2 sm:grid-cols-3 md:grid-cols-4...`. Khuyến nghị: xác nhận lại bằng thiết bị thật hoặc DevTools thủ công trước khi go-live nếu cần độ tin cậy tuyệt đối ở 3 mốc pixel chính xác.

### VIII. Lỗi phát hiện và đã sửa (tổng hợp)

1. **CRUD `/rooms` không sync cleaning_status** khi admin đổi status trực tiếp — đã sửa (`RoomService::syncCleaningStatusForVacantCluster()`).
2. **Toàn bộ luồng "Nâng cao"** (start/complete/pass/fail/skip/release) chưa từng ghi `cleaning_status`, có thể để lại giá trị cũ (đông cứng) gây hiển thị sai badge — đã sửa từng method, có test khóa hành vi.
3. **Migration backfill OUT_OF_ORDER/OUT_OF_SERVICE → CLEAN** là giả định không có căn cứ — đã đổi sang DIRTY, migration đã rollback+re-migrate trên DB dev.
4. **HOUSEKEEPING vẫn thấy mục "Nâng cao"** trên UI dù sản phẩm muốn chỉ còn thao tác đơn giản — đã gate UI theo `can.inspect || can.maintenance`, không đụng permission backend.
5. Trùng lặp code giữa `markClean()`/`markDirty()` — đã gộp vào `applyCleaningState()` dùng chung.

### IX. Rủi ro còn lại

- Quyết định "complete = CLEAN" (mục IV) là suy luận nghiệp vụ hợp lý nhất theo mapping đã thống nhất, nhưng vẫn là một quyết định sản phẩm — nếu ChatGPT muốn "complete" giữ trạng thái trung lập chờ inspect thật sự pass mới coi là sạch, cần review lại riêng dòng này (đã cô lập rõ trong 1 dòng code + 1 test, dễ đảo ngược).
- Mobile QA ở đúng 3 breakpoint pixel chưa được xác nhận bằng ảnh chụp thật (giới hạn công cụ trong phiên này) — đã bù bằng review code, khuyến nghị xác nhận thêm bằng thiết bị thật trước go-live.
- `room.cleaning.update` (từ patch trước) và toàn bộ thay đổi trong review này chỉ mới áp dụng trên DB dev cục bộ — môi trường khác cần chạy lại seeder + migration trước khi nhận các sửa đổi này.

---

## 24. Commit Readiness

**READY FOR COMMIT**

Căn cứ:

- **Build:** `npm run build` — PASS (không lỗi, không cảnh báo mới).
- **Test suite toàn bộ (chạy sau cùng, sau tất cả sửa đổi Final Consistency Review):** `php artisan test` — **951 passed, 23 failed** (584s).
  - Đối chiếu từng lỗi với danh sách 23 lỗi pre-existing đã xác minh bằng `git stash` trước khi bắt đầu review này: **khớp 100% — cùng 11 lỗi `BookingManagementUiTest` + cùng 12 lỗi `RoomAvailabilityCheckerTest`, cùng nguyên nhân (date-drift hardcode, không liên quan Housekeeping)**. Không có lỗi mới nào phát sinh từ bất kỳ thay đổi nào trong review này (enum helper, migration remap, sync luồng Nâng cao, CRUD fix, gate UI).
  - Test flaky `PerStayAttributionTest` (do thứ tự chạy, không liên quan) không tái xuất hiện lần chạy này.
  - 149 test case của riêng module Housekeeping/Room (bao gồm 29 test mới thêm trong review này) — toàn bộ pass.
- **Không còn khả năng lệch status/cleaning_status ở các luồng chính** đã rà soát: markClean/markDirty, toàn bộ luồng Nâng cao (assign/start/complete/pass/fail/skip/maintenance/release), checkout, partial checkout, move-room, CRUD admin `/rooms` — mỗi điểm đều có test khóa hành vi.
- **Mapping migration an toàn**, đã sửa 2 case (OUT_OF_ORDER/OUT_OF_SERVICE) từng gán CLEAN vô căn cứ sang DIRTY; đã rollback+re-migrate trên DB dev, xác nhận dữ liệu đúng bằng truy vấn trực tiếp.
- **Checkout/move-room/partial checkout đúng**, xác nhận qua cả code review (thứ tự lock/DML trong transaction) lẫn test tự động.
- **Luồng Nâng cao không còn làm hỏng mô hình 2 trạng thái** — mọi transition đều ghi `cleaning_status` tường minh, không còn giá trị đông cứng/kế thừa sai.
- **HOUSEKEEPING UI thực sự chỉ còn thao tác đơn giản** — đã gate "Nâng cao" theo `can.inspect || can.maintenance`, QA trực tiếp bằng tài khoản HOUSEKEEPING/RECEPTION thật xác nhận không còn thấy workflow nhiều bước, không thấy bảo trì, không thấy tài chính.
- **Chưa commit, chưa push** — toàn bộ thay đổi vẫn ở working tree.

**Điểm cần ChatGPT xác nhận thêm (không phải blocker, nhưng là quyết định sản phẩm nên chốt tường minh):** quyết định "complete cleaning = CLEAN" (mục 23.IV) — đây là suy luận hợp lý nhất theo mapping đã thống nhất nhưng vẫn là lựa chọn nghiệp vụ, nên xác nhận trước khi coi là chốt cuối cùng.

---

Không commit. Không push. Chờ ChatGPT review.
