# Hướng dẫn cho vai trò RECEPTION (Lễ tân)

> Xem tổng quan quyền hạn tại [README.md](README.md). Lễ tân là vai trò
> vận hành chính tại quầy: xếp phòng, nhận/trả phòng, thu tiền, ghi
> nhận yêu cầu khách, và theo dõi tình trạng buồng phòng hàng ngày.

## Menu bạn thấy

**Tổng quan**, **Đặt phòng**, **Kiểm tra phòng**, **Dọn phòng**,
**Kiểm đồ trả phòng**, **Nhật ký**.

Phần lớn thao tác của Lễ tân nằm **bên trong trang chi tiết một
booking** (mở từ menu **Đặt phòng** → chọn **Xem**), chia thành các
tab: **Thông tin Booking**, **Sơ đồ phòng**, **Tài chính**, **Yêu cầu**
(nếu có), **Lịch sử**.

## 1. Đặt phòng (Booking)

- Tạo booking mới, **Sửa**, **Thêm nhu cầu phòng**, **Thêm đặt cọc**,
  **Hủy booking**, **Khôi phục booking** — thao tác giống hướng dẫn
  Sales, xem chi tiết ở [01-sales.md § 2–4](01-sales.md). Lễ tân
  không quản lý bảng giá phòng.

## 2. Xếp phòng — tab "Sơ đồ phòng"

1. Mở booking → tab **Sơ đồ phòng**.
2. Với mỗi nhu cầu phòng chưa xếp, chọn một phòng trống phù hợp loại
   phòng để gán vào booking (**Phân phòng**).
3. Nếu phòng chọn bị xung đột với booking khác, hệ thống báo trạng
   thái xung đột (`conflict`) — cần chọn phòng khác hoặc xử lý phòng
   đang giữ trước.
4. Có thể hủy gán phòng (giải phóng) nếu xếp nhầm, miễn là khách chưa
   nhận phòng.

## 3. Nhận phòng (Check-in)

- Trong bảng **Nhận phòng - Trả phòng** ở tab Sơ đồ phòng, mỗi dòng là
  một lượt ở (stay) gắn với một phòng đã xếp.
- Khi đến đúng/sau giờ nhận phòng dự kiến, nút **Nhận phòng** khả
  dụng — bấm để ghi nhận giờ khách vào ở thực tế.
- Nếu bấm sớm hơn giờ dự kiến, hệ thống hiện **"Chưa đến giờ nhận
  phòng"** thay vì nút Nhận phòng.
- Có thể dùng **Nhận phòng** hàng loạt (nếu có nhiều phòng cùng
  booking) bằng nút nhận phòng tất cả ở đầu bảng.

## 4. Trong thời gian khách ở

- **Gia hạn lưu trú** — dời ngày trả phòng dự kiến sang muộn hơn khi
  khách yêu cầu ở thêm.
- **Đổi phòng** — chuyển khách đang ở sang phòng khác (nhập lý do đổi
  phòng), dùng khi cần đổi loại phòng, sửa chữa, hoặc theo yêu cầu
  khách.
- **Yêu cầu đặc biệt** (tab **Yêu cầu**) — ghi nhận các yêu cầu về
  giường (ghép/tách giường, thêm giường phụ), đồ dùng thêm (nôi em
  bé, gối, chăn, khăn...), trang trí (sinh nhật, kỷ niệm, đón khách
  VIP...), hỗ trợ đặc biệt (xe lăn, phòng không khói thuốc, gần thang
  máy...), hoặc yêu cầu khác (đến muộn, đón sân bay, phòng thông
  nhau). Yêu cầu sau đó được xác nhận/hoàn tất bởi Lễ tân hoặc Buồng
  phòng tùy loại.

## 5. Tài chính — tab "Tài chính"

- **Thêm phí** (charge) — ghi nhận các khoản phát sinh: ăn uống, dịch
  vụ, phí phát sinh khác... vào hóa đơn (folio) của booking.
- **Thêm thanh toán** — ghi nhận tiền khách đã trả (tiền mặt/chuyển
  khoản/thẻ...).
- Lễ tân **xem được** toàn bộ hóa đơn (folio) nhưng **không được**
  xóa khoản thanh toán, không hủy (void) một dòng phí đã ghi, và
  không đóng/mở lại hóa đơn — các thao tác đó chỉ Quản lý/Admin làm.

## 6. Trả phòng (Check-out)

1. Bấm **Trả phòng** trên dòng lượt ở tương ứng.
2. **Nếu còn nợ tiền** và đây là phòng cuối cùng còn ở của booking, hệ
   thống **chặn** trả phòng và yêu cầu quay lại tab Tài chính thu đủ
   tiền trước.
3. **Nếu phòng chưa được kiểm đồ trả phòng** (xem mục 7), hệ thống
   hiện cảnh báo — Lễ tân có thể bấm **"Vẫn trả phòng"** để bỏ qua
   cảnh báo và tiếp tục (cảnh báo chỉ mang tính nhắc nhở, không khóa
   cứng đối với Lễ tân).
4. **Xác nhận trả phòng cuối cùng**: khi trả phòng cuối cùng của
   booking, hệ thống hiện hộp thoại tóm tắt các khoản sẽ chốt — đọc kỹ
   rồi bấm **"Xác nhận trả phòng cuối cùng"** để hoàn tất. Đây là bước
   bắt buộc, không thể tắt.
5. Có thể **Trả phòng** hàng loạt cho toàn bộ các phòng đang ở còn lại
   của booking bằng nút trả phòng tất cả (bị khóa nếu vẫn còn nợ
   tiền).

## 7. Kiểm đồ trả phòng (Kiểm minibar/đồ dùng)

- Menu **Kiểm đồ trả phòng** liệt kê các lượt ở sắp/đang trả phòng
  trong ngày.
- Với mỗi phòng, bấm **Kiểm đồ** để mở phiếu kiểm, tick các
  minibar/đồ dùng khách đã dùng theo danh mục sản phẩm/dịch vụ, lưu
  nháp (**Đang kiểm**) hoặc hoàn tất.
- Sau khi hoàn tất: nếu có phát sinh chi phí, hệ thống tự động ghi
  khoản phí đó vào hóa đơn (**Có phát sinh**); nếu không có gì phát
  sinh thì đánh dấu **Không phát sinh**.
- Nên hoàn tất kiểm đồ **trước khi** bấm Trả phòng để tránh cảnh báo ở
  mục 6 bước 3.

## 8. Theo dõi buồng phòng — menu "Dọn phòng"

Lễ tân xem được bảng trạng thái buồng phòng theo tầng để biết phòng
nào đang trống-sạch, đang dọn, đang bẩn... phục vụ việc xếp phòng ở
mục 2. Việc giao việc dọn phòng cụ thể cho từng nhân viên buồng phòng
do vai trò **Buồng phòng** (Housekeeping) thực hiện — xem
[03-buong-phong.md](03-buong-phong.md).

## Bạn KHÔNG làm được

- Không xóa khoản thanh toán, không hủy (void) dòng phí, không
  đóng/mở lại hóa đơn (folio).
- Không **"Bỏ qua và trả phòng"** với lý do chính thức khi chưa kiểm
  đồ — chỉ có thể **"Vẫn trả phòng"** (bỏ qua cảnh báo thông thường);
  quyền bỏ qua có ghi lý do chính thức thuộc về Quản lý/Admin.
- Không quản lý bảng giá phòng, không cấu hình phòng/tầng/loại phòng.
- Không chạy Night Audit, không xem Doanh thu/Đối soát.
- Không quản lý người dùng, vai trò, quyền, hay cài đặt hệ thống.
