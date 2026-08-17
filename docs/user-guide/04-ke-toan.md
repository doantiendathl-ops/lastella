# Hướng dẫn cho vai trò ACCOUNTANT (Kế toán)

> Xem tổng quan quyền hạn tại [README.md](README.md). Vai trò Kế toán
> tập trung vào thu tiền, theo dõi hóa đơn, báo cáo doanh thu và đối
> soát công nợ. Không tạo/sửa booking, không thêm phí dịch vụ.

## Menu bạn thấy

**Tổng quan**, **Đặt phòng** (chỉ xem), **Kiểm tra phòng**,
**Night Audit** (chỉ xem), **Doanh thu**, **Đối soát**, **Nhật ký**.

## 1. Thu tiền cho booking

1. Vào menu **Đặt phòng**, mở booking cần thu tiền.
2. Vào tab **Tài chính** để xem toàn bộ hóa đơn (folio): các khoản phí
   đã ghi và các khoản đã thanh toán, số dư còn nợ.
3. Bấm **Thêm thanh toán**, nhập số tiền/hình thức thanh toán, lưu lại.

Kế toán **không** thêm được khoản phí (charge) mới vào hóa đơn — việc
ghi phí dịch vụ/phòng thuộc về Lễ tân hoặc được Night Audit tự động
ghi. Kế toán cũng không xóa thanh toán, không hủy (void) dòng phí,
không đóng/mở lại hóa đơn.

## 2. Theo dõi Night Audit

Vào menu **Night Audit** để xem danh sách các lượt chạy kiểm toán đêm
theo ngày kinh doanh, mở chi tiết một lượt chạy để xem số lượt ở đã xử
lý, số khoản đã ghi, số khoản bỏ qua, số khoản lỗi. Kế toán chỉ **xem**
— không tự chạy hay thử lại (**Thử lại**) một lượt chạy lỗi; việc đó
do Quản lý/Admin thực hiện.

## 3. Báo cáo Doanh thu

Menu **Doanh thu** (`/admin/reports/revenue`) hiển thị doanh thu theo
nguồn và theo loại phí trong khoảng thời gian chọn, có thể xuất báo
cáo (CSV) để lưu trữ/đối chiếu.

## 4. Đối soát

Menu **Đối soát** (`/admin/reconciliation`) gồm:
- Danh sách **công nợ còn lại** (booking còn số dư chưa thu).
- Danh sách **khoản đã hủy (void)** — các dòng phí bị Quản lý/Admin
  hủy, để kế toán đối chiếu số liệu không bị lệch.

Cả hai đều xuất được báo cáo để phục vụ đối soát cuối ngày/cuối kỳ.

## Bạn KHÔNG làm được

- Không tạo/sửa/hủy booking, không xếp phòng, không nhận/trả phòng.
- Không thêm phí dịch vụ vào hóa đơn, không xóa thanh toán, không hủy
  dòng phí, không đóng/mở lại hóa đơn.
- Không tự chạy/thử lại Night Audit.
- Không xem/thao tác Dọn phòng, Kiểm đồ trả phòng.
- Không quản lý phòng/tầng/loại phòng/giá phòng, không quản lý người
  dùng hay cài đặt hệ thống.
