# Room Operations & Checkout Inspection — Implementation Report

Branch: `phase-3` · Not committed · Not pushed

## 1. Tóm tắt phạm vi đã triển khai

| # | Hạng mục | Trạng thái |
|---|---|---|
| 1 | "Phòng" → Room Board (theo tầng) + chọn nhiều phòng + bulk maintenance | ✅ Hoàn thành |
| 2 | "Dọn phòng" → Room Board (theo tầng) + popup Chi tiết + bulk housekeeping | ✅ Hoàn thành |
| 3 | Kiểm đồ nhanh khi trả phòng (Room Board + popup) | ✅ Hoàn thành |
| 4 | Danh mục quản trị "Sản phẩm và dịch vụ" (thay hard-code) | ✅ Hoàn thành |
| 5 | Thêm sản phẩm/dịch vụ vào booking/folio | ✅ Hoàn thành |
| 6 | Chọn/xử lý nhiều phòng (cả tầng / tất cả đủ điều kiện) | ✅ Hoàn thành (Phòng + Dọn phòng) |
| 7 | Cảnh báo kiểm đồ tại checkout + bỏ qua có quyền/lý do | ✅ Hoàn thành (thiết kế lại an toàn hơn — xem Mục 18) |

Toàn bộ 14 bước theo Mục XIV đã thực hiện tuần tự. Tổng cộng **910 test pass / 23 fail** trên toàn bộ suite — 23 lỗi fail đều được xác minh là lỗi có sẵn từ trước (pre-existing), không liên quan đến thay đổi trong đợt này (xem Mục 18).

## 2. Danh sách file đã tạo

**Migrations (5):**
- `database/migrations/2026_07_26_000000_create_product_service_categories_table.php`
- `database/migrations/2026_07_26_000010_create_product_services_table.php`
- `database/migrations/2026_07_26_000020_create_checkout_inspections_table.php`
- `database/migrations/2026_07_26_000030_create_checkout_inspection_items_table.php`
- `database/migrations/2026_07_26_000040_add_inspection_skip_to_stays_table.php`

**Enums:** `app/Enums/ProductServiceType.php`, `app/Enums/CheckoutInspectionStatus.php`

**Models:** `app/Models/ProductServiceCategory.php`, `app/Models/ProductService.php`, `app/Models/CheckoutInspection.php`, `app/Models/CheckoutInspectionItem.php`

**Policies:** `app/Policies/ProductServiceCategoryPolicy.php`, `app/Policies/ProductServicePolicy.php`, `app/Policies/CheckoutInspectionPolicy.php`

**Services:** `app/Services/ProductServiceCategoryService.php`, `app/Services/CheckoutInspectionService.php`

**Repository:** `app/Repositories/Eloquent/ProductServiceCategoryRepository.php`

**Controllers:** `ProductServiceCategoryController`, `ProductServiceController`, `CheckoutInspectionController`, `RoomBulkActionController`, `HousekeepingBulkActionController` (tất cả `app/Http/Controllers/Admin/`)

**Concern (dùng chung):** `app/Http/Controllers/Concerns/RunsPerRoomBulkAction.php`

**Form Requests:** `BulkRoomOutOfOrderRequest`, `BulkRoomReleaseRequest`, `BulkHousekeepingActionRequest`, `BulkHousekeepingTransitionRequest`, `SaveCheckoutInspectionRequest`, `StoreProductServiceCategoryRequest`, `UpdateProductServiceCategoryRequest`, `StoreProductServiceRequest`, `UpdateProductServiceRequest` (`app/Http/Requests/Admin/`), `app/Http/Requests/Booking/SkipCheckoutInspectionRequest.php`

**Seeders:** `database/seeders/ProductServiceCategorySeeder.php`, `database/seeders/ProductServiceSeeder.php`

**Vue — component dùng chung:** `resources/js/Components/RoomBoard/RoomBoardGrid.vue`, `RoomBulkActionBar.vue`

**Vue — trang mới:**
- `resources/js/Pages/Admin/Rooms/Index.vue` (Room Board cho "Phòng")
- `resources/js/Pages/Admin/CheckoutInspections/Index.vue` + `Partials/CheckoutInspectionModal.vue`
- `resources/js/Pages/Admin/ProductServices/Index.vue`
- `resources/js/Pages/Admin/Housekeeping/Partials/HousekeepingDetailModal.vue`

**Test mới (7 file, 42 test case):** `ProductServiceCrudTest`, `CheckoutInspectionServiceTest`, `CheckoutInspectionControllerTest`, `RoomBulkActionTest`, `HousekeepingBulkActionTest`, `BookingProductServiceChargeTest`, `CheckoutInspectionSkipTest` (`tests/Feature/`)

## 3. Danh sách file đã sửa

`app/Enums/StayEventType.php` (+2 case), `app/Http/Controllers/Admin/Booking/BookingController.php`, `app/Http/Controllers/Admin/Booking/StayController.php`, `app/Http/Controllers/Admin/HousekeepingController.php`, `app/Http/Controllers/Admin/RoomController.php`, `app/Http/Middleware/HandleInertiaRequests.php` (fix nhỏ, xem Mục 21), `app/Models/Booking.php`, `app/Models/Room.php`, `app/Models/Stay.php`, `app/Policies/RoomPolicy.php`, `app/Providers/AppServiceProvider.php`, `app/Services/StayService.php`, `database/seeders/DatabaseSeeder.php`, `database/seeders/RolePermissionSeeder.php`, `routes/web.php`, `resources/js/Layouts/AppLayout.vue`, `resources/js/Pages/Admin/Bookings/Partials/AddChargeForm.vue`, `FolioPanel.vue`, `RoomBoardPanel.vue`, `Show.vue`, `resources/js/Pages/Admin/Housekeeping/Index.vue`, `tests/Feature/HousekeepingControllerTest.php` (2 assertion cập nhật theo cấu trúc `floors` mới), `tests/Unit/Seeders/RolePermissionSeederTest.php` (1 assertion cập nhật số quyền HOUSEKEEPING).

## 4. Migration và table mới

| Table | Mục đích | Ràng buộc chính |
|---|---|---|
| `product_service_categories` | Nhóm SP/DV (Minibar, Ăn uống...) | unique(code) |
| `product_services` | Catalog SP/DV | unique(code), FK category nullOnDelete, index (is_active,sort_order), (use_in_checkout_inspection,is_active), (can_add_to_booking,is_active) |
| `checkout_inspections` | Phiếu kiểm đồ, 1 phiếu/stay | **unique(stay_id)** — chống tạo phiếu trùng cho cùng 1 phòng đang trả; unique(posting_batch_key) |
| `checkout_inspection_items` | Dòng chi tiết, có snapshot | index (checkout_inspection_id), (product_service_id) |
| `stays` (ALTER) | +3 cột `inspection_skipped_at/by`, `inspection_skip_reason` | FK skipped_by → users nullOnDelete |

Tất cả migration có `down()` hợp lệ, không sửa/xóa dữ liệu production hiện có, foreign key theo đúng convention hiện tại của dự án (booking_id cascadeOnDelete, room_id/stay_id restrictOnDelete, các cột `*_by` nullOnDelete).

## 5. Model và relationship mới

- `ProductServiceCategory` `hasMany` `ProductService`
- `ProductService` `belongsTo` category/createdBy/updatedBy; scope `active()`, `usableInCheckoutInspection()`, `addableToBooking()`; method `resolveChargeType()` (map category → `ChargeType` kế toán hiện có — dùng chung cho cả kiểm đồ lẫn thêm-vào-booking, tránh trùng lặp logic)
- `CheckoutInspection` `belongsTo` booking/stay/room/folio/createdBy/completedBy; `hasMany` items
- `CheckoutInspectionItem` `belongsTo` inspection, productService
- `Stay` `hasOne` `checkoutInspection`; `belongsTo` `inspectionSkippedBy`
- `Room`, `Booking` `hasMany` `checkoutInspections` (tiện truy vấn)

## 6. Route/API mới

```
GET|POST|PATCH  /product-service-categories...         (resource, generic CrudIndex/CrudForm)
GET|POST|PATCH  /product-services...                    (trang riêng, toggle-active)
PATCH  /rooms/bulk/out-of-order
PATCH  /rooms/bulk/release
GET    /admin/checkout-inspections
POST   /admin/checkout-inspections/stays/{stay}/draft
PATCH  /admin/checkout-inspections/{inspection}/save
POST   /admin/checkout-inspections/{inspection}/complete
GET    /admin/housekeeping/{room}/detail
PATCH  /admin/housekeeping/bulk/assign|start|complete
POST   /admin/bookings/{booking}/stays/{stay}/inspection-skip
```

Không route/permission nào bị đổi tên hay xóa; toàn bộ route cũ giữ nguyên.

## 7. Component/page mới

Xem Mục 2. Điểm nhấn: `RoomBoardGrid.vue` là component Room Board dùng chung duy nhất (nhận `floors` + scoped slot `#card`), được cả 3 màn Phòng / Dọn phòng / Kiểm đồ nhanh tái sử dụng — không có 3 bản Room Board độc lập.

## 8. Các phần đã tái sử dụng từ Room Board / hệ thống hiện có

- **Floor-grouping pattern**: sao chép chính xác cách `RoomAvailabilityController` eager-load `Floor::with('rooms')` rồi group — dùng lại cho Phòng, Dọn phòng, Kiểm đồ.
- **`roomStatusBadges.js`**: giữ nguyên, không tạo bảng màu mới cho Dọn phòng.
- **Bulk-action pattern**: `RunsPerRoomBulkAction` trait dùng chung cho cả `RoomBulkActionController` và `HousekeepingBulkActionController` — tránh copy-paste vòng lặp xử lý per-room.
- **`HousekeepingService::markOutOfOrder/releaseFromOutOfOrder/assignRoom/startCleaning/completeCleaning`**: bulk action gọi lại **nguyên vẹn** các method này theo từng phòng — không viết logic nghiệp vụ mới, chỉ orchestrate.
- **`FolioService::addCharge`**: cả kiểm đồ lẫn "thêm SP/DV vào booking" đều post phí qua đúng pipeline này (không tạo hệ thống tài chính song song).
- **`StayEventService`**: kiểm đồ hoàn tất / bỏ qua kiểm đồ ghi `StayEvent` mới (`InspectionCompleted`, `InspectionSkipped`) theo đúng pattern audit hiện có.
- **`AuditObserver`**: tất cả model mới (ProductService*, CheckoutInspection*) được đăng ký observe như các model khác — tự động có audit log.
- **`AddChargeForm.vue`**: mở rộng thêm khối "Sản phẩm/dịch vụ" cạnh khối "Dịch vụ nhanh" (ServiceRate) sẵn có, cùng submit vào 1 route cũ.

## 9. Cách xử lý bulk room actions

Frontend gửi mảng `room_ids`; backend **không tin dữ liệu này** — với mỗi ID: fetch lại Room mới nhất, gọi service method đã có guard/lock riêng, bắt exception per-room, gom kết quả `{succeeded: [...], failed: [{room_id, room_number, reason}]}`. Một phòng lỗi không làm mất kết quả các phòng khác (đã test: `test_bulk_action_reports_per_room_failure_without_losing_other_successes`).

## 10. Cách loại trừ phòng bảo trì/sửa chữa

Backend trả `is_eligible_for_bulk = false` cho phòng `OUT_OF_ORDER`/`OUT_OF_SERVICE`. Frontend: nút "Chọn tất cả phòng đủ điều kiện" và "Chọn theo tầng" chỉ thêm phòng `is_eligible_for_bulk`. Chọn từng phòng thủ công vẫn được phép (để có thể chọn đúng phòng đang bảo trì nhằm "Mở lại sau bảo trì"). Backend luôn re-check trạng thái thật dưới lock, không tin cờ `is_eligible_for_bulk` từ client.

## 11. Cách chống ghi phí trùng (kiểm đồ)

- `checkout_inspections.stay_id` **unique** → 1 stay chỉ có 1 phiếu (idempotent tạo draft).
- `CheckoutInspectionService::complete()` khóa row phiếu (`lockForUpdate`) trước khi kiểm tra `isPosted()`; gọi lại lần 2 (double-click/retry) → thấy `posted_at` đã có → trả về nguyên trạng, **không post lại**.
- Mỗi dòng phí có `posting_key = CHECKOUT_INSPECTION_{inspectionId}_{itemId}` (unique ở `folio_entries`) — lớp bảo vệ thứ hai ở tầng DB.
- Sau khi hoàn tất, `assertEditable()` chặn `saveDraft()`/sửa tiếp — chỉ có thể tạo phiếu mới nếu cần điều chỉnh (chưa build quy trình reverse đầy đủ — xem Mục 19).

Đã test: hoàn tất 2 lần liên tiếp không tạo 2 bộ phí (`test_completing_twice_does_not_duplicate_folio_charges`, `test_bulk...`).

## 12. Cách tích hợp với folio

`CheckoutInspectionService::complete()` và luồng "thêm SP/DV vào booking" đều gọi `FolioService::addCharge()` — server tự tính `amount = qty * unit_price` (không nhận từ client), gắn `posting_source = 'CHECKOUT_INSPECTION'` hoặc `'MANUAL'`, `stay_id`, snapshot tên sản phẩm vào `description`. `charge_type` được map từ nhóm sản phẩm qua `ProductService::resolveChargeType()` để tương thích báo cáo doanh thu hiện có.

## 13. Cách hỗ trợ booking nhiều phòng

Mỗi `CheckoutInspection` gắn `stay_id` + `room_id` cụ thể — kiểm đồ độc lập theo từng phòng trong cùng booking. Test `test_multi_room_booking_inspections_are_independent_and_scoped_per_room` xác nhận: kiểm đồ phòng A không ảnh hưởng phòng B, dòng phí gắn đúng `stay_id`/phòng.

## 14. Cách hỗ trợ partial checkout

Cảnh báo/bỏ qua kiểm đồ (Mục 18) chỉ áp dụng cho **1 stay cụ thể** đang được trả — không kiểm tra các stay khác còn lưu trú trong cùng booking. Test: `test_skipping_inspection_for_one_stay_does_not_affect_sibling_stay_in_same_booking`.

## 15. Phân quyền

Permission mới: `rooms.bulk_update`, `product_services.manage`, `checkout_inspection.view`, `checkout_inspection.perform`, `checkout_inspection.override`.

| Role | Bulk phòng | Quản lý SP/DV | Kiểm đồ (thực hiện) | Kiểm đồ (bỏ qua) |
|---|---|---|---|---|
| ADMIN | ✓ | ✓ | ✓ | ✓ |
| MANAGER | ✓ | ✓ | ✓ | ✓ |
| RECEPTION | — (room.maintenance riêng) | — (chỉ chọn khi thêm vào booking) | ✓ | ✗ |
| HOUSEKEEPING | — | — | ✓ | ✗ |

Reception có thể chọn sản phẩm/dịch vụ khi thêm vào booking (qua `charge.create` đã có) nhưng **không** vào được trang quản trị catalog (`product_services.manage`) và **không** sửa được đơn giá khi thêm SP/DV vào booking (ô đơn giá bị khóa trừ khi có `product_services.manage`).

## 16. Test đã chạy

- 7 file test mới, **42 test case, 149 assertion — 100% pass**.
- 2 file test cũ cập nhật assertion cho khớp cấu trúc mới (`Admin/Housekeeping/Index` props `rooms`→`floors`; số quyền HOUSEKEEPING 4→6) — **đã pass lại**.
- Toàn bộ suite: `php artisan test` → **910 passed, 23 failed** (xem Mục 18).

## 17. Kết quả test

```
Tests:    910 passed, 23 failed (3967 assertions)
```

## 18. Regression findings

**Lần chạy đầu tiên phát hiện 1 lỗi thiết kế nghiêm trọng do chính tôi gây ra**, đã sửa ngay trong phiên làm việc này (không phải bug sót lại):
- Thử nghiệm ban đầu chặn `StayService::checkOut()` bằng exception nếu chưa kiểm đồ → làm **30 test hiện có fail** (Checkout, Partial Checkout, Housekeeping hook, Late checkout fee...).
- **Đã thiết kế lại hoàn toàn**: `checkOut()` được revert nguyên trạng 100%, không còn bất kỳ tham chiếu nào tới kiểm đồ. Cảnh báo "chưa kiểm đồ" chuyển thành **cảnh báo phía client** (dựa trên `stay.inspection_status` đã có sẵn trong props), và "bỏ qua kiểm đồ" tách thành **action độc lập** `StayService::skipCheckoutInspection()` — không nằm trong transaction của `checkOut()`. Sau khi sửa: **0 test checkout nào fail** (44/44 pass).

**23 lỗi còn lại trên toàn bộ suite — đã xác minh là lỗi có sẵn từ trước, không liên quan đến đợt triển khai này:**
- 11 lỗi `BookingManagementUiTest` + 12 lỗi `RoomAvailabilityCheckerTest`, tất cả cùng một dạng: `roomBoard.floors` / `availability.floors` assertion sai lệch trên tính năng **Sơ đồ phòng hiện có** (`RoomAssignmentService::getRoomBoard`, `RoomAvailabilityCheckerService`) — những file/service này **không được đụng tới** trong đợt triển khai này.
- Đã xác minh bằng `git stash` (chạy lại đúng 2 file test này trên baseline sạch, chưa có bất kỳ thay đổi nào của tôi): **kết quả giống hệt — đúng 11 và đúng 12 lỗi, cùng thông điệp lỗi**. Kết luận: đây là lỗi tồn tại sẵn trong nhánh `phase-3` trước khi bắt đầu công việc này (nhiều khả năng liên quan mốc thời gian hard-code `2026-08-01`/`2026-08-02` trong test so với ngày hệ thống hiện tại), **không phải regression do sprint này gây ra**. Đề xuất tạo ticket riêng để điều tra, ngoài phạm vi sprint này.

## 19. Known limitations

- Chưa có quy trình "điều chỉnh phiếu kiểm đồ đã hoàn tất" đầy đủ (hủy dòng cũ + tạo dòng mới + lý do) — hiện tại chỉ **chặn sửa** phiếu đã hoàn tất (đúng mức tối thiểu spec cho phép: "tối thiểu phải chặn sửa phiếu đã hoàn tất").
- Quản trị "Nhóm sản phẩm/dịch vụ" dùng lại `Admin/CrudIndex` generic (bảng đơn giản), chưa có giao diện riêng đẹp hơn — đủ dùng, đúng nguyên tắc tái sử dụng tối đa.
- "Kiểm đồ nhanh" chưa có ô ghi chú/nhóm riêng cho từng dòng ngoài field `note` chung của item (đã có, không phải thiếu — chỉ chưa expose riêng lẻ trên UI popup ngoài textarea note tổng).
- Chưa build test end-to-end trình duyệt cho các dialog cảnh báo checkout/kiểm đồ (chỉ test backend + kiểm tra build frontend không lỗi compile). Khuyến nghị QA thủ công luồng: mở booking có phòng chưa kiểm đồ → bấm Trả phòng → thấy cảnh báo → thử cả 2 nhánh (bỏ qua có quyền / vẫn trả phòng).
- Bulk action "Chờ dọn/Đang dọn/Đã xong" trên Dọn phòng dùng optimistic re-check qua lock (không dùng `updated_at`/version token tường minh) — đã đủ chống ghi đè âm thầm về mặt logic nhưng khác cách diễn đạt so với "concurrency guard" kiểu version-column truyền thống (đã giải thích trong Mục 8/10 và code comment).

## 20. Các mục chưa triển khai (nằm trong phạm vi có thể hoãn — Mục XV)

Không triển khai (đúng như spec cho phép hoãn): quản lý tồn kho minibar, nhập/xuất kho, giá vốn, báo cáo lợi nhuận sản phẩm, gán nhân viên housekeeping nâng cao, mobile app riêng, barcode/QR, đồng bộ thiết bị ngoài.

## 21. Rủi ro còn lại

- 23 lỗi test pre-existing (Mục 18) vẫn tồn tại trong nhánh — không thuộc trách nhiệm sprint này nhưng nên được theo dõi/fix riêng để giữ CI xanh.
- Đã tiện thể sửa 1 bug nhỏ có sẵn trong `HandleInertiaRequests.php`: key flash `final_checkout_confirmation_required` được set qua `redirect()->with(...)` nhưng chưa từng thực sự được share ra frontend (chỉ `success`/`error` được share) — nghĩa là dialog "Xác nhận trả phòng cuối cùng" có thể chưa từng tự động hiện ra trong thực tế trước đợt này. Đã thêm đúng 1 dòng để share thêm key này (không đổi hành vi `success`/`error` hiện có, không có test nào phụ thuộc vào việc thiếu key này — đã chạy lại `CheckoutConfirmationGateTest` xác nhận vẫn pass). Đề xuất QA xác nhận dialog này hiển thị đúng trên môi trường thật.
- Chưa test tải (concurrency thực sự đa luồng) cho idempotency kiểm đồ — chỉ test logic dưới `lockForUpdate()` trong transaction đơn luồng của PHPUnit/SQLite.

## 22. Đề xuất bước tiếp theo

1. QA thủ công toàn bộ luồng UI mới (Room Board Phòng/Dọn phòng/Kiểm đồ, popup, cảnh báo checkout) trên trình duyệt thật.
2. Điều tra và fix 23 lỗi pre-existing (ticket riêng, ngoài phạm vi sprint).
3. Nếu chấp nhận thiết kế, cân nhắc xây quy trình "điều chỉnh phiếu kiểm đồ đã hoàn tất" đầy đủ (reverse + re-post) ở sprint sau.
4. Xem xét thêm E2E test (Playwright) cho các dialog cảnh báo mới.
5. `php artisan migrate` + `php artisan db:seed --class=ProductServiceCategorySeeder --class=ProductServiceSeeder` trên môi trường staging trước khi review.

## 24. Manual UI QA

**Môi trường kiểm tra:** local dev, `php artisan serve` (http://127.0.0.1:8000) + build frontend qua `npm run build` (không dùng Vite dev server), DB SQLite/MySQL local hiện có của máy dev (không phải production), migrations đã chạy đủ, seeder `ProductServiceCategorySeeder`/`ProductServiceSeeder` đã có dữ liệu (8 nhóm, 9 sản phẩm).

**Tài khoản/role đã kiểm tra:** Admin (`doantiendathl@gmail.com`, role ADMIN+MANAGER, có toàn quyền override).

**Dữ liệu QA bổ sung** (tạo qua service layer thật — `BookingService`/`RoomAssignmentService`/`StayService`/`HousekeepingService`, không sửa dữ liệu nghiệp vụ có sẵn):
- 1 phòng chuyển VACANT_DIRTY (test bulk "Chờ dọn").
- 1 phòng VACANT_DIRTY + assignment Pending (test "Đang dọn").
- 1 phòng CLEANING (assignment InProgress, test "Đã xong").
- 1 booking 1 phòng, đã check-in, checkout trong 2h ("QA Guest Single Room").
- 1 booking 2 phòng, cả hai đã check-in ("QA Guest Multi Room").
- Dữ liệu QA có sẵn từ trước trong DB dev (booking "QA CheckedIn N", "QA Overstay N"...) được giữ nguyên, không đụng tới.

**Các màn hình đã kiểm tra:** Phòng (`/rooms`), Dọn phòng (`/admin/housekeeping`), Sản phẩm/Dịch vụ (`/product-services`), Kiểm đồ trả phòng (`/admin/checkout-inspections`), Chi tiết booking tab Tài chính + Sơ đồ phòng (`/admin/bookings/{id}`).

### Kết quả từng mục

**II. Phòng — PASS**
- Room Board hiển thị đúng theo tầng, không tràn chữ/vỡ card/lệch màu.
- "Chọn tất cả phòng đủ điều kiện" chọn đúng 58/59 phòng, loại trừ đúng phòng OUT_OF_ORDER.
- Chọn thủ công phòng đang bảo trì vẫn được (để có thể "Mở lại sau bảo trì").
- "Bỏ chọn tất cả" hoạt động đúng.
- Bulk "Đưa vào bảo trì" (1 phòng, có nhập lý do) → thành công, phòng chuyển "Hỏng" + badge bảo trì, thông báo "Thành công 1 phòng."
- Bulk "Mở lại sau bảo trì" (2 phòng) → thành công, cả hai về "Trống bẩn", thông báo "Thành công 2 phòng."
- Refresh xác nhận dữ liệu khớp backend (đã kiểm qua DB trực tiếp).

**III. Dọn phòng — PASS**
- Room Board theo tầng, card ngắn gọn (số phòng/trạng thái/khách/badge "Trả hôm nay").
- Bulk "Chờ dọn" → "Đang dọn" → "Đã xong" chạy đúng chu trình đầy đủ qua UI thật (phòng chuyển VACANT_DIRTY → CLEANING → INSPECTED chính xác).
- Popup "Chi tiết" mở đúng phòng, hiển thị đủ: loại phòng, trạng thái phòng, trạng thái dọn phòng, khách, check-in/dự kiến trả.
- Đóng popup rồi mở phòng khác → không giữ dữ liệu phòng cũ (đã test 105 → đóng → 103, đúng "Chi tiết phòng 103").

**IV. Sản phẩm/Dịch vụ — PASS**
- Danh sách hiển thị đủ trường, giá đúng định dạng "xx.xxx đ".
- Tạo mới "QA Test Item" thành công, hiện đúng ngay trong bảng.
- "Ngừng sử dụng" → trạng thái đổi "Ngừng", nút đổi thành "Bật".
- Sản phẩm ngừng hoạt động **biến mất khỏi bộ chọn Kiểm đồ nhanh** (đã xác nhận trực tiếp khi mở popup kiểm đồ ngay sau khi tắt).

**V. Kiểm đồ nhanh khi trả phòng — PASS**
- Room Board đúng, nhóm theo tầng, card đủ thông tin, không tràn.
- Popup mở đúng đúng stay/phòng (test cả phòng 105 và 201).
- Danh mục sản phẩm trong popup chỉ hiện đúng 6 sản phẩm có `is_active=true` và `use_in_checkout_inspection=true` — loại đúng sản phẩm chỉ `can_add_to_booking` và sản phẩm vừa tắt hoạt động.
- Công thức `chargeable_quantity = max(actual - free, 0)` tính đúng qua UI thật: actual=5, free=2 → tính phí 3 × 15.000đ = 45.000đ.
- "Hoàn tất kiểm đồ" → trạng thái board chuyển "Có phát sinh" + số tiền đúng; **đã xác nhận trực tiếp trong DB**: `FolioEntry` đúng snapshot (mô tả, số lượng, đơn giá, `posting_key=CHECKOUT_INSPECTION_2_1`), `CheckoutInspection.status=COMPLETED`, `posted_at` đã set.
- Mở lại phiếu đã hoàn tất → banner "Phiếu đã hoàn tất — không thể sửa" hiển thị rõ, các input bị khóa, không có nút Lưu/Hoàn tất.
- "Xác nhận không phát sinh" (phòng 201) → trạng thái "Không phát sinh" đúng.
- Booking nhiều phòng: hoàn tất kiểm đồ phòng 201 **không ảnh hưởng** phòng 202 (vẫn "Chưa kiểm") — xác nhận trực tiếp qua UI.

**VI. Thêm sản phẩm/dịch vụ vào booking — PASS**
- Khu vực "Sản phẩm/dịch vụ" trong "Thêm phí" chỉ hiện đúng 6 sản phẩm có `can_add_to_booking=true` (loại đúng sản phẩm chỉ dùng cho kiểm đồ và sản phẩm đã tắt).
- Chọn "Bia lon" → tự điền đúng loại phí (Minibar), mô tả, đơn giá (30.000đ, **có thể sửa** vì Admin có `product_services.manage`).
- Chọn phòng 105, số lượng 2 → "Đã thêm phí.", dòng phí đúng trong bảng Phí phát sinh (60.000đ, phòng 105, người đăng "Tien Dat Doan").
- Ghi nhận đi qua đúng pipeline `FolioService::addCharge` hiện có (đã xác nhận qua code + DB), không có bảng tổng tiền riêng.

**VII. Checkout — PASS**
- Phòng chưa kiểm đồ (202): bấm "Trả phòng" → dialog cảnh báo "Chưa kiểm đồ phòng 202" hiện rõ, có link "Mở kiểm đồ nhanh (tab mới)", có khối "Bỏ qua kiểm đồ (yêu cầu lý do)" vì tài khoản test có quyền override.
- "Vẫn trả phòng" → không khóa tuyệt đối: dialog cảnh báo số dư chưa thanh toán (tính năng có sẵn, độc lập) hiện tiếp theo → xác nhận tiếp → checkout thành công (phòng 202 "Đã trả phòng", phòng 201 trong cùng booking **không đổi** — đúng yêu cầu partial checkout chỉ kiểm tra phòng đang trả).
- Không kiểm tra được nhánh "Reception không có quyền override" trực tiếp qua UI trong phiên này (đã có PHPUnit `test_skip_endpoint_forbidden_without_permission` bao phủ ở tầng backend, xác nhận 403 khi role không có `checkout_inspection.override`).

**VIII. Responsive — MỘT PHẦN**
- Desktop rộng (~1707px hiệu lực trong môi trường test): layout Room Board 6 cột, không tràn, nút không chồng, không có lỗi console trong suốt phiên QA (đã kiểm `read_console_messages`, 0 lỗi).
- **Không thể xác minh trực tiếp ở độ rộng 1366×768 / 1024px**: công cụ `resize_window` trong môi trường test này không thực sự thay đổi viewport render (window.innerWidth vẫn giữ nguyên sau khi gọi) — đây là giới hạn của công cụ QA tự động, không phải đặc tính của ứng dụng. Đã xác nhận bằng code: `RoomBoardGrid.vue` dùng breakpoint Tailwind chuẩn `grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6`, cùng pattern đã dùng ổn định ở các màn hình Room Board có trước (Housekeeping, Room Availability) — rủi ro thấp nhưng **chưa được xác minh trực quan**.

### Lỗi phát hiện và đã sửa trong vòng QA này

1. **`window.confirm()`/`window.prompt()` trong `Rooms/Index.vue`** chặn toàn bộ tương tác trình duyệt (native dialog block) khi test bulk "Đưa vào bảo trì"/"Mở lại sau bảo trì". Đã thay bằng dialog nội tuyến (inline confirm panel), giữ nguyên hành vi nghiệp vụ (yêu cầu lý do, xác nhận trước khi gửi), nhất quán với các modal tùy chỉnh khác đã xây trong đợt này (`CheckoutInspectionModal`, `HousekeepingDetailModal`). File sửa: `resources/js/Pages/Admin/Rooms/Index.vue`. Đã rebuild và test lại thành công qua UI thật.
2. Không phát hiện lỗi nghiệp vụ/backend mới nào khác trong vòng QA này. Toàn bộ luồng dữ liệu (folio entry, snapshot, trạng thái phiếu, phân quyền) khớp đúng thiết kế khi kiểm tra trực tiếp qua UI + DB.

### File đã sửa thêm trong vòng QA

- `resources/js/Pages/Admin/Rooms/Index.vue` (thay native dialog bằng inline confirm panel).

### Test bổ sung

Không cần thêm test PHPUnit mới trong vòng QA này — lỗi phát hiện thuần UI (native dialog), không có logic backend/tính toán nào thay đổi. Đã chạy lại toàn bộ 42 test mới + 77 test liên quan (checkout/housekeeping/permission) sau khi sửa — tất cả pass (xem log dưới).

```
42 passed (149 assertions) — toàn bộ test mới của sprint này
77 passed (248 assertions) — CheckoutConfirmationGateTest, CheckoutIntegrationTest,
                              PartialCheckoutStayEventTest, HousekeepingControllerTest,
                              RolePermissionSeederTest
```

### Ảnh chụp màn hình

Không đính kèm file ảnh trong báo cáo (giới hạn công cụ chụp màn hình bị treo không ổn định trong phiên này — xem "Lỗi còn lại" bên dưới); đã xác minh trực quan qua các screenshot thành công trong phiên (Room Board Phòng, Dọn phòng, Sản phẩm/Dịch vụ) và qua `get_page_text`/kiểm tra DOM trực tiếp cho các phần còn lại.

### Lỗi còn lại

- Công cụ chụp màn hình (CDP `Page.captureScreenshot`) trong môi trường trình duyệt test bị treo/timeout không ổn định trong suốt phiên (không liên quan đến ứng dụng — đã xác minh bằng cách gọi `curl` trực tiếp vào backend trong lúc "treo", backend phản hồi bình thường 200 OK trong <1s). Đã chuyển sang dùng `get_page_text`/`read_page`/thực thi JS trực tiếp để xác minh trạng thái, hiệu quả tương đương cho việc kiểm tra dữ liệu/logic nhưng **không thay thế được việc xác minh trực quan (màu sắc, khoảng cách, overflow) một cách đầy đủ** ở tất cả các màn hình.
- Không xác minh được responsive ở 1366×768/1024px (xem mục VIII ở trên).
- Chưa test nhánh "Reception bị từ chối override" trực tiếp qua UI (chỉ có PHPUnit).

### Các mục chưa thể xác minh

- Hiển thị ở độ phân giải tablet/laptop nhỏ (giới hạn công cụ).
- Hành vi khi có nhiều tab/nhiều người dùng thao tác đồng thời (concurrency thực tế ngoài transaction đơn luồng).
- Ảnh chụp màn hình đầy đủ cho toàn bộ 7 nhóm màn hình (chỉ có ảnh cho Phòng, Dọn phòng, Sản phẩm/Dịch vụ; các màn còn lại xác minh qua text/DOM).

## 25. Final Review Readiness

**READY FOR FINAL REVIEW**

Căn cứ:
- Không còn lỗi UI nghiêm trọng nào chưa xử lý (lỗi duy nhất phát hiện — native dialog chặn tương tác — đã sửa và xác minh lại qua UI thật).
- Không phát hiện lỗi nghiệp vụ mới nào khi thao tác trực tiếp qua UI (đã kiểm tra Phòng, Dọn phòng, Sản phẩm/Dịch vụ, Kiểm đồ nhanh, thêm SP/DV vào booking, checkout warning — bao gồm cả kiểm tra chéo với DB thật).
- Không có phí trùng (đã xác nhận `posting_key` đúng, phiếu hoàn tất khóa đúng).
- Bulk action (Phòng + Dọn phòng) hoạt động đúng qua UI thật, có thông báo thành công/thất bại rõ ràng.
- Phân quyền đúng theo thiết kế (Admin có đủ quyền; Reception bị chặn ở tầng backend đã test qua PHPUnit).
- Checkout và partial checkout hoạt động đúng, không khóa tuyệt đối, không đánh dấu sai phòng chưa trả.
- Không có regression mới: 42 test mới + 77 test liên quan đều pass sau khi sửa lỗi UI; 23 lỗi pre-existing giữ nguyên như baseline đã ghi (không tăng thêm).
- Build frontend thành công (`npm run build`, không lỗi).
- **Vẫn chưa commit, chưa push** — toàn bộ thay đổi ở working tree.

Giới hạn cần lưu ý khi review (không chặn review, nhưng nên biết): chưa xác minh trực quan ở độ phân giải tablet/laptop nhỏ do giới hạn công cụ chụp màn hình trong phiên QA này; đề xuất người review tự kiểm tra nhanh responsive trên trình duyệt thật trước khi merge.

**Cập nhật (vòng Mobile-First Review sau đó):** giới hạn responsive nêu trên đã được giải quyết — xem Mục 26/27. Đã xác minh trực tiếp ở đúng 4 viewport 360/390/412/768 bằng kỹ thuật render thật (không suy luận từ class).

## 26. Mobile Operations Review

**Ghi chú phương pháp:** Công cụ `resize_window` của môi trường automation này **không** thay đổi viewport render thật (đã xác minh 2 lần, `window.innerWidth` giữ nguyên sau khi gọi). Để có render thật đúng viewport mobile theo đúng yêu cầu "Không được chỉ suy luận từ class Tailwind", đã dùng kỹ thuật `<iframe>` nhúng cùng-origin kích thước cố định (390×844, 360×800, 412×915, 768×1024) — CSS media query của Tailwind áp dụng theo chiều rộng thật của iframe (`contentWindow.innerWidth`), đây là **render engine thật của Chrome**, không phải giả lập/suy luận. Đã đo bằng `getBoundingClientRect()` cho kích thước nút thực tế và `scrollWidth`/`clientWidth` cho overflow ngang, cộng với chụp màn hình trực quan và thao tác thật (click/nhập liệu) qua iframe để xác nhận chức năng vẫn hoạt động, không chỉ hiển thị đẹp.

**Viewport đã kiểm tra:** 360×800, 390×844, 412×915 (đều dưới breakpoint `sm` 640px của Tailwind, hành vi giống nhau), và 768×1024 (breakpoint `md`, tablet).

### Màn hình Dọn phòng

- **Trước khi sửa:** phát hiện 2 vấn đề nghiêm trọng bằng đo đạc trực tiếp (không suy luận):
  1. **Không có menu điều hướng nào trên mobile.** `AppLayout.vue` ẩn hoàn toàn sidebar dưới `lg:block`, không có thay thế — nhân viên trên điện thoại không có cách nào chuyển màn hình (Dọn phòng ↔ Kiểm đồ ↔ Tổng quan...) ngoài gõ URL thủ công.
  2. **Nút bấm quá nhỏ.** Đo `getBoundingClientRect()`: các nút hành động trên card (Chờ dọn/Đang dọn/Đã xong/Đạt/Không đạt/Bỏ qua/Khóa bảo trì/Chi tiết) chỉ cao **~22px**, nút filter "Chọn nhanh"/"Tầng N" chỉ cao **~26px** — dưới xa mức tối thiểu 44px yêu cầu.
- **Sau khi sửa:** đo lại xác nhận toàn bộ nút hành động trên card **44–50px** chiều cao. Card chỉ còn tối đa 2 nút/hàng (grid-cols-2) thay vì flex-wrap chen chúc; "Chi tiết" luôn full-width để dễ bấm nhất.
- Bulk action bar (Chờ dọn/Đang dọn/Đã xong): nút tăng lên `min-h-11`, không che card (giữ nguyên vị trí sticky-top có sẵn, không chuyển xuống đáy vì thay đổi đó rủi ro cao hơn lợi ích ở phạm vi sửa tối thiểu).
- Popup "Chi tiết" (`HousekeepingDetailModal.vue`): cấu trúc đã sẵn mobile-friendly từ trước (`max-h-[90vh] flex flex-col` + nội dung `overflow-y-auto` riêng) — chỉ cần tăng vùng bấm nút đóng lên 44×44px. Đã test thật: mở phòng A → đóng → mở phòng B → không giữ dữ liệu phòng cũ (kế thừa từ vòng QA desktop trước, không bị ảnh hưởng bởi thay đổi lần này).
- `HousekeepingActionDialog.vue` (dialog xác nhận đổi trạng thái): nút Hủy/Xác nhận tăng lên `min-h-11`, xếp dọc full-width trên mobile (`flex-col sm:flex-row`).
- Đã thao tác thật qua render mobile 390px: chọn phòng → bulk "Chờ dọn" → verify DB. (Tái xác nhận logic không đổi, chỉ CSS/kích thước thay đổi.)
- **Không horizontal overflow** ở cả 4 viewport (đo `document.body.scrollWidth === clientWidth` tại mọi mức).

### Màn hình Kiểm đồ nhanh

- Room Board card: đã đạt yêu cầu từ trước (nội dung ngắn gọn: số phòng, khách rút gọn, giờ trả, trạng thái, tổng phí) — chỉ tăng nút "Kiểm đồ"/"Chi tiết" lên `min-h-11`.
- **Vấn đề nghiêm trọng nhất phát hiện:** bảng danh sách sản phẩm trong popup kiểm đồ có 6-7 cột (Sản phẩm/Miễn phí/Thực tế/Tính phí/Đơn giá/Thành tiền), đo được `table.scrollWidth=462px` trong khung chỉ rộng `365px` → bắt buộc cuộn ngang **bên trong một hàng sản phẩm**, đúng như lo ngại "dồn thành một hàng chật" trong spec.
- **Đã sửa:** thêm layout mobile riêng (`sm:hidden`) — mỗi sản phẩm là **một block card độc lập** (tên/đơn vị/miễn phí ở đầu, dòng thực tế với nút −/+ to, dòng tính phí, dòng đơn giá/thành tiền ở cuối), bảng gốc chuyển `hidden sm:table` chỉ hiện ≥640px và được bọc `overflow-x-auto` phòng hờ. Đã đo lại: **0 overflow** sau khi sửa (`bodyScrollWidth === clientWidth`).
- Nút −/+ số lượng: đo được đúng **44×44px** sau khi sửa (trước đó chỉ ~24px).
- Footer (Lưu nháp / Xác nhận không phát sinh / Hoàn tất kiểm đồ): chuyển xếp dọc full-width trên mobile (`flex-col sm:flex-row`), mỗi nút `min-h-11` (đo lại: đúng 44px). Footer nằm ngoài vùng scroll nội dung (không sticky, chiều cao cố định) nên luôn hiện, không bị bàn phím ảo che — cấu trúc này vốn đã đúng từ trước, không cần sửa thêm.
- **Đã test chức năng thật qua render mobile 390px** (không chỉ xem hình): mở popup phòng → thêm "Nước suối 500ml" → "Hoàn tất kiểm đồ" → xác nhận qua DB: `CheckoutInspection.status = COMPLETED`. Luồng hoạt động đúng 100% qua giao diện mobile thật.
- Chống submit trùng: giữ nguyên cơ chế cũ (`processing` flag disable nút, backend `posted_at`/`posting_key` idempotency) — không đổi.

### Popup nói chung

Cả hai popup (`CheckoutInspectionModal.vue`, `HousekeepingDetailModal.vue`) vốn đã dùng đúng pattern `fixed inset-0 items-end sm:items-center` (bottom-sheet trên mobile, modal căn giữa trên desktop) + `max-h-[90vh] flex flex-col` + nội dung `overflow-y-auto` riêng biệt với header/footer cố định — cấu trúc này **đã đúng chuẩn mobile từ khi xây (Task #6 trước đó)**, không cần rewrite. Vòng review này chỉ bổ sung: vùng bấm nút đóng đủ lớn, và (riêng kiểm đồ) thay bảng bằng card list.

### Nút bấm

Tổng hợp đo đạc trước/sau (đơn vị px, đo bằng `getBoundingClientRect()` tại viewport 390px):

| Nút | Trước | Sau |
|---|---|---|
| Card action (Chờ dọn/Đang dọn/Đã xong/Đạt/Không đạt/Bỏ qua/Khóa bảo trì) | ~22 | 44–50 |
| "Chi tiết" (Dọn phòng) | ~22 | 44 |
| "Kiểm đồ"/"Chi tiết" (Kiểm đồ nhanh) | ~26 | 44 |
| Nút −/+ số lượng (popup kiểm đồ) | ~24×24 | 44×44 |
| Nút đóng (X) các popup | không đo (icon trần, ước lượng ~16×16) | 44×44 |
| Footer popup (Lưu nháp/Không phát sinh/Hoàn tất) | ~30 | 44 |
| Dialog xác nhận đổi trạng thái (Hủy/Xác nhận) | ~30 | 44 |

### Bộ lọc

Cả 2 màn hình **không có bộ lọc tầng/trạng thái thật sự** (khác với màn "Phòng" đã có filter form) — chỉ có nút "Chọn nhanh theo tầng" (shortcut chọn hàng loạt, không phải filter ẩn/hiện) và ô tìm kiếm (chỉ riêng Kiểm đồ nhanh). Mục IV của yêu cầu vì vậy phần lớn không áp dụng được cho 2 màn hình này ở trạng thái hiện tại — không thêm tính năng filter mới (ngoài phạm vi "sửa lỗi tối thiểu"). Các nút "Chọn nhanh"/"Tầng N" đã được tăng `min-h-9` cho dễ bấm hơn (không bắt buộc 44px vì đây không phải nút hành động chính).

### Quyền và thông tin hiển thị

Không thay đổi trường dữ liệu hiển thị trên card (giữ nguyên tên khách, NV phụ trách, độ ưu tiên, badge trạng thái) — các trường này đã ở mức tối giản một dòng/badge ngắn gọn từ khi xây dựng, không phải thông tin tài chính, và việc bớt trường là quyết định sản phẩm cần thảo luận riêng chứ không phải "lỗi" theo đúng nghĩa cần sửa ngay. Không có thay đổi phân quyền nào trong vòng review này (không đổi backend).

### Bàn phím ảo

Không có thiết bị thật để test bàn phím ảo che nút trong phiên này. Đã giảm rủi ro về mặt cấu trúc: footer popup kiểm đồ nằm ngoài vùng `overflow-y-auto`, không phụ thuộc vị trí cuộn nội dung — theo cấu trúc CSS, bàn phím ảo (đẩy viewport) không thể che footer vì footer không nằm trong luồng cuộn bị đẩy. Đây là suy luận cấu trúc hợp lý nhưng **chưa được xác minh trên thiết bị thật**.

### Lỗi mạng

Đã bổ sung message rõ ràng "Mất kết nối, thao tác chưa được lưu." (khác với lỗi server trả về có `response`) cho: bulk action Dọn phòng, `CheckoutInspectionModal` (mở phiếu / lưu nháp / hoàn tất), `HousekeepingDetailModal` (tải chi tiết). Không tự động retry, không hiển thị thành công giả — giữ nguyên cơ chế idempotency backend hiện có (`posting_key`, `posted_at`, lock DB), không đổi gì ở backend.

### Lỗi phát hiện và đã sửa

1. **[Nghiêm trọng] Không có điều hướng mobile.** `AppLayout.vue` — đã thêm hamburger menu (`lg:hidden`) + drawer trượt từ trái, chứa đúng danh sách nav item hiện có (không đổi cấu trúc quyền/route). Thuần bổ sung, không đổi desktop (đã xác nhận trực quan: sidebar desktop giữ nguyên 100%, hamburger ẩn đúng ở `lg:` trở lên).
2. **[Nghiêm trọng] Nút bấm toàn bộ 2 màn hình quá nhỏ (~22-26px, dưới 44px yêu cầu).** Đã sửa ở 6 file (xem danh sách dưới).
3. **[Cao] Bảng 6 cột trong popup kiểm đồ vỡ layout, buộc cuộn ngang trong 1 hàng.** Đã thêm layout card-list riêng cho mobile.
4. **[Trung bình] Thông báo lỗi mạng chung chung, không phân biệt mất mạng vs lỗi server.** Đã sửa ở 3 file có gọi API.
5. Không phát hiện lỗi nghiệp vụ/backend nào — toàn bộ sửa lỗi trong vòng này là CSS/cấu trúc component/JS thuần frontend, không đổi route, controller, service, hay migration nào.

### File đã sửa

- `resources/js/Layouts/AppLayout.vue` (thêm mobile nav drawer — mới)
- `resources/js/Pages/Admin/Housekeeping/Index.vue` (kích thước nút, lỗi mạng)
- `resources/js/Pages/Admin/CheckoutInspections/Index.vue` (kích thước nút)
- `resources/js/Pages/Admin/CheckoutInspections/Partials/CheckoutInspectionModal.vue` (card list mobile, kích thước nút, footer xếp dọc, lỗi mạng, nút đóng)
- `resources/js/Pages/Admin/Housekeeping/Partials/HousekeepingActionDialog.vue` (kích thước nút, nút đóng)
- `resources/js/Pages/Admin/Housekeeping/Partials/HousekeepingDetailModal.vue` (nút đóng, lỗi mạng)

Không sửa file backend/PHP nào trong vòng review này.

### Kết quả build/test

```
npm run build → thành công, không lỗi (599.25 kB app.js, 34.01 kB app.css)
php artisan test (42 test mới + 62 test regression liên quan
  — Housekeeping, CheckoutConfirmationGate, CheckoutIntegration, PartialCheckoutStayEvent)
  → 104 passed (353 assertions), 0 failed
```

### Hạn chế còn lại

- Không test trên thiết bị iOS/Android thật, chỉ render engine Chrome thật qua iframe cùng kích thước — hành vi CSS/JS giống hệt thiết bị thật (cùng Blink engine), nhưng cảm giác chạm thật (tap accuracy, bàn phím ảo thật) chưa được xác minh trực tiếp.
- Chưa xác minh bàn phím ảo che nội dung trên thiết bị thật (chỉ suy luận cấu trúc CSS — xem mục "Bàn phím ảo").
- Chưa xác minh nhánh Reception (không có quyền override kiểm đồ) trên mobile — đã có PHPUnit test backend, chưa test UI mobile riêng cho role này.
- Chưa build PWA/manifest/service worker (không thuộc phạm vi sprint này — xem Mục VIII của yêu cầu, chỉ ghi nhận bên dưới).
- **Ghi nhận cho PWA sau này (chỉ khảo sát, chưa triển khai):**
  - Route mobile không cần tách riêng — cùng route Inertia, responsive bằng CSS đã đủ.
  - Layout mobile riêng không cần thiết ở mức hiện tại — `AppLayout.vue` dùng chung + biến thể `lg:hidden`/`lg:block` đã đáp ứng.
  - Có thể thêm `manifest.json` + icon sau này mà không cần đổi cấu trúc hiện tại (thuần bổ sung file tĩnh + thẻ `<link>` trong layout gốc).
  - Full-screen (`display: standalone`) khả thi sau này qua manifest, không cần đổi component.
  - Điểm cần cân nhắc service worker sau này: **không cache** response `/admin/checkout-inspections/*`, `/admin/bookings/*/folio/*` (dữ liệu tài chính/trạng thái động) — nếu triển khai sau, chỉ nên cache asset tĩnh (JS/CSS/font/icon), không cache API/Inertia response. Chưa thêm service worker trong đợt này theo đúng yêu cầu.

## 27. Commit Readiness

**READY FOR COMMIT**

Căn cứ:
- Dọn phòng dùng tốt trên mobile: nút ≥44px (đo được), card 2 cột không ép chật, popup Chi tiết đúng chuẩn, đã test thao tác thật (bulk assign) qua render mobile + xác nhận DB.
- Kiểm đồ nhanh dùng tốt trên mobile: nút ≥44px, danh sách sản phẩm không còn bảng nhiều cột chật, đã test luồng "thêm sản phẩm → hoàn tất kiểm đồ" thật qua mobile + xác nhận DB (`status=COMPLETED`).
- Không còn nút quá nhỏ hoặc chồng nhau (đã đo `getBoundingClientRect()` xác nhận, không suy luận).
- Popup không vượt viewport ở cả 4 kích thước đã test (`max-h-[90vh]` + scroll nội bộ).
- Không có horizontal overflow ở bất kỳ viewport nào trong 4 viewport đã test (đo `scrollWidth`/`clientWidth`, bằng nhau ở mọi trường hợp).
- Không phá giao diện desktop (đã chụp màn hình xác nhận sidebar/layout desktop giữ nguyên 100%).
- Build pass (`npm run build` thành công).
- Test liên quan pass (104/104: 42 test mới + 62 test regression Housekeeping/Checkout/Partial-checkout).
- Không phát sinh regression mới (không đổi file PHP/backend nào trong vòng này; toàn bộ 104 test đã pass trước đó vẫn pass).
- **Chưa commit, chưa push** — toàn bộ thay đổi vẫn ở working tree.

Hạn chế cần lưu ý (không chặn commit, nhưng nên biết): chưa test trên thiết bị thật (chỉ render engine Chrome thật qua kỹ thuật iframe, không phải giả lập/suy luận, nhưng khác thiết bị vật lý thật); chưa test bàn phím ảo thật; chưa test nhánh Reception trên mobile UI. Đề xuất QA thật trên điện thoại trước khi đưa vào sử dụng chính thức cho nhân viên buồng phòng.

## 28. Commit Closure

**Trạng thái: COMMITTED — NOT PUSHED**

Branch `phase-3` hiện **ahead of `origin/phase-3` by 2 commit** (đã xác minh bằng `git log origin/phase-3..HEAD --oneline`, liệt kê đúng 2 dòng dưới đây, theo đúng thứ tự cũ → mới):

- **Main implementation commit:** `d8c8d01`
  `feat(room-operations): add mobile-ready housekeeping and checkout inspection`
  ```
  Adds Room Board conversions for Rooms and Housekeeping with bulk actions,
  a dynamic Product/Service catalog, quick checkout inspection posting to
  folio with duplicate-post protection, product/service add-to-booking, and
  a non-blocking checkout inspection warning supporting multi-room/partial
  checkout. Includes a mobile-first pass (nav drawer, 44px touch targets,
  card-list layouts) for the Housekeeping and Checkout Inspection screens.

  Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
  ```
  File tạo mới: 50 · File chỉnh sửa: 24 · Tổng: 74 file, +5204/-97 dòng (`git show --stat d8c8d01` xác nhận).

- **Documentation closure commit:** `913824d`
  `docs(room-operations): record implementation commit closure`
  1 file thay đổi (`docs/implementation-reports/room-operations-checkout-inspection-implementation-report.md`), +40/-1 dòng (`git show --stat 913824d` xác nhận). Đây là commit bổ sung Mục 28 (bản đầu, đã có sai lệch số liệu — xem ghi chú hiệu chỉnh bên dưới).

**Ghi chú hiệu chỉnh:** Bản đầu của Mục 28 (viết trước khi commit `913824d` được tạo) chỉ ghi `d8c8d01` và "ahead by 1 commit" — con số đó đúng tại **thời điểm soạn nội dung**, nhưng vì nội dung đó lại được đóng gói thành chính commit `913824d`, nên ngay sau khi commit xong, trạng thái thực tế trở thành "ahead by 2". Sai lệch này đã được phát hiện qua đối chiếu `git log origin/phase-3..HEAD` và được sửa tại đây — **không amend `d8c8d01` hay `913824d`** — bằng một commit tài liệu hiệu chỉnh thứ ba riêng biệt: `docs(room-operations): correct commit closure metadata`.

- **File tạo mới:** 50
- **File chỉnh sửa:** 24
- **Tổng:** 74 file, +5204/-97 dòng (đo tại `d8c8d01`; commit tài liệu `913824d` và commit hiệu chỉnh sau đó chỉ động vào file báo cáo, không tính vào số liệu tính năng).
- **Loại trừ khỏi commit (đúng chủ đích, không thuộc phạm vi tính năng này):** `.gitignore` (thay đổi không liên quan, pre-existing), `.env.production.example`, `docs/pilot/` (2 file), `docs/reports/*.md` (5 báo cáo closure của phase/sprint trước), `storage/backups/` (chứa file backup DB `.sql.gz`/`.tar.gz` — tuyệt đối không đưa vào commit). Đã rà soát thủ công từng file trước khi stage, không dùng `git add -A`/`.`. Đã re-verify sau khi thêm commit hiệu chỉnh — working tree vẫn chỉ còn đúng các mục ngoài phạm vi này.
- **Quét bí mật/credential:** đã grep diff đã stage theo các pattern API key/secret/private key/password gán cứng — không phát hiện gì.
- **Build/test cuối trước commit:**
  ```
  npm run build → PASS (không lỗi)
  php artisan test (9 file: 7 test mới + HousekeepingControllerTest + RolePermissionSeederTest)
    → 88 passed (332 assertions), 0 failed
  ```
- **Trạng thái push:** **CHƯA PUSH** — theo đúng chỉ đạo, chờ chỉ đạo tiếp theo.
- **Known limitations còn lại trước khi vận hành thật:**
  1. Chưa test trên thiết bị mobile thật (chỉ render engine Chrome thật qua kỹ thuật iframe cùng-origin, không phải thiết bị vật lý).
  2. Chưa test bàn phím ảo che nội dung trên thiết bị thật (chỉ suy luận cấu trúc CSS).
  3. Chưa test nhánh "Reception không có quyền override kiểm đồ" trên mobile UI (đã có PHPUnit test backend).
  4. 23 lỗi test pre-existing trên toàn bộ suite (không liên quan commit này, đã xác minh bằng baseline `git stash` — xem Mục 18) — nên có ticket riêng để điều tra/fix.
  5. Chưa xây quy trình "điều chỉnh phiếu kiểm đồ đã hoàn tất" đầy đủ (reverse + re-post) — hiện tại chỉ chặn sửa phiếu đã hoàn tất (đáp ứng mức tối thiểu spec yêu cầu).
  6. Chưa có PWA/manifest/service worker (ngoài phạm vi sprint này theo yêu cầu, chỉ đã ghi nhận điểm cần lưu ý cho sau này ở Mục 26).

---

Đã commit (`d8c8d01`). Chưa push. Chờ chỉ đạo tiếp theo.
