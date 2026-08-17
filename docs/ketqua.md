# Kết quả điều tra: Giường phụ & Giá phụ phí — LASTELLA PMS

**Ngày:** 2026-08-16
**Môi trường kiểm tra:** `pms.lastella.com.vn` (production/pilot) + đọc mã nguồn `C:\Projects\lastella` (nhánh `phase-3`)
**Booking dùng để kiểm tra thực tế:** `BK-20260814080851-TBXS` (id 62, khách Hồng Nhung, phòng 303)

---

## TÓM TẮT

Bắt đầu từ câu hỏi "vì sao yêu cầu giường phụ đã xử lý xong mà không hiện trên Sơ đồ thao tác", điều tra phát hiện một **chuỗi lỗi liên quan đến gói dịch vụ "Giường phụ" bị tạo sai mã hệ thống**, dẫn tới:
1. Tính năng "Giường phụ theo phòng" chưa từng hoạt động được trên môi trường pilot.
2. Có khả năng **chưa từng có khoản phí giường phụ nào được tính đúng cho khách** từ trước tới nay.
3. Tồn tại **rủi ro tính tiền sai (nhân nhiều lần)** nếu nhân viên dùng nhầm nút đăng ký thông thường cho gói này, đặc biệt với booking nhiều phòng.

---

## 1. Nguyên nhân gốc: gói "Giường phụ" bị đặt sai mã hệ thống

Trang `/admin/service-packages` có gói tên **"Giường phụ"**, mã hiện tại là `GIUONGPHU`.

Nhưng cả giao diện lẫn phần lưu dữ liệu của tính năng "Giường phụ theo phòng" đều **so khớp cứng đúng chuỗi `EXTRA_BED_PER_NIGHT`**:

- `resources/js/Pages/Admin/Booking/Packages.vue:233` — chỉ hiện ô nhập số lượng theo từng phòng khi `pkg.key === 'EXTRA_BED_PER_NIGHT'`.
- `app/Services/PackageEnrollmentService.php:181` — hàm lưu số lượng theo phòng tìm gói bằng `ServicePackage::where('code', 'EXTRA_BED_PER_NIGHT')`; không thấy sẽ báo lỗi "Gói dịch vụ không tồn tại."

Vì mã thật là `GIUONGPHU` ≠ `EXTRA_BED_PER_NIGHT`, **hai điều kiện trên không bao giờ đúng** → tính năng nhập giường phụ theo từng phòng không hoạt động cho bất kỳ booking nào, không riêng gì booking đang kiểm tra.

**Mã đúng theo thiết kế ban đầu** nằm ở `database/seeders/ServicePackageSeeder.php:46` (`code => 'EXTRA_BED_PER_NIGHT'`) — nhưng seeder này **chưa từng chạy trên database production** (xác nhận: DB hiện không có gói nào mã `BREAKFAST_PER_NIGHT` hay `EXTRA_PERSON_PER_NIGHT` — 2 gói còn lại mà seeder này tạo — cũng không tồn tại). Gói "Giường phụ" hiện có nhiều khả năng được tạo tay qua giao diện, gõ nhầm mã.

**Không sửa được trực tiếp qua giao diện:** trường mã (`code`) bị khóa sau khi gói "đã được sử dụng" (`ServicePackage::hasBeenUsed()` — đúng vì gói đã có giá). Cần can thiệp cấp dữ liệu, không làm qua nút "Sửa" thông thường được — **cần bạn quyết định trước khi ai đó thực hiện, vì đây là sửa dữ liệu production.**

---

## 2. Vì sao trước đó tưởng "đã xử lý xong"

Vì tính năng đúng bị hỏng, nhân viên đã dùng một tính năng khác để ghi nhận thay: mục **"Yêu cầu đặc biệt"** (tab "Yêu cầu" của booking) → danh mục "Cấu hình giường", nội dung tự gõ "Thêm giường phụ", đánh dấu "Đã hoàn thành".

Đây là một bảng ghi chú/checklist chung (`BookingSpecialRequest`), **hoàn toàn tách biệt**, không ghi gì vào `room_assignments.extra_bed_quantity` — trường duy nhất mà Sơ đồ thao tác và phần tính tiền thực sự đọc. Đánh dấu "hoàn thành" ở đây chỉ đổi trạng thái của chính ghi chú đó, không lan sang đâu khác.

---

## 3. Phát hiện nghiêm trọng hơn: hệ thống có 2 nguồn giá độc lập cho "Giường phụ"

| Nguồn giá | Vị trí | Dùng để làm gì |
|---|---|---|
| `service_rates` (loại phí `EXTRA_BED`) | Trang **"Quản lý phụ phí hệ thống"** — `/admin/service-rates` | Giá **thật sự dùng để tính tiền** khi giường phụ được đăng ký đúng cách (theo từng phòng), qua `ExtraBedPostingJob` |
| `service_package_rates` (gắn với gói `GIUONGPHU`) | Trang **"Gói dịch vụ"** — `/admin/service-packages` | Chỉ dùng để **hiển thị giá** lúc đăng ký + kiểm tra hợp lệ trước khi lưu |

Hai nguồn này **không có ràng buộc nào bắt phải luôn khớp nhau** — hiện tại trùng ngẫu nhiên ở 150.000đ. Có 1 cột "Loại phí" trên gói dịch vụ dùng chung giá trị với loại phí bên `service_rates`, nhưng **không phải khóa ngoại thật**, không có đoạn tính tiền nào đọc cột đó để tự nối 2 nguồn lại — chỉ dùng để hiển thị chữ trên giao diện.

### Rủi ro tính tiền sai đang tồn tại ngay bây giờ

Vì mã sai, gói "Giường phụ" hiện **hiển thị nút "Đăng ký" kiểu thông thường** (giống Ăn sáng) thay vì màn hình nhập theo phòng. Nếu nhân viên bấm nhầm nút này:
- Không chạy qua `ExtraBedPostingJob` (job này chỉ nhìn `room_assignments.extra_bed_quantity`, vẫn đang là 0).
- Mà chạy qua `ServicePackagePostingJob` — job xử lý "gói lạ" không thuộc 3 mã cố định — job này tính tiền **lặp lại theo từng phòng/từng lượt lưu trú của booking**, đúng kiểu lỗi tính tiền nhân 3 lần mà hệ thống từng phải sửa trước đây cho giường phụ (ghi rõ trong chú thích mã nguồn `ExtraBedPostingJob.php`).
- Với booking 1 phòng thì chưa gây hậu quả; với booking nhiều phòng, số tiền giường phụ sẽ bị tính sai (nhân lên) nếu đăng ký nhầm theo cách này.

---

## 4. Ăn sáng & Người thêm — hiện trạng liên quan

- **Ăn sáng (`BREAKFAST_PER_NIGHT`):** đã có giá sẵn ở `service_rates` (120.000đ) nhưng **không có gói tương ứng nào trong `service_packages`** → không ai đăng ký được qua giao diện hiện tại. Chữ "Ăn sáng mỗi đêm" thấy ở 1 widget khác trên trang booking là chữ viết cứng trong giao diện, không phải dữ liệu thật.
- **Người thêm (`EXTRA_PERSON_PER_NIGHT`):** vừa không có gói trong `service_packages`, vừa **không có dòng giá nào trong `service_rates`** — kể cả khi đăng ký được, hệ thống vẫn sẽ bỏ qua vì "không có giá".

---

## 5. Đề xuất hướng xử lý (cần bạn quyết định — đụng tới dữ liệu production)

1. Kiểm tra xem gói `GIUONGPHU` đã từng được ai đăng ký/tính phí thật chưa (trước khi sửa, để không phá dữ liệu đã phát sinh).
2. Sửa `code` của gói này từ `GIUONGPHU` → `EXTRA_BED_PER_NIGHT` trực tiếp trong dữ liệu (không sửa được qua giao diện), **hoặc** tạo gói mới đúng mã và vô hiệu hóa gói cũ.
3. Rà lại toàn bộ booking đang hoạt động xem có booking nào từng bấm nhầm nút "Đăng ký" thông thường cho "Giường phụ" chưa — nếu có, kiểm tra hóa đơn có bị tính sai (nhân nhiều lần) không.
4. Cân nhắc: có nên cho ăn sáng/người thêm hoạt động trở lại không (hiện đang "chết" do thiếu gói/thiếu giá), hay đó là chủ đích tạm thời của giai đoạn pilot.

---

## Phụ lục: các câu hỏi kỹ thuật đã trả lời trong quá trình điều tra

- Cơ chế chọn màu booking (`booking_color`, palette 16 màu, loại trừ màu đang dùng).
- Ý nghĩa 2 lớp màu trên ô phòng ở Sơ đồ thao tác (màu nền 5-trạng thái phòng, và icon 3-trạng thái nhận/trả phòng).
- Chức năng nhập giường phụ theo phòng nằm ở đâu (trang "Gói dịch vụ" của từng booking).
- Công nợ toàn hệ thống: đã có sẵn ở trang "Đối soát" (`/admin/reconciliation`), quyết định không xây dashboard mới (xem thêm memory `reconciliation_covers_receivables_dashboard`).
