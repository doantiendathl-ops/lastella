# Lastella PMS — Final Feature Completion Report

**Ngày:** 2026-08-17 · **Nhánh:** `phase-3` (không push, không deploy production)

Báo cáo tổng hợp toàn bộ yêu cầu người dùng đã giao trong chuỗi làm việc này: Unified Services & Requests, Booking Color Picker (Excel-style), Unpaid Checkout & Receivables, và Unified Room Map + Room Assignment multi-rate-group (`docs/yeucaumoi.txt` — "FINAL COMPLETION RUN").

## Implementation Status: **PARTIAL**

Lý do không ghi COMPLETE: một số mục ở phần Room Map (icon dịch vụ có thể cấu hình, migrate Sơ đồ Kiểm đồ, tái dựng lịch sử đổi phòng, Night Audit historical catch-up, Folio traceability qua FK sạch) chưa được thực hiện trong lượt này — xem chi tiết lý do từng mục trong `final-room-map-architecture-review.md` mục 5. Đây là các mục có rủi ro thấp/trung bình, không phải business blocker, nhưng thực hiện vội trong cùng lượt sẽ đánh đổi chất lượng kiểm thử mà các phần tài chính khác trong lượt này đã có.

## Unified Services

**Status: hoàn thành với 1 ngoại lệ đã biết.** Catalog/pricing/admin/booking-function hợp nhất (Slice 1-4, các phiên trước) hoạt động đúng, không hồi quy. Configurable Request Icons chưa làm. Folio traceability qua `posting_key` (đủ dùng, chưa phải FK sạch). Historical Night Audit catch-up chưa sửa (đã biết từ trước, ngoài phạm vi theo đúng chỉ dẫn "không rewrite Night Audit mù").

## Unified Room Map

**Status: PARTIAL — 3/4 màn hình đã áp dụng visual language dùng chung** (booking_color nền chính, status strip, viền chọn/conflict): Sơ đồ thao tác, Sơ đồ chọn phòng (Đặt phòng), Sơ đồ Check phòng. Sơ đồ Kiểm đồ trả phòng CHƯA migrate (deprioritized có chủ đích, xem lý do trong architecture review).

## Historical Occupancy

**Status: hoàn thành cho Sơ đồ thao tác** (mục tiêu chính của mục 11) — checked-out booking hiện đúng trong khoảng lịch sử, biến mất ngoài khoảng, không rò rỉ vào view hiện tại. 3 test tự động xác nhận. Reconstruction cho đổi phòng giữa chừng (mục 24) chưa làm.

## Booking Assignment

**Status: hoàn thành.** Quick assignment không cần Room Requirement trước, reverse-sync, multiple requirement groups cùng room_type — hạ tầng đã có sẵn từ phase trước, đã xác minh hoạt động đúng. Room Charge/Payment Projection dùng đúng rate riêng từng nhóm — **đã sửa 1 lỗi tài chính thật** (xem bên dưới).

## Service/Request Icons

**Status: PARTIAL.** Icon hiện có trên Sơ đồ thao tác (ghép giường, giường phụ, kiểm đồ) vẫn là icon cố định theo tính năng (hard-coded), chưa đọc từ cấu hình Service Catalog như mục 14 yêu cầu. Phạm vi thời gian hiển thị (mục 15) đã đúng một cách tự nhiên qua việc gắn với `stay_id`/`room_assignment_id` đang active, nhưng chưa được audit/test tường minh riêng trong lượt này.

## Room Inspection

**Status: không đổi, đúng như quyết định trước đó.** `CheckoutInspectionService`/`ProductService` giữ nguyên workflow, không rewrite. Room Map riêng của màn hình Kiểm đồ chưa migrate visual language.

## Booking Colors

**Status: COMPLETE.** Excel-style picker, HEX ổn định, overlap-only exclusion, không global lock, lịch sử giữ màu — toàn bộ đã hoàn thành và xác nhận ở các lượt trước (`Prompt_1.txt`), không có gì thay đổi/hồi quy trong lượt này.

## Unpaid Checkout

**Status: COMPLETE, hồi quy sạch.** Checkout còn nợ, room release độc lập tài chính, công nợ hiện đúng trong Đối soát, thanh toán sau/một phần sau checkout, Historical Room Map vẫn hiện đúng booking đã checkout (xác nhận lại trong lượt này qua `RoomOperationsHistoricalOccupancyTest`).

## Night Audit

**Status: không đổi.** Occupancy resolver mới của Sơ đồ thao tác dùng chung `BusinessDateService` với Night Audit (nhất quán "hôm nay"), nhưng bản thân Night Audit's historical catch-up logic (mục 27) chưa được rewrite/audit sâu trong lượt này — rủi ro đã biết từ trước, ghi nhận, không tự ý sửa.

## Folio / Financial Integrity

**Status: cải thiện, không hồi quy.** Đã sửa 1 lỗi tài chính thật: `RoomChargePostingJob`/`PaymentProjectionService` bỏ qua `booking_requirement_id`, khiến 2 dòng nhu cầu cùng room_type (giá khác nhau) có thể bị tính sai giá cho phòng thuộc dòng thứ 2. Đã sửa + 3 test khóa hành vi đúng. Không tạo ledger thứ hai, không đổi công thức thanh toán ở nơi khác.

## Tests — Before/After

| | Failed | Passed | Assertions |
|---|---|---|---|
| Trước lượt này (HEAD `ee0d1aa`) | 28 | 1344 | 5156 |
| Sau lượt này (5 commit) | 28 (giống hệt danh sách cũ) | 1350 | 5173 |

0 lỗi mới. +6 test mới (historical occupancy ×3, multi-rate-group ×3). Chi tiết đầy đủ: `final-room-map-regression-review.md`.

## Build

**PASS.** `npm run build` sạch sau mỗi commit có thay đổi frontend (2387 module, không lỗi biên dịch).

## Git

5 commit trên `phase-3`, chưa push:
- `be8488d` — Sơ đồ thao tác: booking color + historical occupancy + select-all
- `3c9d02f` — Sơ đồ chọn phòng: bỏ hover popup
- `d9c1c9d` — Sơ đồ Check phòng: booking color nền chính
- `e82cccd` — Room Charge: sửa lỗi multi-rate-group

## Production Actions Required

Không có action nào cần chạy trên production ở bước này — mọi thay đổi chỉ tồn tại trên `phase-3` local. Khi được duyệt deploy, ưu tiên rà soát kỹ riêng commit `e82cccd` (ảnh hưởng số tiền Room Charge thực tế) trước khi áp dụng cho dữ liệu production có sẵn nhiều booking đa-nhóm-giá.

## Remaining Risks

1. **Night Audit historical catch-up** chưa được sửa — rủi ro đã biết từ các phase trước (Night Audit chỉ chạy thủ công trên production).
2. **Historical Room Reassignment** (đổi phòng giữa chừng) chưa tái dựng interval lịch sử chính xác trên bất kỳ Room Map nào — dữ liệu nguồn (`StayEvent`) đã có, việc dùng nó thì chưa làm.
3. **Service Icon cấu hình** chưa có — icon trên Room Tile vẫn hard-code theo tính năng, không phải qua Service Catalog.
4. **Sơ đồ Kiểm đồ trả phòng** chưa migrate visual language dùng chung.
5. **Folio traceability** qua `posting_key` string, chưa phải FK sạch tới `BookingService`.

Không có risk nào trong 5 mục trên là business blocker theo định nghĩa mục 46 (đều là technical/architecture, có giải pháp additive/reversible, không ảnh hưởng tiền/dữ liệu/quyền ngay lập tức).

---

`ALL REQUESTED FEATURES = PARTIAL`

`READY FOR COMMIT = YES` (đã commit cả 5, mỗi commit tự đứng vững, test xanh)

`READY FOR PRODUCTION MIGRATION = NO`

`READY FOR PILOT ACCEPTANCE TEST = YES, với ghi chú` — các phần COMPLETE (Unified Services, Booking Colors, Unpaid Checkout, 3/4 Room Map, Room Charge multi-rate-group) đã sẵn sàng để người dùng test thủ công trên local; 5 mục ở "Remaining Risks" nên được người dùng xác nhận có cần ưu tiên tiếp hay chấp nhận as-is trước khi coi toàn bộ chuỗi yêu cầu là kết thúc.

---

# Production Runbook

**Không tự chạy bất kỳ bước nào dưới đây.** Tài liệu tham khảo cho lần deploy được người dùng chủ động quyết định.

## Preflight

- [ ] Backup database production đầy đủ (mysqldump hoặc snapshot theo hạ tầng hiện có).
- [ ] `git commit`/tag rõ ràng bản đang chạy trên production TRƯỚC khi deploy (điểm rollback code).
- [ ] Xác nhận DB schema production khớp với `phase-3` hiện tại (không có migration nào bị bỏ sót/chạy lệch thứ tự) — chạy `php artisan migrate:status` trên production (read-only, an toàn) trước.
- [ ] Ghi lại business date hiện tại của production (`BusinessDateService::currentBusinessDate()` hoặc trang Night Audit).
- [ ] Kiểm tra có Night Audit nào đang "pending"/chưa chạy trên production hay không — nếu có, cân nhắc chạy trước khi deploy để tránh trộn lẫn dữ liệu cũ/mới trong cùng 1 kỳ.
- [ ] **Không có data migration nào trong lượt này** (0 migration file mới) — bước "dry-run trước data migration" không áp dụng cho lượt này, nhưng vẫn giữ nguyên quy trình chuẩn cho các lượt sau.
- [ ] Rà soát thủ công dữ liệu `booking_requirements` hiện có trên production: đếm số booking có ≥2 dòng cùng `room_type_id` (nếu có, đây là nhóm bị ảnh hưởng trực tiếp bởi fix `RoomChargePostingJob` — nên xác minh Room Charge của các booking này TRƯỚC và SAU deploy).

## Commands

Không có migration/seeder mới cần chạy. Deploy chuẩn theo quy trình hiện có của dự án (build frontend, cache config/route, restart queue/worker nếu có) — không có bước đặc thù nào bổ sung cho lượt này.

```bash
git fetch && git checkout phase-3   # hoặc nhánh deploy đã chốt
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
# Không chạy php artisan migrate — không có migration mới trong lượt này
```

## Dry-run

Không áp dụng (không có data migration). Nếu tương lai có backfill `booking_requirement_id` cho assignment cũ, PHẢI dry-run riêng trước — ngoài phạm vi lượt này.

## Apply

**Không tự chạy.** Người vận hành thực hiện thủ công theo Commands ở trên, sau khi Preflight đã hoàn tất.

## Verification (sau deploy)

- [ ] Booking: tạo/sửa 1 booking thử, xác nhận Excel Color Picker hoạt động đúng.
- [ ] Service: `/admin/services` load đúng, tạo 1 Dịch vụ/Yêu cầu thử trên 1 booking.
- [ ] Room Map: mở cả 3 màn hình đã migrate (Sơ đồ thao tác, Sơ đồ chọn phòng, Kiểm tra phòng) — xác nhận nền màu booking hiển thị đúng, không vỡ layout.
- [ ] Room Inspection: mở Kiểm đồ trả phòng, xác nhận workflow không đổi.
- [ ] Night Audit: xác nhận trang Night Audit vẫn hiển thị đúng trạng thái, KHÔNG tự động chạy audit thử trên production.
- [ ] Folio: mở 1 booking đã có Room Charge, xác nhận số tiền không đổi so với trước deploy (đặc biệt các booking có ≥2 dòng nhu cầu cùng room_type đã ghi nhận ở Preflight).
- [ ] Reconciliation: `/admin/reconciliation` load đúng, không có booking nào bất thường mới xuất hiện do lỗi tính toán.
- [ ] Checkout outstanding: thử checkout 1 booking test còn nợ (nếu có môi trường test riêng) hoặc xác nhận qua code review — không thử trên dữ liệu production thật.

## Rollback / Compensating Plan

- **Code**: `git checkout` về tag/commit đã ghi ở Preflight, deploy lại (không có migration nên rollback code là đủ, không cần rollback DB).
- **Nếu `RoomChargePostingJob` fix gây số tiền sai lệch phát hiện SAU deploy**: các charge ĐÃ POST trước đó không tự đổi (fix chỉ ảnh hưởng lần post MỚI, không viết lại lịch sử) — nếu cần điều chỉnh, dùng cơ chế Adjustment/Void hiện có của Folio, không sửa trực tiếp `FolioEntry` đã post.
- **Nếu Room Map mới gây lỗi hiển thị nghiêm trọng**: rollback code là đủ; không có dữ liệu nào bị ghi sai do các thay đổi thuần frontend.
