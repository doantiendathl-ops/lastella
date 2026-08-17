# Hướng dẫn cho vai trò HOUSEKEEPING (Buồng phòng)

> Xem tổng quan quyền hạn tại [README.md](README.md). Vai trò Buồng
> phòng chịu trách nhiệm dọn phòng, kiểm tra chất lượng phòng sau khi
> dọn, và kiểm đồ khi khách trả phòng. Không thao tác booking/tài
> chính.

## Menu bạn thấy

**Tổng quan**, **Dọn phòng**, **Kiểm đồ trả phòng**.

## 1. Bảng Dọn phòng

Menu **Dọn phòng** (`/admin/housekeeping`) hiển thị toàn bộ phòng theo
từng tầng, kèm trạng thái hiện tại. Với mỗi phòng, tùy trạng thái hiện
tại, hệ thống hiện các nút thao tác phù hợp:

| Nút | Ý nghĩa | Khi nào dùng |
|---|---|---|
| **Chờ dọn** | Giao phòng cho một nhân viên buồng phòng | Phòng đang bẩn (trống-bẩn), cần phân công người dọn |
| **Đang dọn** | Bắt đầu dọn | Sau khi đã được giao, nhân viên bắt đầu vào dọn |
| **Đã xong** | Hoàn tất dọn phòng | Dọn xong, chuyển sang chờ kiểm tra |
| **Đạt** | Kiểm tra đạt chất lượng | Sau khi dọn xong, kiểm tra thấy phòng đạt yêu cầu → phòng sẵn sàng đón khách |
| **Không đạt** | Kiểm tra không đạt | Phòng chưa đạt yêu cầu → quay lại trạng thái cần dọn/đang dọn |
| **Bỏ qua** | Bỏ qua bước kiểm tra | Dùng khi không cần kiểm tra riêng, coi như đã đạt |
| **Khóa bảo trì** | Đưa phòng vào diện bảo trì/hỏng | Phòng gặp sự cố kỹ thuật, không thể đón khách |
| **Mở khóa** | Gỡ trạng thái bảo trì | Phòng đã sửa xong, trả về trạng thái vận hành bình thường |

Có thể thao tác **hàng loạt** (chọn nhiều phòng cùng lúc) cho các
bước Chờ dọn / Đang dọn / Đã xong / đánh dấu sạch-bẩn khi cần dọn
nhiều phòng cùng lúc (ví dụ đầu ca).

## 2. Quy trình dọn một phòng — tóm tắt

```
Trống-bẩn ── Chờ dọn ──▶ Đang dọn ──▶ Đã xong ──▶ Kiểm tra
                                                     │
                                        ┌────────────┴────────────┐
                                       Đạt                     Không đạt
                                        │                          │
                                   Trống-sạch              quay lại Chờ dọn/Đang dọn
```

Phòng đang **Khóa bảo trì** không tham gia quy trình dọn phòng bình
thường cho đến khi được **Mở khóa**.

## 3. Yêu cầu đặc biệt của khách

Nếu Lễ tân đã ghi nhận yêu cầu đặc biệt cho một booking (ví dụ: thêm
giường phụ, trang trí sinh nhật, nôi em bé...), Buồng phòng vào phần
**Yêu cầu** trong booking liên quan để đánh dấu **hoàn tất** sau khi
đã thực hiện xong yêu cầu tại phòng.

## 4. Kiểm đồ trả phòng

Menu **Kiểm đồ trả phòng** (`/admin/checkout-inspections`) liệt kê các
lượt ở sắp/đang trả phòng trong ngày:

1. Bấm **Kiểm đồ** trên phòng cần kiểm.
2. Tick các minibar/đồ dùng khách đã sử dụng/làm hỏng theo danh mục
   sản phẩm/dịch vụ có sẵn.
3. Lưu nháp nếu chưa kiểm xong (trạng thái **Đang kiểm**), hoặc hoàn
   tất phiếu — hệ thống tự ghi phí phát sinh (nếu có) vào hóa đơn của
   booking (**Có phát sinh** / **Không phát sinh**).

## Bạn KHÔNG làm được

- Không tạo/sửa/hủy booking, không xếp phòng, không nhận/trả phòng.
- Không thêm phí hay thu tiền, không xem tab Tài chính của booking.
- Không xem menu Kiểm tra phòng trống, Night Audit, Doanh thu, Đối
  soát.
- Không quản lý phòng/tầng/loại phòng/giá phòng, không quản lý người
  dùng hay cài đặt hệ thống.
