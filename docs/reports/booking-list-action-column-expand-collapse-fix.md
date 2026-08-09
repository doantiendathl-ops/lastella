# Booking List Action Column Expand/Collapse Fix

**Ngày:** 2026-08-09 · **Nhánh:** `phase-3` · Không commit, không push, không deploy.

## Root Cause

Cột "THAO TÁC" khi thu gọn (`actionsCollapsed = true`) được giới hạn width bằng `w-14 min-w-14 max-w-14` (56px, `px-2` = 40px content width khả dụng) trên `<th>` — **cùng class binding** dùng cho `<td>` dữ liệu (đúng, vì `<td>` khi thu gọn chỉ cần chứa 1 nút "⋯" 32px). Nhưng trước khi sửa, nội dung `<th>` vẫn là `<div class="flex items-center gap-2"><span>Thao tác</span><button>...</button></div>` — tức là LUÔN render cả label "Thao tác" (8 ký tự có dấu) LẪN nút text đầy đủ "Mở rộng"/"Thu gọn" bên trong cùng 1 flex row 40px, bất kể trạng thái. Nội dung này không bao giờ fit trong 40px.

Vì `<table>` không có `table-fixed` (mặc định `table-auto`), nội dung header bị tràn (overflow) không được browser tự động cắt/ẩn — thay vào đó, trình duyệt dùng kích thước nội dung lớn nhất để tính width cột thật sự, khiến cột không thực sự thu hẹp đúng 56px như khai báo, đồng thời chữ "THAO TÁC" bị wrap ("THAO" xuống dòng, "TÁC" dòng dưới) và nút "Mở rộng" bị đẩy chồng ngay dưới, đúng y hệt hiện tượng "chữ/control chen vào header bảng" trong ảnh Product Owner báo cáo (đã tái hiện lại y hệt trên production, xem mục QA).

**ROOT CAUSE = width issue kết hợp CSS/flex overflow issue, lộ ra qua `table-auto` layout.** Không phải structural DOM issue (vị trí control trong `<th>` là đúng vị trí, chỉ là width không đủ chứa) — không cần rewrite bảng.

## Existing Local Code Status

**EXPAND/COLLAPSE FEATURE WAS PRE-EXISTING LOCAL WORKING-TREE CODE = YES.**

Toàn bộ tính năng thu gọn/mở rộng cột Thao tác (state `actionsCollapsed`/`desktopCollapsedPreference`, `useMediaQuery` composable, `BookingActionsMenu.vue`, `bookingActions()`/`visibleActions()`, localStorage) đã tồn tại từ trước task này — xây dựng trong một phiên làm việc trước, **chưa từng commit**, đã tự ghi lại tại `docs/implementation-reports/booking-table-collapsible-actions-report.md` (report đó tự nhận: "Mobile QA trực quan chưa xác nhận được", không phải oversight của task này).

- **Phần đã tồn tại trước task này** (không đổi): toàn bộ state/composable/`bookingActions()`/`BookingActionsMenu.vue`/`<td>` (body cell) rendering logic/localStorage — xác nhận qua `git show HEAD:...Index.vue` không có bất kỳ dòng nào trong số này (HEAD là bản Index.vue cũ, đơn giản, chưa có tính năng thu gọn).
- **Phần task này thay đổi thêm**: đúng 1 khối `<th>` (header cell của cột Thao tác, ~14 dòng gốc → ~25 dòng mới) + thêm import `Maximize2` từ `lucide-vue-next`. Không đụng bất kỳ dòng nào khác trong toàn bộ diff 238 dòng của file.

## Fix

Tách nội dung `<th>` theo `actionsCollapsed`:

- **Collapsed + desktop** (`!isMobile`): chỉ render 1 nút icon-only (`Maximize2`, `h-4 w-4`, khung `h-7 w-7`) với `title`/`aria-label="Mở rộng cột thao tác"` — không còn text label nào cạnh tranh không gian. Fit gọn trong 40px.
- **Collapsed + mobile** (`isMobile`, không có toggle vốn đã không hiển thị từ trước): chỉ còn `<span class="sr-only">Thao tác</span>` — giữ accessibility, không có nội dung visible nào (mobile vốn không cần toggle, luôn collapsed).
- **Expanded** (chỉ xảy ra trên desktop): giữ nguyên y hệt hành vi cũ — `<span>Thao tác</span>` + nút text "Thu gọn", đặt trong `px-4` (đủ rộng, không đổi vì không có bug ở trạng thái này).

Không đổi class width (`w-14 min-w-14 max-w-14 px-2` / `px-4`) — chỉ đổi NỘI DUNG bên trong để nó thực sự fit trong width đã khai báo.

## Desktop QA

Thực hiện qua trình duyệt thật, local dev (`http://127.0.0.1:8000/admin/bookings`), cửa sổ trình duyệt thực tế rộng ~1488-1528px (công cụ `resize_window` không đổi được `window.innerWidth` thật — hạn chế công cụ đã biết từ nhiều phiên trước; xem thêm ghi chú dưới). Vì component này chỉ có đúng 1 breakpoint liên quan là `sm` (640px, qua `useMediaQuery('(max-width: 639px)')`), và không có breakpoint nào khác giữa 641px–1707px ảnh hưởng tới layout cột này, kết quả test ở ~1500px đại diện chính xác cho toàn bộ dải "desktop" bao gồm 1366px.

- Load trang: PASS, không lỗi.
- Trạng thái mặc định (đã từng lưu localStorage từ phiên trước = expanded): "THAO TÁC" hiển thị 1 dòng, không wrap, nút "Thu gọn" cạnh đó, không tràn — PASS.
- Click "Thu gọn": cột co lại rõ rệt, header chỉ còn icon mở rộng (chevron 2 mũi tên chéo), căn giữa, không tràn, không đè lên cột "Màu" — PASS. Các cột dữ liệu (TRẠNG THÁI...) xuất hiện thêm trong viewport — xác nhận thu gọn thực sự tiết kiệm không gian (trước đây bug khiến cột không thực sự co do content tràn).
- Click icon mở rộng lại: khôi phục đúng về "THAO TÁC / Thu gọn" — PASS.
- Header/body alignment: cả 2 trạng thái đều thẳng hàng (cùng width) — PASS.
- Action controls: click "⋯" trên 1 dòng → dropdown mở đúng vị trí, đúng danh sách action theo đúng trạng thái booking đó (dòng "Đã hủy" hiển thị "Khôi phục booking", không có "Hủy booking") — PASS.
- Console: không có lỗi (kiểm tra qua `read_console_messages`, cả sau reload) — PASS.

## Mobile QA

**KHÔNG xác nhận được bằng ảnh chụp thật** — `resize_window` báo thành công nhưng `window.innerWidth` vẫn giữ nguyên giá trị cửa sổ gốc (đã kiểm chứng trực tiếp qua `window.innerWidth`/`window.innerHeight` sau khi gọi resize) — hạn chế công cụ đã lặp lại nhất quán xuyên suốt toàn bộ dự án này, không phải lỗi code.

Bù đắp bằng code review: `isMobile` là `matchMedia`-based reactive composable thật (`useMediaQuery`, không phải class CSS breakpoint), fix chỉ thay đổi nhánh `v-else` (`<span class="sr-only">`) khi `isMobile === true` — không có text/button nào cạnh tranh không gian ở nhánh này, nên về logic không thể tái tạo bug đã sửa (tràn nội dung) ở mobile. Đây là cùng mức độ tin cậy report gốc (`booking-table-collapsible-actions-report.md` mục 13) đã dùng cho toàn bộ tính năng mobile trước đây.

**PRODUCT OWNER MANUAL VIEWPORT CHECK REQUIRED** tại `http://127.0.0.1:8000/admin/bookings` (hoặc production sau khi deploy), dùng DevTools device toolbar hoặc thiết bị thật, khoảng 390×844:
- [ ] Header cột Thao tác không hiển thị chữ tràn/wrap.
- [ ] Cột Thao tác hẹp, không chiếm phần lớn chiều ngang.
- [ ] Bấm "⋯" mở bottom sheet đúng, không che Mã/Khách hàng.
- [ ] Không tạo horizontal scroll ở page-level ngoài vùng bảng.

## Empty-State QA

Filter mã đặt phòng không tồn tại (`NONEXISTENT-CODE-XYZ`) → "Không có đặt phòng.": header vẫn render đúng ở cả 2 trạng thái (collapsed: icon gọn; expanded: label + nút) — PASS. Bug không chỉ giới hạn/khác biệt ở empty state, và fix áp dụng nhất quán cho cả 2 trường hợp.

## Populated-Table QA

Bảng có dữ liệu thật (9+ booking): đã QA đầy đủ ở mục Desktop QA phía trên — PASS.

## Regression

- Filter: nhập mã đặt phòng → Áp dụng → kết quả đúng; Đặt lại → xoá filter, về danh sách đầy đủ — PASS, không lỗi console, không phá form.
- Action functionality (expanded + collapsed): đã QA ở mục Desktop QA — PASS, không mất chức năng nào.
- `php artisan test tests/Feature/BookingManagementUiTest.php`: **154 passed, 11 failed** — 11 lỗi thất bại giống hệt tập lỗi pre-existing đã ghi nhận nhiều lần trước đây (date-drift trong test, không liên quan bất kỳ thay đổi nào ở đây — đã xác nhận cùng con số 11 trong `docs/implementation-reports/booking-table-collapsible-actions-report.md` mục 11 và trong baseline `⨯`-count của các task trước cùng phiên này). **Không có lỗi mới**. Chỉ chạy suite này (frontend-only change, không cần chạy hàng trăm test backend không liên quan theo đúng Section XX).
- Không đụng Room Demand/Room-Board/Bulk Release/Service Package/Night Audit/Folio/Revenue/Check-in-out — xác nhận qua `git diff --stat`: chỉ 1 file (`Index.vue`) bị sửa bởi task này.

## Build

`npm run build`: **PASS** (2383 module, không lỗi Vue/Vite mới).

## Git Scope

- **A. expand/collapse bug fix (task này)**: đúng 1 khối `<th>` trong `resources/js/Pages/Admin/Bookings/Index.vue` + import `Maximize2`.
- **B. pre-existing Index.vue hunks unrelated to bug fix (nhưng LÀ chính tính năng thu gọn/mở rộng, pre-existing từ trước)**: toàn bộ phần còn lại của diff 238 dòng — state/composable/`bookingActions()`/`BookingActionsMenu` integration/`<td>` rendering — đã tồn tại TRƯỚC task này, task này không đổi.
- **C. other pre-existing local changes (hoàn toàn không liên quan)**: `.gitignore`, `bootstrap/app.php`, `resources/js/Layouts/AppLayout.vue`, `resources/js/Pages/Admin/Bookings/Show.vue`, `resources/js/app.js`, và các file/thư mục untracked khác — không đụng.

Không stage, không commit trong task này — chờ ChatGPT review scope trước khi selective-stage (A hay A+B cùng lúc, vì B chưa từng được commit riêng và cũng là 1 phần hợp lệ của "Booking Table Collapsible Actions" — quyết định staging boundary để lại cho task review kế tiếp).

## Readiness

Desktop: PASS đầy đủ, bằng chứng thật. Mobile: PENDING Product Owner manual confirmation (hạn chế công cụ, không phải nghi ngờ về code). Build PASS. Không regression phát hiện.
