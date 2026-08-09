# Early Check-in & Admin Actual Time Override

**Ngày:** 2026-08-09 · **Nhánh:** `phase-3` · Active Pilot, không migration, không commit trong task này.

## Root Cause

`StayService::checkIn()` chặn check-in bằng điều kiện `now()->lt($stay->planned_checkin_at)` — nếu chưa tới giờ nhận phòng dự kiến, ném `ValidationException` với message "Chưa đến thời gian nhận phòng dự kiến...". Đồng thời, `BookingController::show()` tính sẵn 2 field UI-facing (`can_check_in`/`checkin_too_early`) cho **cả 2 mảng** `assignments[]` và `stays[]`, dùng CÙNG điều kiện thời gian này để disable nút "Nhận phòng" trên giao diện và hiển thị label "Chưa đến giờ nhận phòng" — nghĩa là gate tồn tại ở **cả backend (service) lẫn frontend (payload)**, phải gỡ đồng bộ cả hai.

**File/method chịu trách nhiệm chính xác:**
- `app/Services/StayService.php::checkIn()` — dòng chặn gốc (đã xoá).
- `app/Http/Controllers/Admin/Booking/BookingController.php` — 2 vị trí tính `can_check_in`/`checkin_too_early` (mảng `assignments[]` và `stays[]`).

**Phát hiện phụ, không phải bug của task này:** `CheckInStayRequest`/`CheckOutStayRequest` trước đây chấp nhận `actual_checkin_at`/`actual_checkout_at` từ **bất kỳ user nào có quyền `stay.checkin`/`stay.checkout`** (bao gồm RECEPTION) mà không kiểm tra role — nghĩa là về mặt kỹ thuật, một request giả mạo từ nhân viên thường đã luôn có thể tự đặt timestamp tuỳ ý trước khi có task này. Đây chính xác là lỗ hổng Section VIII yêu cầu đóng — đã sửa (mục Authorization bên dưới).

## Existing Data Model

**NO MIGRATION** — schema hiện tại đã đủ. Cột chính xác trên bảng `stays`:
- `planned_checkin_at` / `planned_checkout_at` — lịch booking gốc (SCHEDULED).
- `actual_checkin_at` / `actual_checkout_at` — sự kiện vận hành thực tế (ACTUAL).
- `checked_in_by` / `checked_out_by` — actor.

`StayService::checkIn()`/`checkOut()` **đã có sẵn** tham số `$actualCheckinAt`/`$actualCheckoutAt` (optional, `?? now()`) từ trước — hạ tầng "custom actual time" đã tồn tại, chỉ chưa được expose đúng quyền + chưa validate.

## Early Check-in Rule

Đã xoá đúng 1 điều kiện: `now()->lt($lockedStay->planned_checkin_at)` trong `StayService::checkIn()`, và điều kiện tương ứng trong `BookingController::show()` (2 vị trí). **Không đổi** bất kỳ guard nào khác:
- Assignment phải `Assigned` (không phải Released/CheckedIn/CheckedOut).
- Stay chưa từng check-in (`actual_checkin_at === null`).
- Mọi guard/room-conflict/authorization hiện có — nguyên vẹn, vì chúng nằm ở lớp tạo `RoomAssignment` (không đụng), không phải ở `checkIn()`.

`EarlyCheckinFeePostingJob` (đã tồn tại từ trước, không sửa) tự động bắt đầu áp dụng đúng như thiết kế gốc của nó ngay khi check-in sớm thực sự xảy ra — logic phí không đổi, chỉ tần suất kích hoạt tăng lên vì giờ mới có thể check-in sớm.

## Employee Behavior

Nhân viên bấm "Nhận phòng"/"Trả phòng" → POST không kèm `actual_checkin_at`/`actual_checkout_at` → `StayService` dùng `now()`. Không datetime picker. UI hoàn toàn không đổi cho non-admin.

## Admin Behavior

- **Check-in**: click "Nhận phòng" → dialog "Thời gian nhận phòng thực tế" (default = giờ hiện tại, editable) → submit qua `useForm()`, lỗi validate hiển thị inline.
- **Check-out**: click "Trả phòng" → dialog tương tự thu thập actual time TRƯỚC KHI luồng checkout hiện có (inspection warning → balance warning → final-confirm) chạy tiếp — mọi bước sau đều mang theo giá trị time đã chọn.
- **Edit sau sự kiện**: icon bút chì cạnh mỗi giá trị "Thực nhận phòng"/"Thực trả phòng" (chỉ hiện khi có quyền + giá trị đã tồn tại) → dialog sửa riêng, gọi `PATCH .../actual-check-in` hoặc `.../actual-check-out` — **chỉ đổi đúng 1 cột timestamp**, không re-run check-in/check-out, không đụng status/folio/housekeeping/StayEvent(CheckIn).

## Authorization

`$user->hasRole('ADMIN')` (Spatie, đúng convention có sẵn trong project — không tạo `is_admin` boolean mới):
- `BookingController::permissions()` → `adjustActualTime` (điều khiển hiển thị field/nút trên UI).
- `CheckInStayRequest`/`CheckOutStayRequest::withValidator()` → **reject 422** nếu field `actual_*_at` xuất hiện trong request mà user không phải ADMIN (không chỉ ignore âm thầm).
- `UpdateActualCheckInRequest`/`UpdateActualCheckOutRequest::authorize()` → `hasRole('ADMIN')`.
- `StayPolicy::updateActualCheckIn()`/`updateActualCheckOut()` → `hasRole('ADMIN')`, kiểm tra lại ở `StayService` (defense-in-depth, ném `AuthorizationException` nếu gọi trực tiếp bằng actor không phải ADMIN).

## Validation

Tất cả validate server-side, không tin frontend:
- `actual_checkin_at`: `date`, `before_or_equal:now` (FormRequest) + không được sau `actual_checkout_at` nếu đã tồn tại (Service, dưới lock).
- `actual_checkout_at`: `date`, `before_or_equal:now` (FormRequest) + không được trước `actual_checkin_at` (Service, dưới lock — cả lúc checkout lần đầu lẫn lúc sửa sau).
- Timezone: dùng `now()`/Carbon mặc định app (`Asia/Bangkok`, theo `config('app.timezone')`), không tự trộn UTC/local.

## Audit

`StayEventType::CheckInTimeAdjusted` / `CheckOutTimeAdjusted` (mới, string column không cần migration) — ghi qua `StayEventService::record()` có sẵn: `stay_id`, `actor_id`, `occurred_at`, `metadata` (`old_*_at`/`new_*_at`, ISO8601). Không thêm trường "lý do" — theo Section XX cho phép bỏ nếu làm task phức tạp thêm đáng kể; old/new + actor đã đủ cho phase này.

## Financial/Night Audit Impact

- `EarlyCheckinFeePostingJob`/`LateCheckoutFeePostingJob` — **0 dòng sửa**, đọc `actual_checkin_at`/`actual_checkout_at` y hệt trước, tự động hoạt động đúng với timestamp mới (kể cả sau khi ADMIN sửa) vì luôn đọc giá trị Stay hiện tại tại thời điểm Night Audit chạy.
- Sửa actual time **sau khi charge đã post**: không tự xoá/sửa/duplicate `FolioEntry` cũ — `updateActualCheckIn()`/`updateActualCheckOut()` chỉ `UPDATE` đúng 1 cột trên `stays`, không gọi lại Posting Job nào. Đã test trực tiếp (`test_editing_actual_time_does_not_create_duplicate_folio_entries`).
- Nếu rate/fee cần re-tính lại cho ngày đã qua sau khi sửa actual time: **ngoài phạm vi task này** (deferred reconciliation) — ghi nhận, không block việc sửa timestamp.

## Tests

`tests/Feature/EarlyCheckInAdminActualTimeOverrideTest.php` — 26/26 PASS, bao phủ đủ 25 case yêu cầu (Section XXIV): early check-in, employee now()-only + forge-rejected, admin override + future-rejected, edit-after-fact (cả 2 chiều, cả quyền), checkout-before-checkin rejected, scheduled không đổi, room-conflict/cancelled/already-checked-in/already-checked-out guards, no-duplicate-folio, audit old/new/actor, final-checkout-gate vẫn hoạt động cùng override.

**2 test pre-existing cần cập nhật để khớp rule mới (không phải regression, là thay đổi chủ đích):**
- `BookingManagementUiTest::test_stay_payload_reflects_checkin_too_early_flag` → đổi tên + assertion thành `test_stay_payload_allows_check_in_before_planned_time` (`can_check_in===true`, `checkin_too_early===false`).
- `BookingManagementUiTest::test_check_in_before_planned_time_is_rejected` → đổi tên + assertion thành `test_check_in_before_planned_time_is_allowed` (assert SUCCESS thay vì reject).

**4 test pre-existing trong `BookingEngineFoundationTest.php` cần thêm `$this->travelTo()`:** các test này gọi `StayService::checkIn()`/`checkOut()` trực tiếp với timestamp cố định (`'2026-07-01 15:00:00'`) trong khi `setUp()` đóng băng đồng hồ test ở `'2026-07-01 14:00:00'` — trước đây không validate nên "tương lai 1 giờ so với đồng hồ đóng băng" vẫn qua được; nay validation mới (đúng theo spec) chặn lại đúng. Đã thêm `$this->travelTo()` khớp thời điểm ở mỗi bước để giữ nguyên narrative gốc của test (check-in lúc 15:00, check-out ngày hôm sau lúc 11:00) mà không vi phạm rule mới.

## Regression

| Suite | Kết quả |
|---|---|
| `EarlyCheckInAdminActualTimeOverrideTest` (mới) | 26/26 PASS |
| `BookingManagementUiTest` | 154 passed / 11 failed — 11 = baseline pre-existing đã biết từ nhiều phiên trước (time-drift, không liên quan) |
| `BookingEngineFoundationTest` | 25/25 PASS (sau khi fix fixture) |
| `EarlyCheckinFeeTest` | 3/3 PASS |
| `LateCheckoutFeeTest` | 12/13 PASS — **1 lỗi mới phát hiện, KHÔNG do task này gây ra** (xem "Remaining Issues") |
| `CheckoutIntegrationTest`, `CheckoutUiTest`, `CheckoutConfirmationGateTest`, `StayEventFoundationTest`, `PartialCheckoutStayEventTest`, `StayExtendControllerTest`, `StayServiceExtendTest`, `StayServiceMoveRoomTest`, `StayServiceHousekeepingHookTest` | tất cả PASS |
| `FolioCrudTest` | PASS |
| `NightAuditOperationsTest` | PASS |
| `npm run build` | PASS |

## Browser QA

Thực hiện qua trình duyệt thật, `http://127.0.0.1:8000`, booking `QA-M4-MOBILE-6YHY` (scheduled check-in `2027-05-01`, ~9 tháng trong tương lai — trường hợp early check-in cực đoan để xác nhận chắc chắn):

**ADMIN (đã đăng nhập thật):**
- Bấm "Nhận phòng" khi `now` cách xa `planned_checkin_at` ~9 tháng → **SUCCESS**, dialog "Thời gian nhận phòng thực tế" hiện đúng, default = giờ hiện tại — PASS.
- Đặt custom time `09-Aug-2026 10:30 AM` (JS set trực tiếp trên input `datetime-local` do coordinate-typing không điều khiển được input segmented) → submit → "THỰC NHẬN PHÒNG" hiển thị đúng `2026-08-09 10:30`, "DỰ KIẾN NHẬN PHÒNG" **không đổi** (`2027-05-01 14:00`) — PASS.
- Bấm icon bút chì cạnh "THỰC NHẬN PHÒNG" → sửa thành `09:50` → lưu → cập nhật đúng, trạng thái/thao tác không đổi — PASS.
- Bấm "Trả phòng" → dialog "Thời gian trả phòng thực tế" (default now) → đặt `11:45` → "Tiếp tục trả phòng" → **luồng kiểm đồ hiện có (chưa kiểm đồ) tự động hiện ra đúng như trước** → "Vẫn trả phòng" → "THỰC TRẢ PHÒNG" = `2026-08-09 11:45` — PASS, xác nhận override thread đúng qua toàn bộ luồng checkout phức tạp có sẵn.
- Bấm bút chì sửa "THỰC TRẢ PHÒNG", thử đặt thời gian **trước** thời gian nhận phòng (`08:00 < 09:50`) → **lỗi inline hiển thị đúng**: "Thời gian trả phòng thực tế không được trước thời gian nhận phòng thực tế." — dữ liệu không bị đổi — PASS.
- Console: 0 lỗi (kiểm tra sau reload) — PASS.

**RECEPTION:** không có browser session RECEPTION thật trong phiên này (không có credential để tự đăng nhập vai khác theo nguyên tắc an toàn tuyệt đối) — hành vi RECEPTION đã được xác nhận đầy đủ qua **automated HTTP-level test thật** (request thật qua route thật, không mock): normal check-in dùng `now()` (PASS), forge `actual_checkin_at`/`actual_checkout_at` bị 422 reject (PASS). Không tự nhận PASS bằng ảnh chụp trình duyệt cho phần này — ghi rõ giới hạn.

**Mobile (390×844):** không xác nhận được bằng ảnh chụp thật — `resize_window` báo thành công nhưng `window.innerWidth` vẫn giữ nguyên (đã kiểm chứng lại lần nữa, hạn chế công cụ đã lặp lại nhất quán xuyên suốt toàn bộ dự án). **PRODUCT OWNER MANUAL VIEWPORT CHECK REQUIRED** tại `http://127.0.0.1:8000/admin/bookings/471?tab=room_map`, DevTools device toolbar hoặc thiết bị thật.

## Git Scope

- **A. Early Check-in / Admin Actual Time feature files**: `app/Enums/StayEventType.php`, `app/Http/Controllers/Admin/Booking/BookingController.php`, `app/Http/Controllers/Admin/Booking/StayController.php`, `app/Http/Requests/Booking/CheckInStayRequest.php`, `app/Http/Requests/Booking/CheckOutStayRequest.php`, `app/Http/Requests/Booking/UpdateActualCheckInRequest.php` (mới), `app/Http/Requests/Booking/UpdateActualCheckOutRequest.php` (mới), `app/Policies/StayPolicy.php`, `app/Services/StayService.php`, `resources/js/Pages/Admin/Bookings/Partials/RoomBoardPanel.vue`, `routes/web.php`, `tests/Feature/BookingEngineFoundationTest.php`, `tests/Feature/BookingManagementUiTest.php`, `tests/Feature/EarlyCheckInAdminActualTimeOverrideTest.php` (mới), báo cáo này.
- **B. Booking List Expand/Collapse (task trước)**: **KHÔNG còn gì unstaged** — toàn bộ tính năng đó đã được commit + push riêng (`d7301278666aaeb8e166fc7555c6b81ee30097f2`) trước khi task này bắt đầu. `Index.vue` sạch, không xuất hiện trong diff hiện tại.
- **C. Pre-existing unrelated local changes (không đụng)**: `.gitignore`, `bootstrap/app.php`, `resources/js/Layouts/AppLayout.vue`, `resources/js/Pages/Admin/Bookings/Show.vue`, `resources/js/app.js`, và các file/thư mục untracked khác — bao gồm 2 file MỚI xuất hiện không do task này tạo (`docs/reports/so-do-phong-thang-8-2026-du-lieu.xlsx`, `docs/reports/so-do-phong-thang-8-2026-thong-ke.md` — có vẻ do Product Owner tạo song song trong phiên này).

## Remaining Issues

1. **`LateCheckoutFeeTest::test_check_out_triggers_late_checkout_fee_via_stay_service`** — lỗi mới phát hiện, **không do task này gây ra**: test dùng `now()->subHours(2)->setTime(12,0,0)` làm "planned checkout" cố định lúc 12:00 trưa NGÀY CHẠY TEST, rồi so với `actual = now()` thật — chỉ "late" nếu chạy sau 12:30 trưa (grace 30 phút mặc định). Test chạy trước/gần 12:00 trưa sẽ fail vì thực chất chưa "muộn". Đã xác minh: `LateCheckoutFeePostingJob.php` và test file này **0 dòng bị task này sửa**; guard mới trong `checkOut()` (`<=now()`, `>=checkin`) không chặn test này (checkout = `now()` luôn hợp lệ). Đây là lỗi thiết kế fixture time-of-day-fragile pre-existing, cùng loại với các lỗi `BookingManagementUiTest`/`RoomAvailabilityCheckerTest` đã ghi nhận nhiều lần trong dự án — không sửa trong task này (ngoài phạm vi, file không liên quan).
2. **Reason field cho admin edit-after-fact** — không thêm (Section XX cho phép bỏ qua nếu làm phức tạp thêm đáng kể).
3. **Reconciliation của fee đã post khi actual time bị sửa sau đó** — deferred, ngoài phạm vi Active Pilot.
4. **Mobile viewport** — chưa xác nhận trực quan (hạn chế công cụ), cần Product Owner xác nhận thủ công.
5. **RECEPTION browser session** — hành vi xác nhận qua automated test, chưa qua trình duyệt thật (không có credential RECEPTION trong phiên này).

## Readiness

Backend + core Vue logic hoàn chỉnh, test tự động đầy đủ, Browser QA ADMIN đầy đủ real evidence. Còn 2 hạng mục cần Product Owner xác nhận thủ công (mobile viewport, RECEPTION trình duyệt thật) — không phải blocker kỹ thuật.
