# Hướng dẫn cho vai trò SALES (Kinh doanh / Đặt phòng)

> Xem tổng quan quyền hạn tại [README.md](README.md). Vai trò SALES tập
> trung vào việc bán phòng — tạo, sửa, hủy booking và quản lý giá bán.
> SALES không thao tác vận hành tại quầy (không nhận/trả phòng, không
> thu tiền, không dọn phòng).

## Menu bạn thấy

**Tổng quan**, **Đặt phòng**, **Kiểm tra phòng**, **Giá phòng**,
**Nhật ký**.

## 1. Kiểm tra phòng trống trước khi chào bán

Vào menu **Kiểm tra phòng** (`/admin/room-availability`) để tra cứu
loại phòng còn trống theo khoảng ngày trước khi báo giá/nhận đặt của
khách.

## 2. Tạo booking mới

1. Vào menu **Đặt phòng** (`/admin/bookings`) → bấm nút tạo mới.
2. Điền thông tin khách (họ tên, liên hệ), ngày nhận/trả phòng dự
   kiến, loại phòng và bảng giá áp dụng, số lượng phòng theo từng loại.
3. Lưu lại — booking được tạo, chưa xếp phòng cụ thể và chưa nhận
   phòng (đó là việc của Lễ tân sau này).

## 3. Sửa / thêm nhu cầu phòng cho booking

Từ danh sách **Đặt phòng**, mở menu thao tác của một booking:
- **Sửa** — chỉnh lại thông tin khách, ngày ở.
- **Thêm nhu cầu phòng** — bổ sung thêm loại phòng/số lượng phòng cần
  cho cùng một booking (ví dụ khách đặt thêm phòng sau).
- **Thêm đặt cọc** — ghi nhận khoản đặt cọc/tạm ứng của khách vào
  booking (mở tab **Tài chính** để thêm khoản thanh toán).

## 4. Hủy / khôi phục booking

- **Hủy booking** — dùng khi khách hủy đặt phòng; hệ thống yêu cầu
  nhập lý do hủy.
- **Khôi phục booking** — dùng để phục hồi một booking đã bị hủy nhầm
  hoặc khách đổi ý đặt lại.

## 5. Quản lý giá phòng

Vào menu **Giá phòng** (`/admin/room-rates`) để xem/điều chỉnh bảng
giá theo loại phòng, dùng làm căn cứ báo giá khi tạo booking.

## 6. Nhật ký thao tác

Menu **Nhật ký** cho phép tra lại lịch sử ai đã tạo/sửa/hủy booking
nào, vào lúc nào — hữu ích khi cần đối chiếu với khách hoặc nội bộ.

## Bạn KHÔNG làm được

- Không xếp phòng cụ thể, không nhận phòng (**Check-in**)/trả phòng
  (**Check-out**) — việc của Lễ tân.
- Không thêm phí dịch vụ, không thu tiền qua tab Tài chính ngoài việc
  ghi nhận đặt cọc ban đầu của booking.
- Không thấy menu Dọn phòng, Kiểm đồ trả phòng, Night Audit, Doanh
  thu, Đối soát.
- Không quản lý phòng/tầng/loại phòng, không cấu hình hệ thống.
