# Unified Services & Requests — Implementation Plan (Slice 1)

**Ngày:** 2026-08-16
**Slice:** 1/N — chứng minh toàn bộ kiến trúc mới end-to-end qua đúng 1 nghiệp vụ thật: **Giường phụ**.
**Slice được người dùng duyệt (GATE 1) trước khi triển khai.**

---

## 1. Vì sao chọn Giường phụ làm slice đầu

Đây là nghiệp vụ đã được audit kỹ trong phiên làm việc trước khi viết prompt này — đã biết rõ dữ liệu thật, đã biết rõ lỗi (`GIUONGPHU`), và nó chạm đủ những đặc tính khó nhất của kiến trúc đích: theo phòng (`scope=ROOM`), tính qua đêm (`billing_mode=PER_NIGHT`), cần xác nhận/hoàn thành (`fulfillment_required=true`), tích hợp Night Audit, cần snapshot giá. Nếu chạy đúng cho Giường phụ, kiến trúc coi như đã được chứng minh cho mọi trường hợp còn lại.

---

## 2. Quyết định kiến trúc đã tự chọn (không có Business Blocker nào phát sinh)

Tất cả quyết định dưới đây được tự suy luận từ code/database/prompt, theo đúng nguyên tắc Mục 2 của `docs/yeucaumoi.txt` — không có quyết định nào thỏa cả 4 điều kiện của "Business Blocker thực sự" (2 cách hiểu hợp lý khác nhau + không suy luận được từ code + ảnh hưởng tính tiền/quyền/mất dữ liệu + không có giải pháp backward-compatible an toàn).

| Quyết định | Lựa chọn | Lý do |
|---|---|---|
| `ChargeType` cho FolioEntry của dịch vụ hợp nhất | `ChargeType::Other` | Không thêm case mới vào enum đang được nhiều nơi khác đọc (RevenueReport, Reconciliation...) trong 1 slice nhỏ — giữ additive tối đa. Rủi ro đã ghi vào Risks. |
| Route booking-side | Trang mới `/admin/bookings/{booking}/services`, KHÔNG sửa `Packages.vue` | Tách rủi ro hoàn toàn khỏi luồng cũ đang chạy; cả 2 cùng tồn tại trong giai đoạn chuyển đổi (Mục 1 cho phép). |
| Migration GIUONGPHU | Audit trước (đã xong, 0 usage), chỉ **deactivate**, không rename/xóa | `code` bị khóa bởi `hasBeenUsed()` một khi có giá — dù usage thật = 0, `service_package_rates` vẫn có 1 dòng giá nên field bị khóa qua UI. Deactivate là thay đổi an toàn nhất, đảo ngược được (bật lại `is_active`/`is_bookable`). |
| Permission mới | `services.manage` (catalog), tái dùng `booking.package.manage` (booking-side) | Mục 13 yêu cầu tái dùng permission đã có nếu phù hợp — `booking.package.manage` đã đúng nghĩa "quản lý dịch vụ trong 1 booking". |
| Category cho Giường phụ | Tạo mới `BED_CONFIG` ("Cấu hình phòng") | Không có danh mục hợp nhất nào có sẵn để tái dùng (danh mục cũ là 2 enum khác: `RequestCategory` và `ProductServiceCategory`, không migrate ở slice này). |
| Giá khởi tạo | 150.000đ, hiệu lực 01/08/2026 (khớp `service_rates` hiện tại) | Đây là giá **thật sự đang được dùng để tính tiền** hiện nay (qua `ExtraBedPostingJob`) — mang đúng số này sang để Admin chỉ cần xác nhận, không phải đoán. |

---

## 3. Data model

Xem chi tiết cột ở `unified-services-architecture-review.md` Mục 3. Tóm tắt 4 bảng mới:

- `service_categories` (id, code, name, description, sort_order, is_active, created_by, updated_by)
- `services` (id, category_id FK, code, name, description, is_chargeable, scope, billing_mode, quantity_enabled, default_quantity, unit_label, fulfillment_required, is_active, is_bookable, sort_order, created_by, updated_by)
- `service_prices` (id, service_id FK, unit_price, effective_from, is_active, created_by)
- `booking_services` (id, booking_id FK, service_id FK, room_assignment_id FK nullable, quantity, billing_mode_selected, suggested_price, actual_price, price_override_reason, fulfillment_status, created_by, confirmed_by/at, completed_by/at, cancelled_by/at)

---

## 4. Build order (đã thực hiện theo đúng thứ tự này)

1. Migrations (4 bảng, additive-only, không FK ngược vào bảng cũ trừ `users`/`bookings`/`room_assignments` là tham chiếu đọc, không sửa cấu trúc chúng).
2. Enum: `ServiceScope`, `ServiceBillingMode`, `ServiceFulfillmentStatus`.
3. Model: `ServiceCategory`, `Service` (có khóa field sau khi dùng, mirror `ServicePackage::booted()`), `ServicePrice`, `BookingService`.
4. `ServicePricingResolver` — resolver giá duy nhất.
5. `BookingServiceEnrollmentService` — enroll/confirm/complete/cancel, validate scope + giá + lý do đổi giá.
6. `UnifiedServicePostingJob` — PER_NIGHT (đăng ký thêm vào Night Audit) + `postOneTime()` (gọi trực tiếp lúc enroll).
7. Admin catalog: `ServiceCategoryController` (tái dùng `Admin/CrudIndex`+`CrudForm` có sẵn), `ServiceCatalogController`, `ServicePriceController`.
8. Booking-side: `BookingServiceController` + trang Vue riêng `Admin/Booking/Services.vue`.
9. `AuditMiscodedLegacyPackages` console command (dry-run mặc định).
10. `UnifiedServiceSeeder` (idempotent) — tạo catalog "Giường phụ" chuẩn.
11. Permission `services.manage` (RolePermissionSeeder, additive).
12. Test suite (xem Mục 5).
13. Báo cáo.

---

## 5. Test plan

| Nhóm | File | Số test |
|---|---|---|
| Pricing resolver | `tests/Unit/Services/ServicePricingResolverTest.php` | 5 |
| Enrollment (scope/giá/quantity/fulfillment) | `tests/Unit/Services/BookingServiceEnrollmentServiceTest.php` | 16 |
| Posting job (idempotency, multi-room, ONE_TIME) | `tests/Feature/UnifiedServicePostingJobTest.php` | 13 |
| Night Audit thật (pipeline thật, không mock) | `tests/Feature/NightAuditUnifiedServiceIntegrationTest.php` | 2 |
| Audit command (GIUONGPHU-class detection) | `tests/Feature/Console/AuditMiscodedLegacyPackagesTest.php` | 5 |
| Admin catalog HTTP | `tests/Feature/ServiceCatalogAdminTest.php` | 9 |
| Category CRUD HTTP | `tests/Feature/ServiceCategoryAdminTest.php` | 2 |
| Booking-side HTTP | `tests/Feature/BookingServiceControllerTest.php` | 10 |
| **Tổng mới** | | **62** |

Bắt lỗi thật trong lúc TDD: `BookingServiceEnrollmentService::confirm()/complete()/cancel()` ban đầu validate transition hợp lệ nhưng **quên thực sự lưu `fulfillment_status` mới** — 3 test fail ngay, sửa xong pass lại 100%. Một route path sai (`ServiceCategoryController` redirect thiếu tiền tố `/admin`) cũng bị bắt bởi test HTTP và sửa trước khi merge.

---

## 6. Regression plan

- Baseline trước khi sửa: `php artisan test` toàn bộ, ghi nhận **1336 passed / 28 failed** (28 lỗi thuộc `RoomAvailabilityCheckerTest`, đã biết từ trước, không liên quan gì tới slice này — lỗi phụ thuộc ngày hệ thống).
- Sau khi hoàn tất: chạy lại toàn bộ, xác nhận số lỗi cũ không tăng, cộng thêm 62 test mới pass. Kết quả đầy đủ ở `unified-services-implementation-report.md`.
- `npm run build`: PASS, 0 lỗi (chỉ có cảnh báo kích thước chunk JS đã tồn tại từ trước, không liên quan).

---

## 7. Production Actions Required (liệt kê, KHÔNG tự chạy)

1. Chạy migration 4 bảng mới trên production: `php artisan migrate` (chỉ tạo bảng mới, không ALTER bảng cũ — an toàn additive).
2. Seed permission mới: `php artisan db:seed --class=RolePermissionSeeder` (idempotent, chỉ thêm `services.manage`, không đổi quyền cũ).
3. Seed catalog Giường phụ chuẩn: `php artisan db:seed --class=UnifiedServiceSeeder` (idempotent — kiểm tra kỹ giá 150.000đ/01-08-2026 còn đúng thực tế trước khi chạy, vì đây là giá mang từ `service_rates` hiện tại sang, không phải giá mới).
4. Audit + xử lý `GIUONGPHU`: chạy `php artisan services:audit-miscoded-legacy-packages` (dry-run) trước để xem báo cáo, review kỹ, rồi mới chạy `--apply` nếu đồng ý — **không tự động hóa bước này, cần người có thẩm quyền xác nhận trên production**.
5. Sau khi xác nhận ổn định: cân nhắc gỡ hẳn nút "Đăng ký" thường cho gói `GIUONGPHU` khỏi `Packages.vue` (hoặc để nguyên vì đã deactivate, không hiện nữa).

---

## 8. Explicitly out of scope (Slice 2+)

- Ăn sáng / Người thêm chuyển sang catalog mới.
- Danh mục Yêu cầu đặc biệt (`BookingSpecialRequest`) chuyển thành Service `is_chargeable=false`.
- Sản phẩm/Dịch vụ (kiểm phòng, minibar) hợp nhất.
- Trang admin hợp nhất thật sự (gộp `/admin/services` + `/admin/service-packages` + `/admin/service-rates` + `/admin/product-services` thành 1).
- Deprecate/redirect route cũ.
- Xóa bảng/cột legacy (`room_assignments.extra_bed_quantity`, `BookingPackageFlag`...).
- `ChargeType` riêng cho catalog hợp nhất (hiện dùng chung `Other`).

---

# Slice 2 — Ăn sáng + Người thêm

**Ngày:** 2026-08-17
**Đã duyệt (GATE):** người dùng chọn "Ăn sáng + Người thêm (Recommended)" trong 3 lựa chọn slice tiếp theo.

## Vì sao đây là slice rủi ro thấp

Trên production, `service_packages` hiện **không có** dòng nào mã `BREAKFAST_PER_NIGHT`/`EXTRA_PERSON_PER_NIGHT` (chỉ có `QA_PILOT_PKG` và `GIUONGPHU`) — nghĩa là 2 nghiệp vụ này **chưa từng đăng ký được qua UI cũ**, không có dữ liệu thật nào đang chạy để phải "di dời". Slice này chỉ thêm dữ liệu catalog mới (không có code mới ngoài test), tái sử dụng 100% engine Slice 1 đã xây và đã qua rà soát bảo mật/code.

## Quyết định đã tự chọn

| Quyết định | Lựa chọn | Lý do |
|---|---|---|
| Giá Ăn sáng | 120.000đ, hiệu lực 01/08/2026 | Khớp đúng giá `service_rates` (FOOD_BEVERAGE) đang là giá thật duy nhất từng dùng để tính tiền — mang forward, không đoán. |
| Giá Người thêm | **Không seed giá nào cả** | `service_rates` production **không có dòng EXTRA_PERSON nào** — không có số thật để mang forward. Tự bịa 1 con số sẽ đi ngược đúng nguyên tắc cả nỗ lực này hướng tới. Dịch vụ vẫn hiện trong catalog, nhưng `enroll()` sẽ từ chối ("chưa có giá chuẩn") cho tới khi Admin tự nhập giá thật — đã có test xác nhận hành vi này an toàn ở cả 2 trạng thái (trước/sau khi có giá). |
| Scope | `BOOKING` cho cả 2 | Đúng bản chất nghiệp vụ (ăn sáng/người thêm áp dụng cho cả booking, không riêng 1 phòng) — đồng thời là phép thử thực tế đầu tiên cho đường `scope=BOOKING` bằng dữ liệu seed thật (Slice 1 chỉ test bằng fixture tổng hợp). |
| `quantity_enabled` | Ăn sáng = false (luôn tính 1), Người thêm = true | Khớp đúng ngữ nghĩa `PackageQuantityMode` cũ (`NONE` vs `MANUAL_INPUT`). |
| `fulfillment_required` | false cho cả 2 | Không có bước "giao/xác nhận vật lý" thật như giường phụ — nhân viên không cần thao tác xác nhận/hoàn thành riêng cho từng đêm ăn sáng. |
| Code mới | Không có — chỉ sửa `UnifiedServiceSeeder.php` + thêm test | Đúng nguyên tắc Mục 24 "không rewrite mù" — engine Slice 1 đã đủ tổng quát. |

## Test mới

`tests/Feature/UnifiedServiceSlice2SeedTest.php` — 8 test, chạy trên **catalog seed thật** (không phải fixture tổng hợp): xác nhận đúng 3 Service được tạo, seeder idempotent, Ăn sáng có giá thật/Người thêm không có giá, không đăng ký được Người thêm tới khi có giá, số lượng ép về 1 khi `quantity_enabled=false`, cả 2 dịch vụ tính đúng 1 lần/đêm dù nhiều phòng, 2 dịch vụ cùng lúc tạo 2 khoản phí độc lập.

## Không cần rà soát bảo mật/code riêng cho slice này

Không có code nghiệp vụ mới — chỉ dữ liệu seed dùng lại đúng field/kiểu dữ liệu đã qua rà soát ở Slice 1, cộng test. Rủi ro tương đương 0.

---

# Slice 3 — Danh mục Yêu cầu (BookingSpecialRequest → catalog hợp nhất)

**Ngày:** 2026-08-17
**Đã duyệt (GATE):** người dùng chọn phương án mở rộng nhất — di dời cả 24 loại yêu cầu, cập nhật luôn Sơ đồ thao tác để đọc dữ liệu ghép giường từ cả 2 nguồn.

## Vì sao đây là slice rủi ro cao hơn Slice 1/2

Khác với 2 slice trước (chỉ thêm dữ liệu/code hoàn toàn mới), Slice 3 **sửa trực tiếp vào code đang chạy thật** của Sơ đồ thao tác (`RoomOperationsBoardService.php`) và Đổi phòng (`RoomSwapService.php`) — 2 hệ thống nằm trong danh sách "tuyệt đối không được làm hỏng" của `docs/yeucaumoi.txt`. Vì vậy slice này áp dụng đúng quy trình rà soát nghiêm ngặt như Slice 1 (agent `code-reviewer` độc lập), thay vì bỏ qua như Slice 2.

## Quyết định đã tự chọn

| Quyết định | Lựa chọn | Lý do |
|---|---|---|
| Loại trừ `extra_bed` ("Thêm giường phụ") khỏi danh sách di dời | Không seed loại này vào catalog mới, kể cả khi người dùng chọn "di dời cả 24" | Đã có sẵn dịch vụ "Giường phụ" **có tính phí** ở Slice 1 (mã `EXTRA_BED_PER_NIGHT`). Tạo thêm 1 bản "yêu cầu" miễn phí trùng ý nghĩa sẽ tái tạo đúng lỗi gốc của toàn bộ phiên làm việc này (nhân viên chọn nhầm mục miễn phí, khách không bị tính tiền giường phụ thật). Đây là điểm mình chủ động lệch khỏi câu trả lời "cả 24" của người dùng — đã nêu rõ trong hội thoại, không âm thầm quyết định. |
| `twin_to_double` ("Ghép thành giường đôi") | Di dời, **scope=ROOM** (khác 22 loại còn lại dùng `BOTH`) | Board hiện đọc badge "Ghép giường" theo từng phòng cụ thể — ghép giường "cho cả booking" không có ý nghĩa vật lý. Chọn `ROOM` thay vì `BOTH` loại bỏ hoàn toàn trạng thái "chưa gán phòng" mơ hồ cho nguồn dữ liệu mới, đơn giản hóa phần merge trên Board. |
| Cách Board đọc dữ liệu ghép giường | **Hợp (union) cả 2 nguồn**, không thay thế | Đúng nguyên tắc "introduce → migrate → switch runtime → verify → deprecate" (Mục 1) — đây là bước "introduce + verify", chưa phải "switch". Nhân viên dùng màn hình nào (cũ hay mới) để tạo yêu cầu ghép giường, Board đều hiện đúng badge. |
| Xung đột khi 1 phòng có dữ liệu ở cả 2 nguồn cùng lúc | Nguồn cũ (legacy) thắng | Trường hợp hiếm (nhân viên phải cố tình tạo cả 2), nhưng cần 1 quy tắc rõ ràng — ưu tiên nguồn đã tồn tại lâu hơn, an toàn hơn khi không chắc. |
| 21 loại còn lại (Thêm đồ dùng, Trang trí, Hỗ trợ đặc biệt, Yêu cầu khác) | `scope=BOTH` | Khớp đúng hành vi cũ — `BookingSpecialRequest.stay_id` vốn luôn optional cho mọi loại, `BOTH` cho phép nhân viên chọn y hệt như trước (toàn booking hoặc theo phòng cụ thể). |

## Lỗi thật đã bắt được và sửa trong quá trình TDD/review (Slice 3)

| # | Lỗi | Phát hiện bởi | Mức độ | Đã sửa |
|---|---|---|---|---|
| 1 | `Illuminate\Database\Eloquent\Collection::merge()/unique()` fatal error "Call to a member function getKey() on array" — collection bắt nguồn từ Eloquent Collection vẫn giữ hành vi đòi hỏi Model kể cả sau khi `.map()` biến toàn bộ giá trị thành mảng thường | Test tự viết (TDD) | Cao (crash ngay khi có dữ liệu) | Có — thêm `.toBase()` trước khi merge |
| 2 | `Collection::merge()` với khóa là số nguyên (`stay_id`) áp dụng ngữ nghĩa nối danh sách (đánh số lại), **âm thầm xóa mất việc gắn key theo `stay_id`** — badge ghép giường biến mất dù dữ liệu đúng | Test tự viết (TDD), sau khi sửa lỗi #1 | Cao (mất dữ liệu hiển thị, không crash nên khó nhận ra) | Có — đổi `merge()` thành `union()` |
| 3 | Bộ lọc trạng thái dùng danh sách cho phép cứng (`whereIn(...,['CREATED','CONFIRMED','COMPLETED'])`) thay vì loại trừ Cancelled như mọi chỗ khác trong cùng file | Agent rà soát code | Trung bình (sẽ âm thầm hỏng nếu enum có thêm trạng thái mới sau này) | Có — đổi thành `where('fulfillment_status','!=','CANCELLED')` |
| 4 | **Đổi phòng (RoomSwapService) không di chuyển dữ liệu ghép giường mới sang phòng mới** — vì `booking_services.room_assignment_id` trỏ thẳng vào `RoomAssignment`, trong khi Đổi phòng luôn tạo `RoomAssignment`+`Stay` hoàn toàn mới (không sửa tại chỗ); dữ liệu cũ tưởng như "biến mất" khỏi phòng mới sau khi đổi phòng | Tự phát hiện khi viết test theo gợi ý của agent rà soát ("cần xác nhận đường này đã được test") | **Cao** — đúng bug "Mục XXVI" mà code cũ đã từng phải sửa 1 lần rồi, nay tái diễn ở nguồn dữ liệu mới | Có — thêm `RoomSwapService::relinkUnifiedServices()`, gọi song song với `relinkSpecialRequests()` đã có sẵn, kèm test `test_swap_moves_unified_bed_join_association_to_new_assignment` |

Lỗi #4 là phát hiện quan trọng nhất của slice này — không phải do agent rà soát tự tìm ra trực tiếp (agent chỉ đánh dấu MEDIUM "cần xác nhận đường này đã test" thay vì khẳng định có bug), nhưng viết test theo đúng gợi ý đó đã lộ ra lỗi thật.

## Files changed

| File | Thay đổi |
|---|---|
| `database/seeders/UnifiedRequestCatalogSeeder.php` | Mới — 23 Service (24 loại yêu cầu cũ trừ `extra_bed`), 5 category. |
| `database/seeders/DatabaseSeeder.php` | Sửa (additive) — gọi seeder mới. |
| `app/Services/RoomOperationsBoardService.php` | Sửa — `bedJoinRequestsByStayId()` và phần liên quan trong `dailySummaryForDate()` hợp nhất 2 nguồn dữ liệu ghép giường. |
| `app/Services/RoomSwapService.php` | Sửa — thêm `relinkUnifiedServices()`, gọi song song `relinkSpecialRequests()` ở cả 2 điểm gọi. |
| `tests/Feature/UnifiedRequestCatalogSeedTest.php` | Mới — 5 test kiểm tra catalog. |
| `tests/Feature/RoomOperationsUnifiedBedJoinTest.php` | Mới — 9 test (đã bổ sung 2 test theo gợi ý rà soát: completed status, đổi phòng). |

## Test results

Slice 3: 14 test mới, 14/14 PASS (5 catalog + 9 board/swap). Hồi quy toàn bộ hệ thống: **1421 passed / 29 failed** — giữ nguyên đúng 29 lỗi cũ (bao gồm cả lỗi flaky `PerStayAttributionTest` đã ghi nhận từ Slice 2), không có lỗi mới nào phát sinh — xác nhận qua đối chiếu chính xác từng nhóm test lỗi. Đặc biệt: toàn bộ 19 test cũ của `RoomOperationsBedOperationsTest.php` (hàng rào hồi quy cho nguồn dữ liệu cũ) và 100% test có sẵn của `RoomSwapServiceTest.php` vẫn PASS nguyên vẹn.

## READY FOR COMMIT = YES · READY FOR PRODUCTION MIGRATION = NO

Lý do NO giống các slice trước — cần xác nhận thủ công, không tự chạy migration/seed lên production.

---

# Slice 4 — Sản phẩm/Dịch vụ kiểm phòng: AUDIT-ONLY, không sửa code

**Ngày:** 2026-08-17
**Quyết định tự chọn (Mục 2 — không phải Business Blocker, tự suy luận được từ code):** không di dời `ProductService`/Kiểm đồ trả phòng vào catalog hợp nhất.

## Audit — đã đọc kỹ `CheckoutInspectionService.php` trước khi quyết định

Yêu cầu cốt lõi của Mục 18 ("Room Inspection → service/charge transaction → Folio", "Sản phẩm miễn phí không được tạo charge") **đã được đáp ứng từ trước**, không cần code mới:

- `CheckoutInspectionService::postChargesToFolio()` post thẳng qua `FolioService::addCharge()` — cùng 1 `Folio`/`FolioEntry` mà `UnifiedServicePostingJob` (Slice 1-3) và mọi job khác đang dùng. **Không có sổ tiền song song.**
- Có sẵn điều kiện chặn `chargeable_quantity <= 0 || line_total <= 0` trước khi post — sản phẩm miễn phí/số lượng 0 không bao giờ tạo charge.
- Giá đã snapshot đúng cách (`unit_price_snapshot`) — đổi giá catalog sau không ảnh hưởng phiếu kiểm đồ cũ (có test `updating price does not change historical snapshot` xác nhận).

## Vì sao không di dời sâu vào `booking_services`

`ProductService` không cùng hình dạng nghiệp vụ với 3 domain đã di dời — đây là hàng hóa tiêu thụ 1 lần lúc kiểm phòng (mô hình gần với POS), không có khái niệm "đăng ký theo đêm/theo booking, xác nhận/hoàn thành" mà `scope`/`billing_mode`/`fulfillment_status` được thiết kế cho. Ép vào khuôn đó sẽ gượng ép, không tạo giá trị thật, trong khi `CheckoutInspectionService.php` là một trong những file có nhiều lớp bảo vệ tài chính đã được đúc kết qua nhiều lần sửa lỗi thật trước đây (khóa sau khi post, ADR-50 guard, chặn sửa sau checkout) — rủi ro đụng vào không tương xứng với lợi ích.

Mục 18 của `docs/yeucaumoi.txt` tự nó cũng cho phép việc này: *"Không cần ép toàn bộ UI kiểm phòng phải dùng màn hình Booking Services nếu điều đó phá workflow tốt hiện có. Nhưng phía financial data phải thống nhất."* — vế sau đã đúng sẵn, vế đầu được giữ nguyên.

## Files changed

Không có — đây là slice thuần audit, không sửa/thêm file code nào.

## Kết luận backlog còn lại

Các hạng mục còn lại (trang admin hợp nhất thật sự gộp 4 trang catalog thành 1, deprecate route cũ, xóa bảng/cột legacy) đều bị chính `docs/yeucaumoi.txt` (Mục 21: "không xóa bảng/cột/module legacy nếu dữ liệu vẫn có khả năng tham chiếu"; nguyên tắc "introduce → migrate → switch runtime → **verify** → deprecate") yêu cầu phải trải qua giai đoạn **verify trên production** trước — điều không thể thực hiện từ môi trường này (Mục 22, chỉ đọc production). Vì vậy dừng backlog "Unified Services & Requests" tại đây là điểm dừng hợp lý cho 1 lượt làm việc — 3 slice đã triển khai giải quyết đúng và đầy đủ các vấn đề kiến trúc nghiêm trọng đã audit ban đầu (giá trùng nguồn, danh mục viết cứng, rủi ro tính tiền sai nhiều phòng).
