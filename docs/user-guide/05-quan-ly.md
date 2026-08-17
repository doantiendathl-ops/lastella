# Hướng dẫn cho vai trò MANAGER (Quản lý)

> Xem tổng quan quyền hạn tại [README.md](README.md). Quản lý có gần
> như toàn bộ quyền vận hành và cấu hình nghiệp vụ trong hệ thống —
> **trừ** quản lý tài khoản người dùng/vai trò/quyền và cấu hình
> thông tin khách sạn cấp cao, hai việc đó chỉ **Admin** làm được.

## Menu bạn thấy

Toàn bộ menu **trừ** "Người dùng", "Vai trò", "Quyền".

## Quyền kế thừa từ các vai trò khác

Quản lý làm được **tất cả** thao tác đã mô tả trong:
- [01-sales.md](01-sales.md) — tạo/sửa/hủy booking, quản lý giá phòng.
- [02-le-tan.md](02-le-tan.md) — xếp phòng, nhận/trả phòng, gia hạn,
  đổi phòng, yêu cầu đặc biệt, thêm phí/thu tiền.
- [03-buong-phong.md](03-buong-phong.md) — dọn phòng, kiểm tra chất
  lượng phòng, kiểm đồ trả phòng.
- [04-ke-toan.md](04-ke-toan.md) — theo dõi Night Audit, Doanh thu,
  Đối soát.

Phần dưới đây chỉ liệt kê các quyền **nâng cao thêm** mà các vai trò
trên không có.

## 1. Xử lý hóa đơn nâng cao (tab "Tài chính")

- **Hủy (void) một dòng phí** — khi ghi nhầm một khoản phí, thay vì
  xóa, hệ thống dùng cơ chế hủy có ghi lý do để giữ dấu vết kế toán.
- **Xóa một khoản thanh toán** — dùng khi ghi nhầm khoản thu.
- **Đóng hóa đơn (folio)** — chốt sổ hóa đơn khi booking đã hoàn tất
  và đối soát xong; **Mở lại hóa đơn** khi cần điều chỉnh sau khi đã
  đóng.

## 2. Trả phòng khi chưa kiểm đồ

Khi hệ thống cảnh báo phòng chưa được kiểm đồ trước khi trả phòng (xem
[02-le-tan.md § 6](02-le-tan.md)), Quản lý có thêm lựa chọn
**"Bỏ qua và trả phòng"** — ghi nhận chính thức lý do bỏ qua kiểm đồ
(có lưu vết) rồi tiến hành trả phòng, thay vì chỉ bỏ qua cảnh báo tạm
thời như Lễ tân.

## 3. Night Audit

Ngoài xem như Kế toán, Quản lý còn:
- **Chạy Night Audit** thủ công cho ngày kinh doanh hiện tại (nếu chưa
  có lịch tự động, hoặc cần chạy lại theo yêu cầu).
- **Thử lại** một lượt chạy bị lỗi (nút **Thử lại** trong trang chi
  tiết lượt chạy).

## 4. Cấu hình phòng & bảng giá

- **Tầng** (`/floors`) — thêm/sửa/xóa tầng của khách sạn.
- **Loại phòng** (`/room-types`) — thêm/sửa loại phòng (tên, mô tả,
  sức chứa...).
- **Phòng** (`/rooms`) — thêm/sửa/xóa từng phòng cụ thể; có thao tác
  hàng loạt để **khóa bảo trì** hoặc **mở khóa** nhiều phòng cùng lúc.
- **Giá phòng** (`/room-rates`) — quản lý đầy đủ bảng giá theo loại
  phòng (Sales chỉ dùng để tham khảo khi bán, Quản lý có toàn quyền
  chỉnh sửa).

## 5. Sản phẩm & dịch vụ

- **Sản phẩm/Dịch vụ** (`/product-services`) — quản lý danh mục
  minibar/dịch vụ dùng trong Kiểm đồ trả phòng và ghi phí, bật/tắt
  từng sản phẩm.
- Quản lý giá dịch vụ và xem **lịch sử thay đổi giá** dịch vụ theo
  thời gian.

## 6. Cài đặt hệ thống (mức nghiệp vụ)

Menu **Cài đặt** (`/settings`) — các thiết lập vận hành chung của hệ
thống. (Riêng thông tin khách sạn cấp cao — tên công ty, địa chỉ, múi
giờ, ngày kinh doanh hệ thống — thuộc quyền cấu hình cấp cao hơn, chỉ
Admin chỉnh được; xem [06-quan-tri-vien.md](06-quan-tri-vien.md).)

## Bạn KHÔNG làm được

- Không tạo/sửa/khóa tài khoản người dùng.
- Không tạo/sửa vai trò, không gán/thu hồi quyền hạn.
- Không chỉnh cấu hình khách sạn cấp cao (thông tin công ty, ngày
  kinh doanh hệ thống...).
