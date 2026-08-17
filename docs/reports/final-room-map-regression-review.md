# Final Room Map Regression Review

**Ngày:** 2026-08-17 · **Nhánh:** `phase-3` · HEAD trước lượt này: `ee0d1aa`

## Baseline (trước khi sửa)

```
php artisan test
28 failed, 1344 passed (5156 assertions)
```

28 lỗi: `BookingManagementUiTest` (11, room-board), `DashboardTest` (3), `ReleaseBatchSchemaTest` (1), `RoomAvailabilityCheckerTest` (13) — toàn bộ đã biết từ trước, không liên quan các lượt trước đó của phiên làm việc này.

`npm run build`: sạch, 2387 module.

## Kết quả sau khi sửa (5 commit)

```
php artisan test
28 failed, 1350 passed (5173 assertions)
```

**Danh sách 28 lỗi giống hệt danh sách baseline, cùng tên test, cùng nhóm — 0 lỗi mới.** +6 test pass mới (3 `RoomOperationsHistoricalOccupancyTest` + 3 `RoomChargeMultiRateGroupTest`), +17 assertion.

`npm run build`: sạch sau mỗi commit liên quan frontend (be8488d, 3c9d02f, d9c1c9d).

## Critical Regressions

**Không có.** Không có failing suite nào phát sinh mới.

## Findings từ Code Review (tự thực hiện qua đọc + test trực tiếp, không dispatch subagent riêng cho lượt này do phạm vi mỗi thay đổi nhỏ/độc lập và có test tự động xác nhận ngay)

- **Financial / Room Rate**: `RoomChargePostingJob`/`PaymentProjectionService` fix đã xác minh qua 165 test hiện có (RoomCharge/PaymentProjection/NightAudit/RoomAssignment) PASS toàn bộ + 3 test mới chứng minh chính xác kịch bản mục 37. Không phát hiện double-posting (idempotency key không đổi, chỉ đổi NGUỒN đọc giá).
- **Historical Occupancy**: xác nhận qua test trực tiếp — checked-out hiện đúng trong khoảng, biến mất ngoài khoảng, KHÔNG hiện sai trên view "hôm nay" (guard test riêng cho case này). Released/Cancelled/NoShow xác nhận qua code-read không được đưa vào historical view (không viết test riêng cho case Released vì logic đã tự nhiên loại trừ qua whereIn status — coverage gián tiếp qua các test hiện có không tạo Released nào bị hiện sai).
- **Frontend**: thay đổi ở `RoomOperationsCell.vue`/`Show.vue`/`RoomAvailability/Index.vue` đều là CSS/style/markup, không đổi props/emit contract ngoài 1 event mới thêm (`select-booking-rooms`, additive). `npm run build` xác nhận không có lỗi biên dịch. Không có dead-code sót lại (đã xóa `anyModalOpen`, import `Eye` không dùng, computed `occupancyStripClass` thử-rồi-bỏ).

Không phát hiện HIGH/CRITICAL nào cần sửa trước khi commit.

## READY FOR COMMIT = YES (đã commit)

## READY FOR PRODUCTION MIGRATION = NO

(Không deploy production theo đúng chỉ dẫn. `RoomChargePostingJob` fix ảnh hưởng số tiền thực tế — cần review thủ công + theo dõi kỹ khi rollout, dù test đã xác nhận không hồi quy trên toàn bộ suite hiện có.)
