# Hướng dẫn cho vai trò ADMIN (Quản trị viên)

> Xem tổng quan quyền hạn tại [README.md](README.md). Admin có **toàn
> quyền** trên hệ thống: mọi thao tác nghiệp vụ của các vai trò khác,
> cộng thêm quản lý tài khoản, phân quyền, và cấu hình khách sạn cấp
> hệ thống.

## Menu bạn thấy

Toàn bộ menu, bao gồm cả **Người dùng**, **Vai trò**, **Quyền**.

## Quyền kế thừa

Admin làm được **tất cả** thao tác mô tả ở
[05-quan-ly.md](05-quan-ly.md) (và qua đó, mọi thao tác của
[01-sales.md](01-sales.md), [02-le-tan.md](02-le-tan.md),
[03-buong-phong.md](03-buong-phong.md),
[04-ke-toan.md](04-ke-toan.md)). Phần dưới đây chỉ liệt kê những gì
**chỉ Admin** làm được.

## 1. Quản lý người dùng

Menu **Người dùng** (`/users`):
- Tạo tài khoản mới cho nhân viên (email đăng nhập, mật khẩu ban
  đầu, họ tên).
- Sửa thông tin tài khoản, gán/đổi **vai trò** cho tài khoản (một
  người có thể giữ nhiều vai trò cùng lúc).
- Vô hiệu hóa (khóa mềm) tài khoản khi nhân viên nghỉ việc thay vì
  xóa hẳn, để giữ lại lịch sử thao tác của người đó trong hệ thống.

## 2. Quản lý vai trò & quyền

- Menu **Vai trò** (`/roles`) — xem/tạo vai trò, và với mỗi vai trò
  chọn tập quyền cụ thể được gán (dựa trên danh sách quyền hệ thống).
- Menu **Quyền** (`/permissions`) — xem toàn bộ danh sách quyền hạn
  rời rạc mà hệ thống hỗ trợ (ví dụ `booking.create`, `payment.create`,
  `folio.close`, `night_audit.run`...), dùng làm cơ sở khi tạo/sửa vai
  trò ở trên.

> Thận trọng khi sửa vai trò đang có người dùng: thay đổi quyền của
> một vai trò ảnh hưởng ngay lập tức đến **mọi tài khoản** đang giữ
> vai trò đó.

## 3. Cấu hình khách sạn cấp hệ thống

Ngoài trang **Cài đặt** chung (`/settings`, Quản lý cũng vào được),
Admin có thêm trang cấu hình nâng cao tại `/admin/hotel-settings`
(hiện chưa có mục riêng trên menu chính, truy cập trực tiếp bằng
đường dẫn hoặc qua liên kết nội bộ), gồm các nhóm:

| Nhóm | Thiết lập |
|---|---|
| Ngày kế toán & Kiểm toán đêm | Giờ offset ngày kế toán, cửa sổ giờ được phép chạy Night Audit (bắt đầu/kết thúc), yêu cầu chạy tuần tự |
| Phí nhận/trả phòng | Ân hạn trả phòng muộn (phút), ân hạn nhận phòng sớm (phút) |
| Tiền tệ | Mã tiền tệ, số chữ số thập phân hiển thị |

Đây là các tham số ảnh hưởng đến cách hệ thống tự vận hành (ví dụ giờ
nào được coi là "ngày kinh doanh mới", có tính phí trả phòng muộn hay
không) — nên thay đổi thận trọng, tốt nhất là ngoài giờ vận hành cao
điểm.

## 4. Nhật ký hệ thống (giám sát toàn bộ)

Admin (cũng như Quản lý) dùng menu **Nhật ký** để tra cứu lịch sử thay
đổi trên mọi đối tượng nghiệp vụ (booking, thanh toán, hóa đơn, phân
phòng, dọn phòng, kiểm đồ...) — lọc theo hành động, người thực hiện,
loại đối tượng, thời gian. Đây là công cụ chính để truy vết khi có
tranh chấp hoặc cần điều tra một sai sót vận hành.

## Trách nhiệm đặc thù của Admin

- Là người **duy nhất** cấp/thu hồi quyền truy cập hệ thống — cần xử
  lý yêu cầu tạo/khóa tài khoản kịp thời khi nhân sự thay đổi.
- Là người chịu trách nhiệm cấu hình đúng thông tin vận hành cấp hệ
  thống trước khi đưa vào sử dụng thật (xem thêm quy trình chuẩn bị
  pilot tại `docs/pilot/`).
