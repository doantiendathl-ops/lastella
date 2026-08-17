# Hướng dẫn sử dụng phần mềm Lastella PMS

Tài liệu này hướng dẫn thao tác trên phần mềm quản lý khách sạn Lastella
theo từng vai trò (role) người dùng. Mỗi vai trò có một file riêng, chỉ
mô tả đúng những gì vai trò đó nhìn thấy và được phép làm trên hệ thống.

> Tài liệu được viết dựa trên đúng code hiện tại của hệ thống (tên menu,
> tên nút, tên tab lấy nguyên văn từ giao diện). Nếu sau này giao diện
> thay đổi, cần cập nhật lại tài liệu tương ứng.

## 1. Đăng nhập

1. Truy cập địa chỉ hệ thống (`APP_URL` do quản trị viên cung cấp).
2. Nhập **Email** và **Mật khẩu** được cấp, bấm **Đăng nhập**.
3. Sau khi đăng nhập, hệ thống hiển thị menu bên trái — menu chỉ hiện
   những mục bạn có quyền dùng (xem bảng vai trò bên dưới).
4. Quên mật khẩu hoặc cần cấp/đổi tài khoản: liên hệ **Quản trị viên**
   (Admin) — chỉ Admin mới tạo/sửa/khoá tài khoản người dùng.

## 2. Sáu vai trò trong hệ thống

| Vai trò | Vai trò trong khách sạn | Tài liệu chi tiết |
|---|---|---|
| **SALES** | Kinh doanh / đặt phòng (bán phòng, không vận hành tại quầy) | [01-sales.md](01-sales.md) |
| **RECEPTION** | Lễ tân — vận hành hàng ngày tại quầy | [02-le-tan.md](02-le-tan.md) |
| **HOUSEKEEPING** | Buồng phòng — dọn phòng, kiểm phòng | [03-buong-phong.md](03-buong-phong.md) |
| **ACCOUNTANT** | Kế toán — thu ngân, báo cáo, đối soát | [04-ke-toan.md](04-ke-toan.md) |
| **MANAGER** | Quản lý — toàn quyền vận hành + cấu hình nghiệp vụ | [05-quan-ly.md](05-quan-ly.md) |
| **ADMIN** | Quản trị hệ thống — toàn quyền, kể cả tài khoản & cài đặt | [06-quan-tri-vien.md](06-quan-tri-vien.md) |

Một người dùng có thể được gán nhiều vai trò cùng lúc (ví dụ vừa
MANAGER vừa SALES) — khi đó menu và quyền là **hợp của tất cả vai trò**
được gán.

## 3. Menu bạn sẽ thấy theo từng vai trò

Cột "✓" nghĩa là menu đó xuất hiện trong thanh điều hướng bên trái với
vai trò tương ứng.

| Menu | SALES | RECEPTION | HOUSEKEEPING | ACCOUNTANT | MANAGER | ADMIN |
|---|---|---|---|---|---|---|
| Tổng quan | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Đặt phòng | ✓ | ✓ | | ✓ (chỉ xem/báo cáo) | ✓ | ✓ |
| Kiểm tra phòng | ✓ | ✓ | | ✓ | ✓ | ✓ |
| Dọn phòng | | ✓ | ✓ | | ✓ | ✓ |
| Kiểm đồ trả phòng | | ✓ | ✓ | | ✓ | ✓ |
| Night Audit | | | | ✓ (chỉ xem) | ✓ (chạy được) | ✓ |
| Doanh thu | | | | ✓ | ✓ | ✓ |
| Đối soát | | | | ✓ | ✓ | ✓ |
| Giá phòng | ✓ | | | | ✓ | ✓ |
| Tầng / Loại phòng / Phòng | | | | | ✓ | ✓ |
| Sản phẩm/Dịch vụ | | | | | ✓ | ✓ |
| Cài đặt | | | | | ✓ | ✓ |
| Nhật ký (Audit log) | ✓ | ✓ | | ✓ | ✓ | ✓ |
| Người dùng / Vai trò / Quyền | | | | | | ✓ |

Ghi chú:
- **RECEPTION** thấy menu Đặt phòng, Dọn phòng, Kiểm đồ trả phòng nhưng
  các thao tác nhận/trả phòng, thanh toán, ghi nhận yêu cầu đặc biệt...
  đều nằm **bên trong trang chi tiết một booking**, không phải menu
  riêng — xem chi tiết ở [02-le-tan.md](02-le-tan.md).
- **HOUSEKEEPING** không thấy menu Đặt phòng/Kiểm tra phòng — vai trò
  này chỉ làm việc trên bảng Dọn phòng và Kiểm đồ trả phòng.
- **ACCOUNTANT** thấy Đặt phòng nhưng không có quyền tạo/sửa/hủy booking
  — chỉ xem và làm việc ở tab Tài chính (thu tiền) trong từng booking.
- **MANAGER** thấy gần như mọi menu **trừ** "Người dùng / Vai trò /
  Quyền" và không cấu hình được thông tin khách sạn cấp cao (chỉ
  ADMIN mới có quyền `hotel_settings.manage`).

## 4. Luồng nghiệp vụ tổng thể (một lượt khách ở)

```
1. SALES/RECEPTION tạo booking (Đặt phòng)
        │
2. RECEPTION xếp phòng cho booking (tab "Sơ đồ phòng")
        │
3. RECEPTION bấm "Nhận phòng" khi khách đến (Check-in)
        │  (trong lúc ở: có thể "Gia hạn lưu trú" / "Đổi phòng",
        │   RECEPTION ghi Yêu cầu đặc biệt, thêm phí dịch vụ vào Tài chính)
        │
4. Mỗi đêm: Night Audit tự chạy/được MANAGER chạy thủ công
   → tự động ghi phí phòng đêm đó vào Tài chính của từng booking
        │
5. Trước khi khách trả phòng: HOUSEKEEPING/RECEPTION mở
   "Kiểm đồ trả phòng" để kiểm minibar/đồ dùng phát sinh
        │
6. RECEPTION/ACCOUNTANT thu đủ tiền còn nợ trên tab Tài chính
        │
7. RECEPTION bấm "Trả phòng" (Check-out)
   → hệ thống bắt buộc xác nhận lần cuối, chặn nếu còn nợ tiền
        │
8. HOUSEKEEPING chuyển phòng "Chờ dọn" → "Đang dọn" → "Đã xong"
   → kiểm phòng "Đạt"/"Không đạt" → phòng trở lại trạng thái sẵn sàng
        │
9. MANAGER/ACCOUNTANT xem Doanh thu, Đối soát để tổng kết
```

## 5. Quy ước trong tài liệu

- Tên nút/menu được viết **in đậm**, đúng nguyên văn hiển thị trên
  giao diện (ví dụ **Nhận phòng**, **Đổi phòng**, **Chờ dọn**).
- Đường dẫn URL được viết dạng `mã` (ví dụ `/admin/bookings`) chỉ để
  tham khảo — bình thường bạn luôn thao tác qua menu, không cần gõ URL.
- Mục "Bạn KHÔNG làm được" trong mỗi file liệt kê rõ giới hạn quyền,
  để tránh nhầm lẫn khi thao tác không thấy nút mong đợi.
