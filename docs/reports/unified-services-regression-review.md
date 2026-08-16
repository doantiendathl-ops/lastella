# Unified Services & Requests — Final Regression Review (Slice 1)

**Ngày:** 2026-08-16

---

## Xác nhận theo đúng danh sách Mục 33 của `docs/yeucaumoi.txt`

| Câu hỏi | Trả lời |
|---|---|
| Runtime errors? | Không. |
| PHP/Laravel errors? | Không. |
| JS build errors? | Không — `npm run build` PASS, 0 lỗi. |
| Console errors? | Không kiểm tra được trực tiếp qua trình duyệt thật trong môi trường này (không có UI chạy sống để mở DevTools) — nhưng đã rà soát tay: không có `console.log`/`dd`/`dump`/`var_dump` nào còn sót trong toàn bộ file mới. |
| Migration errors? | Không — `php artisan migrate --pretend` xác nhận SQL đúng trước khi chạy thật; `php artisan migrate --force` chạy thành công trên DB dev local, cả 4 bảng tạo đúng, có FK đúng, có `down()` để rollback. |
| Duplicate posting? | **Có phát hiện 1 lỗi nghiêm trọng trong lúc phát triển** (tính phí trùng cho dịch vụ phạm vi toàn booking, nhiều phòng) — đã sửa và có test hồi quy xác nhận. Sau khi sửa: không còn duplicate posting nào, xác nhận qua test retry/idempotency (13 test riêng cho khía cạnh này). |
| Incorrect multi-room billing? | Đã sửa (xem trên) — có 5 test riêng phủ đúng kịch bản 3 phòng, số lượng khác nhau từng phòng, không được tính chéo sang phòng khác. |
| Missing nightly billing? | Không — test `test_one_room_multiple_nights_posts_a_separate_charge_per_night` xác nhận mỗi đêm được tính đúng 1 lần liên tiếp. |
| Historical price corruption? | Không — test `test_changing_the_canonical_price_later_never_changes_an_existing_snapshot` xác nhận rõ ràng: đổi giá chuẩn sau khi đã có giao dịch không làm thay đổi giá đã snapshot vào giao dịch cũ. |
| Folio regression? | Không — `UnifiedServicePostingJob` tạo `FolioEntry` đúng cấu trúc hiện có, không tạo bảng/luồng tài chính song song nào khác. |
| Night Audit regression? | Không — test `NightAuditUnifiedServiceIntegrationTest` chạy qua **pipeline thật** (không mock job), xác nhận cả 7 job (6 job cũ + 1 job mới) cùng chạy trong 1 lượt không xung đột, không lỗi. |
| Room Inspection regression? | Không đụng tới — hoàn toàn ngoài phạm vi Slice 1, không file nào liên quan bị sửa. |
| Booking regression? | Không — chỉ thêm 1 quan hệ Eloquent mới (`Booking::bookingServices()`), không sửa quan hệ/logic nào có sẵn. Test hồi quy toàn bộ xác nhận 0 lỗi mới. |
| Customer Display regression? | Không đụng tới — không file nào liên quan bị sửa. |
| KDS regression? | Không đụng tới — không file nào liên quan bị sửa. |

---

## Số liệu hồi quy toàn bộ hệ thống

| | Baseline (trước khi sửa) | Sau Slice 1 |
|---|---|---|
| Tests passed | 1336 | 1400 (+64, đúng bằng số test mới) |
| Tests failed | 28 | 28 (giữ nguyên số lượng) |
| Nhóm test lỗi | `RoomAvailabilityCheckerTest`, `BookingManagementUiTest`, `DashboardTest`, `ReleaseBatchSchemaTest` | **Y hệt 4 nhóm trên, không thêm nhóm nào mới** |

28 lỗi này đã tồn tại từ trước khi bắt đầu Slice 1 (xác nhận bằng lần chạy baseline độc lập trước khi sửa bất kỳ file nào), không liên quan tới bất kỳ file nào Slice 1 đã tạo/sửa — xác nhận qua đối chiếu danh sách file thay đổi (Mục "Files changed" trong implementation report) với danh sách 4 test suite lỗi ở trên: không giao nhau.

---

## READY FOR COMMIT = YES

Điều kiện đã đủ theo Mục 34: automated tests phù hợp PASS (66/66 test mới, 1400/1428 tổng — 28 lỗi là baseline có từ trước), build PASS, migration logic đã review (4 bảng additive, có `down()`), regression review không có blocker (bảng trên).

## READY FOR PRODUCTION MIGRATION = NO

**Lý do NO — không phải vì có lỗi, mà vì cần xác nhận thủ công trước khi chạm vào production, đúng theo Mục 22/Mục 34 (không tự chạy migration/seed/audit-apply lên production trong task này):**

1. Cần Admin xác nhận giá 150.000đ/giường/đêm (hiệu lực 01/08/2026) trong `UnifiedServiceSeeder` vẫn còn đúng thực tế tại thời điểm chạy trên production.
2. Cần người có thẩm quyền tự chạy `php artisan services:audit-miscoded-legacy-packages` (dry-run) trên production trước, xem báo cáo, rồi mới quyết định `--apply`.
3. Cần xác nhận permission `services.manage` được gán đúng vai trò mong muốn trên production (hiện đang theo đúng seeder: ADMIN + MANAGER).

Danh sách chính xác các lệnh cần chạy trên production nằm ở `unified-services-implementation-plan.md` Mục 7 "Production Actions Required".

---

## Bổ sung sau Slice 2 (Ăn sáng + Người thêm) — 2026-08-17

Slice 2 không thêm code nghiệp vụ mới (chỉ mở rộng `UnifiedServiceSeeder.php` + 8 test mới `tests/Feature/UnifiedServiceSlice2SeedTest.php`, chạy trên dữ liệu seed thật thay vì fixture tổng hợp). Không cần rà soát bảo mật/code riêng — không có đường code mới nào ngoài phạm vi đã được rà soát ở Slice 1.

| | Sau Slice 1 | Sau Slice 2 |
|---|---|---|
| Tests passed | 1400 | 1407 |
| Tests failed | 28 | 29 |

**Lỗi tăng thêm 1 — đã xác minh không liên quan tới Slice 2:** `Tests\Feature\PerStayAttributionTest > add charge accepts system auto posting source`, lỗi `UNIQUE constraint violation` trên `resources.code = 'RM-102'`. Đây là **flaky test có sẵn từ trước**, không nằm trong bất kỳ file nào Slice 1/2 đã sửa (`PerStayAttributionTest` thuộc module Kiểm phòng/Resource, hoàn toàn ngoài phạm vi) — nguyên nhân là factory tạo mã phòng ngẫu nhiên bị trùng giữa 2 test case khác nhau trong cùng 1 lượt chạy toàn bộ suite (không tất định, phụ thuộc thứ tự/seed ngẫu nhiên của Faker). Không tái hiện khi chạy riêng `PerStayAttributionTest`. Ghi nhận minh bạch, không sửa vì ngoài phạm vi Slice 1/2 — nếu muốn xử lý triệt để cần factory phòng dùng chuỗi duy nhất (`sequence()`/`unique()`) thay vì số ngẫu nhiên có thể trùng.

**Kết luận không đổi:** READY FOR COMMIT = YES, READY FOR PRODUCTION MIGRATION = NO (cùng lý do đã nêu, cộng thêm: cần Admin tự nhập giá thật cho "Người thêm" trước khi dịch vụ này dùng được — cố tình để trống, không đoán giá).
