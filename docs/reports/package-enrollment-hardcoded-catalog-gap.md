# Issue: Booking Package Enrollment vẫn dùng catalog cứng, không đọc `service_packages`

**Status:** OPEN — DEFERRED TO SEPARATE TASK
**Phát hiện trong:** phiên làm việc Room Demand/Room Board Unification Milestone 3 (Browser Manual QA), khi Product Owner hỏi tại sao một gói đã bị tắt (`is_active=false`) vẫn hiện và vẫn đăng ký được trong booking.
**Liên quan Milestone 3:** **Không.** Đây là gap độc lập của tính năng "Dynamic Service Package Admin" — không có file nào của issue này nằm trong diff Milestone 3 (đã xác nhận qua `git status --short`).

## Mô tả

Gói đã `is_bookable=false` hoặc `is_active=false` (tắt ở trang quản trị `/admin/service-packages`) **vẫn xuất hiện và vẫn có thể đăng ký được** trong phần Package Enrollment của booking, vì luồng đăng ký gói cho booking hiện vẫn dùng một catalog **viết cứng trong code**, chưa từng được nối vào bảng `service_packages`.

## Bằng chứng / vị trí code

1. `app/Http/Controllers/Admin/PackageEnrollmentController.php:119-151` — `buildAvailablePackages()` xây danh sách gói hiển thị từ một mảng `$packageMap` viết cứng (3 key: `BREAKFAST_PER_NIGHT`, `EXTRA_PERSON_PER_NIGHT`, `EXTRA_BED_PER_NIGHT`), không query `ServicePackage` model, nên không bao giờ biết tới `is_active`/`is_bookable`.
2. `app/Services/PackageEnrollmentService.php:19-23` — `ALLOWED_PACKAGES` cũng là hằng số cứng; `PackageEnrollmentController::enroll()` (dòng 78-100) validate `package_key` bằng `Rule::in(PackageEnrollmentService::ALLOWED_PACKAGES)` — nghĩa là **cả tầng backend** cũng không chặn đăng ký gói đã tắt, không chỉ riêng UI.
3. Gap này đã được chính báo cáo Milestone 2 của tính năng Dynamic Service Package Admin ghi nhận từ trước nhưng chưa triển khai: `docs/reports/dynamic-service-package-admin-milestone-2-report.md:142` — *"Cần: đổi `PackageEnrollmentController::buildAvailablePackages()` đọc `ServicePackage::active()->bookable()` thay `$packageMap` cứng; đổi `Packages.vue`/`PackagePanel.vue` bỏ label cứng"*.

## Tác động

- Trang quản trị `/admin/service-packages` (bật/tắt gói, quản lý giá) và luồng đăng ký gói thực tế trên booking đang chạy **song song, độc lập** — thay đổi ở trang quản trị không ảnh hưởng gì tới những gì lễ tân/sales thấy và chọn được khi đăng ký gói cho khách.
- Rủi ro: một gói bị vô hiệu hoá (vì lý do giá sai, ngừng cung cấp...) vẫn có thể bị đăng ký nhầm cho khách.

## Phạm vi sửa (khi được giao task riêng, KHÔNG làm trong task này)

- `PackageEnrollmentController::buildAvailablePackages()` → đọc `ServicePackage::active()->bookable()->orderBy('display_order')->get()` thay `$packageMap` cứng.
- `PackageEnrollmentController::enroll()` → validate `package_key` dựa trên `ServicePackage::active()->bookable()` (không chỉ dựa `ALLOWED_PACKAGES` cứng), để backend tự chặn kể cả khi UI chưa kịp cập nhật.
- Khả năng cần sửa thêm `Packages.vue`/`PackagePanel.vue` nếu có label cứng phía frontend tương ứng.
- Cần rà soát 3 Posting Job (breakfast/extra person/extra bed) xem có phụ thuộc cùng kiểu hard-code hay không (đã được ghi chú mở trong milestone-2 report, dòng 146, cũng chưa trả lời).

## Xác nhận không phải regression của Milestone 3

- Đã kiểm tra `git status --short`: không file nào của `PackageEnrollmentController.php`, `PackageEnrollmentService.php`, `PackagePanel.vue`, `ServicePackage*` xuất hiện trong diff hiện tại.
- Milestone 3 (Room Demand/Room Board Unification) không đụng tới bất kỳ code Service Package/Package Enrollment nào ở bất kỳ giai đoạn nào của quá trình implement.
- Gap này tồn tại từ trước, độc lập với nhánh `phase-3` hiện tại và với toàn bộ công việc Milestone 1-3 của Room Demand/Room Board.

## Quyết định

Theo yêu cầu Product Owner: **không sửa trong task hiện tại**, ghi nhận lại để xử lý ở task riêng sau này.
