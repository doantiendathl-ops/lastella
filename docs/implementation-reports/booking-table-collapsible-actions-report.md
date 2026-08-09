# Booking Table Collapsible Actions — Implementation Report

- Repository: LASTELLA PMS
- Branch: `phase-3`
- Nguyên tắc: Minimal Change — không sửa route, không đổi quyền backend, không đổi nghiệp vụ booking

---

## 1. Vấn đề ban đầu

Cột "THAO TÁC" trong `resources/js/Pages/Admin/Bookings/Index.vue` hiển thị đồng thời tới 7 nút icon riêng lẻ (Xem, Sửa, Thêm nhu cầu phòng, Thêm đặt cọc, Phân phòng, Hủy booking, Khôi phục booking) trên mỗi dòng, cố định `sticky left-0`. Trên màn hình mobile, cụm 7 icon này chiếm gần hết chiều rộng khả dụng, đẩy toàn bộ các cột dữ liệu booking (mã, khách hàng, trạng thái...) ra ngoài vùng nhìn thấy, buộc người dùng phải cuộn ngang liên tục chỉ để xem dữ liệu.

## 2. Thiết kế thu gọn

Cột "THAO TÁC" có 2 chế độ:

- **Mở rộng** (giữ nguyên UI cũ): hàng icon, mỗi icon có `title` tooltip, giữ backward-compatible 100% với hành vi trước đây.
- **Thu gọn**: mỗi dòng chỉ còn 1 nút "⋯" (`MoreHorizontal`, lucide-vue-next), rộng 44×44px (mobile) / 32×32px (desktop, `sm:h-8 sm:w-8`). Bấm vào mở menu thao tác đúng booking của dòng đó.

Cả 2 chế độ render từ **cùng một danh sách action** — hàm `bookingActions(booking)` trong `Index.vue` (xem mục 5) — nên không có 2 bộ logic độc lập.

## 3. Hành vi mobile

- `useMediaQuery('(max-width: 639px)')` (composable mới, `resources/js/Composables/useMediaQuery.js`) xác định `isMobile`.
- Trên mobile, `actionsCollapsed` luôn `true` (không có nút "Thu gọn/Mở rộng" ở header — nút này chỉ render khi `!isMobile`).
- Menu mở dạng **bottom sheet**: trượt từ đáy màn hình, tối đa `80vh`, có backdrop mờ (bấm ra ngoài để đóng), header hiển thị "Thao tác booking {mã}" + nút đóng (X), nội dung cuộn được (`overflow-y-auto`) nếu danh sách action dài, padding an toàn `env(safe-area-inset-bottom)` để không bị thanh điều hướng điện thoại che.
- Mỗi item trong menu: icon + tên chữ đầy đủ, vùng bấm tối thiểu `min-h-11` (44px), action nguy hiểm (Hủy booking) có đường kẻ phân cách phía trên + màu đỏ (coral) + luôn đặt cuối danh sách.

## 4. Hành vi desktop

- Nút "Thu gọn"/"Mở rộng" ở tiêu đề cột "THAO TÁC" (chỉ hiện khi không phải mobile).
- Khi thu gọn: menu mở dạng **dropdown nổi**, định vị bằng `getBoundingClientRect()` của nút trigger (`position: fixed`, không cần cộng `scrollX/scrollY`), tự động:
  - Kẹp mép trái vào viewport nếu menu tràn ra ngoài bên phải.
  - Lật lên phía trên nút trigger nếu không đủ chỗ phía dưới.
  - Đóng khi: chọn 1 action, click ra ngoài (`mousedown` listener), nhấn `Escape`, cuộn trang/scroll bảng (đóng thay vì cố bám theo vị trí — tránh trôi lệch khi bảng có `overflow-x-auto` riêng), resize cửa sổ.
- `role="menu"` / `role="menuitem"` / `aria-expanded` / `aria-haspopup="menu"` được giữ để không mất accessibility.

## 5. Danh sách action dùng chung

Hàm `bookingActions(booking)` (trong `Index.vue`) trả về mảng object, mỗi phần tử gồm đúng cấu trúc yêu cầu: `key`, `label`, `icon`, `visible`, `disabled`, `disabledReason` (khi có), `href` hoặc `handler`, `danger` (khi có). `visibleActions(booking)` lọc theo `visible` trước khi truyền vào cả 2 chế độ render.

| key | label (giữ nguyên từ source cũ) | visible điều kiện | disabled điều kiện |
|---|---|---|---|
| `view` | Xem | luôn `true` (không đổi so với trước — trang index đã tự giới hạn theo `viewAny`) | không |
| `edit` | Sửa | `can.updateBooking` | `!booking.can_edit` (lý do: `booking.edit_disabled_reason`) |
| `add-requirement` | Thêm nhu cầu phòng | `can.updateBooking` | không |
| `add-payment` | Thêm đặt cọc | `can.addPayment` | không |
| `assign-room` | Phân phòng | `can.assignRoom` | không |
| `cancel` | Hủy booking | `can.cancelBooking && (booking.can_cancel \|\| cancel_disabled_reason)` | `!booking.can_cancel` (lý do: `booking.cancel_disabled_reason`) |
| `restore` | Khôi phục booking | `booking.can_restore` | không |

Toàn bộ điều kiện `visible`/`disabled` lấy nguyên trạng từ props `can.*` và các trường `booking.can_edit`/`can_cancel`/`can_restore`/`*_disabled_reason` **do backend tính sẵn** (`BookingController::index()`), không thêm/bớt logic phân quyền nào ở frontend.

## 6. File sửa

- **Mới:** `resources/js/Composables/useMediaQuery.js` — composable dùng chung, tái sử dụng được cho các trang khác nếu cần.
- **Mới:** `resources/js/Pages/Admin/Bookings/Partials/BookingActionsMenu.vue` — component dropdown/bottom-sheet, nhận `actions`/`bookingCode`/`isMobile`/`open`, không chứa logic nghiệp vụ (chỉ render + điều khiển đóng/mở/định vị).
- **Sửa:** `resources/js/Pages/Admin/Bookings/Index.vue` — thêm `bookingActions()`, `visibleActions()`, state thu gọn/mở rộng + localStorage, thay cell/header cột "Thao tác" bằng bản data-driven.
- **Không đụng:** routes, controllers, policies, requests, migrations — không file backend nào bị sửa.

## 7. localStorage

- Key: `booking_table_actions_collapsed` (đúng theo yêu cầu).
- Giá trị: chuỗi `'true'`/`'false'` (ghi bằng `String(boolean)`).
- Chỉ đọc/ghi ở phía client (`onMounted` + khi bấm nút toggle) — không có request nào tới backend liên quan trạng thái này.
- Trên mobile: state lưu trong localStorage **không quyết định hiển thị** — `actionsCollapsed = isMobile || desktopCollapsedPreference`, nên mobile luôn ép về collapsed bất kể giá trị lưu, đúng yêu cầu "mặc định collapsed=true kể cả khi chưa có giá trị lưu".
- Trên desktop: nếu chưa có giá trị lưu → mặc định `expanded` (`false`); nếu đã lưu → dùng đúng giá trị đó, giữ qua reload (đã QA xác nhận, xem mục 10).

## 8. Permission

Không có permission mới, không đổi permission cũ. Toàn bộ visibility action tiếp tục dựa 100% vào props `can.*` (đã có sẵn từ `BookingController::permissions()`) và các trường per-row đã tính ở backend (`can_edit`, `can_cancel`, `can_restore`, `*_disabled_reason`). QA thực tế với role SALES (không có `payment.create`/`room.assign`) xác nhận "Thêm đặt cọc" và "Phân phòng" **không xuất hiện** trong cả 2 chế độ — không phải bị ẩn/disable ở frontend, mà đơn giản không có trong mảng `visibleActions()` được truyền xuống, đúng nguyên tắc "action không có quyền không xuất hiện, không chỉ disable giả".

## 9. Quyết định sticky/fixed column

- Cột thao tác giữ nguyên **sticky left-0** (không phải sticky right như gợi ý ban đầu trong yêu cầu — kiểm tra source xác nhận layout gốc vốn đã sticky bên trái, giữ nguyên để không đổi hành vi cuộn ngang hiện có).
- Chế độ thu gọn: `w-14 min-w-14 max-w-14` (56px, đúng khoảng 48–56px yêu cầu) thay cho việc không giới hạn width như bản icon (vốn cần ~280px cho 7 icon).
- Vẫn giữ sticky khi thu gọn (không bỏ sticky) — vì ở 56px, cột không che đáng kể dữ liệu, và giữ sticky giúp người dùng luôn bấm được nút "⋯" khi đang cuộn ngang xem dữ liệu — đây là phương án được chọn sau khi cân nhắc theo đúng tiêu chí "chọn phương án giúp xem dữ liệu tốt nhất" (cột hẹp không tạo áp lực đáng kể lên không gian hiển thị, trong khi bỏ sticky sẽ làm mất khả năng thao tác nhanh khi đã cuộn sâu).
- Không có `box-shadow` nào được thêm (bản gốc không dùng box-shadow cho sticky, chỉ `border-r`) — giữ nguyên.

## 10. QA viewport

**Desktop (đã QA trực tiếp qua trình duyệt, tài khoản ADMIN + SALES thật):**
- Expanded: đầy đủ icon, hoạt động đúng như trước (Xem/Sửa/Thêm nhu cầu phòng/Thêm đặt cọc/Phân phòng/Hủy booking).
- Toggle "Thu gọn" → cột thu nhỏ còn nút "⋯", các cột dữ liệu (TRẠNG THÁI, KINH DOANH...) hiện thêm ra được.
- Bấm "⋯" → dropdown mở đúng vị trí, đúng dữ liệu booking của dòng đó.
- Đổi sang dòng khác → dropdown cũ đóng, dropdown mới mở đúng booking mới (không dính dữ liệu dòng cũ) — xác nhận bằng `openMenuBookingId` chỉ cho phép 1 menu mở tại một thời điểm.
- Click ra ngoài → đóng đúng.
- Bấm "Hủy booking" trong menu (dòng có `can_cancel=true`) → mở đúng modal xác nhận hiện có, đúng mã booking/khách hàng, không thực hiện hủy ngay.
- Reload trang → trạng thái mở rộng/thu gọn được giữ nguyên (localStorage hoạt động).
- Role SALES → menu/icon chỉ còn 4 action (Xem, Sửa, Thêm nhu cầu phòng, Hủy booking), không có Thêm đặt cọc/Phân phòng — đúng permission.

**Mobile 360×800 / 390×844 / 412×915:** **chưa xác nhận được bằng ảnh chụp thực tế** — xem mục 13 (Known limitations). Đã bù đắp bằng:
- Review code kỹ lưỡng nhánh `isMobile` trong `BookingActionsMenu.vue` (bottom sheet, `min-h-11`, `overflow-y-auto`, `safe-area-inset-bottom`, backdrop click-to-close, nút đóng riêng).
- Nhánh mobile dùng chung 100% dữ liệu/logic `runAction()`/`actions` với nhánh desktop-collapsed đã được QA trực tiếp thành công — chỉ khác phần khung chứa (bottom sheet vs dropdown), nên rủi ro sai lệch hành vi nghiệp vụ là thấp.

## 11. Build/test

- `npm run build`: **PASS** (2379 module, không lỗi, không cảnh báo mới).
- `php artisan test tests/Feature/BookingManagementUiTest.php tests/Feature/BookingEngineFoundationTest.php`: **177 passed, 11 failed** (1307 assertions).
  - 11 lỗi thất bại **giống hệt** tập lỗi pre-existing đã xác minh bằng `git stash` trong phiên làm việc trước (date-drift hardcode trong test, không liên quan đến bất kỳ thay đổi nào ở đây — đã ghi nhận tại `docs/implementation-reports/housekeeping-clean-dirty-simplification-report.md` mục 17).
  - **Không có lỗi mới nào** phát sinh từ thay đổi cột Thao tác.

## 12. Regression

Đã kiểm tra qua QA trực tiếp (mục 10) và test tự động (mục 11):
- Xem booking: hoạt động (Link `/admin/bookings/{id}` không đổi).
- Chỉnh sửa: hoạt động, giữ nguyên `can_edit`/`edit_disabled_reason`.
- Thêm phí/nhu cầu phòng: hoạt động, route `?tab=info` không đổi.
- Thanh toán: hoạt động, route `?tab=payments` không đổi.
- Phân phòng: hoạt động, route `?tab=room_map` không đổi.
- Hủy booking: modal xác nhận không đổi hành vi, vẫn yêu cầu nhập đúng mã + lý do trước khi submit.
- Phân quyền: xác nhận qua role SALES (mục 10).
- Không có route, controller, policy, request, migration nào bị sửa — 0 rủi ro tới các luồng booking/checkout/folio khác.

## 13. Known limitations

- **Không xác nhận trực quan được mobile viewport 360×800/390×844/412×915 bằng ảnh chụp thật** trong phiên làm việc này — công cụ browser-automation (`resize_window`) không phản ánh đúng/ổn định vào `window.innerWidth` thực tế trong môi trường này (đã xác nhận qua `window.innerWidth` luôn trả về kích thước cửa sổ gốc dù đã gọi resize, đây là hạn chế công cụ đã gặp và ghi nhận từ phiên làm việc trước, không phải lỗi code). Khuyến nghị: xác nhận lại bằng DevTools device toolbar hoặc thiết bị thật trước khi go-live.
- Dropdown desktop đóng khi cuộn (scroll) thay vì bám theo vị trí trigger khi cuộn — đánh đổi có chủ đích để tránh trôi lệch vị trí khi bảng có vùng cuộn ngang riêng (`overflow-x-auto`); nếu người dùng cuộn trong lúc menu đang mở, menu sẽ tự đóng thay vì hiển thị sai vị trí.
- `bookingActions()` hiện định nghĩa trực tiếp trong `Index.vue` (không tách thành file dùng chung độc lập) vì hiện chỉ có 1 nơi tiêu thụ — nếu tương lai `Show.vue` hoặc trang khác cần tái sử dụng chính xác danh sách này, nên tách thành composable riêng lúc đó (tránh trừu tượng hóa sớm khi chưa có consumer thứ 2).

## 14. Trạng thái

**READY FOR REVIEW**

- Build PASS, test liên quan Booking không có regression mới (chỉ còn 11 lỗi pre-existing đã biết).
- QA desktop đầy đủ (expanded/collapsed/permission/reload/từng-dòng-đúng-dữ-liệu) qua trình duyệt thật.
- Mobile QA trực quan chưa hoàn tất do giới hạn công cụ (mục 13) — không phải blocker về mặt code (logic dùng chung đã được kiểm chứng qua nhánh desktop-collapsed), nhưng nên xác nhận thêm bằng thiết bị thật trước khi coi là chốt cuối cùng.

---

Không commit. Không push. Chờ ChatGPT review.
