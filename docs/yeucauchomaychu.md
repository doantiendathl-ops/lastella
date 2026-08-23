# Hướng dẫn cập nhật pms.lastella.com.vn — đưa lên ngang bằng `phase-3`

**Ngày soạn:** 2026-08-23 · **Nhánh nguồn:** `origin/phase-3` @ `fc5d59e` · **Người soạn:** Claude (máy dev), theo yêu cầu Product Owner.

**Cách dùng file này:** dán nguyên văn phần "LỆNH CHO CLAUDE TRÊN MÁY CHỦ" bên dưới cho Claude đang chạy trực tiếp trên máy chủ production. Claude ở máy dev (soạn file này) **không có quyền truy cập trực tiếp vào máy chủ đó** — mọi thao tác thực tế do Claude trên máy chủ tự thực hiện.

Đợt này giả định production đang chạy đúng `194b7a4` (đợt cập nhật lớn trước, gồm Unified Services/Room Map hợp nhất/quyền thật/Night Audit tự động 00:00 — đã xác nhận triển khai xong qua `docs/reports/night-audit-auto-schedule-deployment-6ef58d7.md` trên máy chủ). Nếu `git log -1` trên máy chủ KHÔNG cho ra `194b7a4` (hoặc một commit sau đó trên `phase-3`) — DỪNG LẠI, báo lại cho máy dev biết production đang ở đâu trước khi làm tiếp, vì file này chỉ mô tả đúng 2 commit mới (`a9089c0`, `fc5d59e`), không phải toàn bộ lịch sử.

---

## Bối cảnh (để Claude trên máy chủ hiểu VÌ SAO, không chỉ làm theo lệnh mù)

Chỉ 2 commit mới kể từ `194b7a4`:

### 1. `a9089c0` — Night Audit: cửa sổ chờ xác nhận 24h ("Tính lại")

Bối cảnh: `docs/reports/revenue-audit-night-audit-never-run-2026-08-22.md` (đã lên git từ trước) phát hiện một khoản Night Audit đã ghi thì **không ai sửa được nữa, kể cả ADMIN** (ADR-50: bất kỳ dòng nào có `posting_key` là bất khả xâm phạm vĩnh viễn). Nghĩa là nếu Night Audit ghi sai (giá phòng sai, thiếu giường phụ, thiếu gói dịch vụ) thì không có cách nào sửa ngoại trừ can thiệp thủ công vào DB.

Thay đổi: một lượt Night Audit **ĐÃ HOÀN TẤT** giờ vẫn sửa được (hủy dòng cũ + bấm nút "Tính lại" để ghi lại) cho tới khi **lượt kế tiếp bắt đầu chạy** — lúc đó hệ thống tự động "khóa" (xác nhận) lượt trước, các dòng của nó trở lại bất khả xâm phạm như cũ. Riêng dòng phí của MỘT lưu trú cụ thể sẽ khóa NGAY LẬP TỨC khi khách đó trả phòng (không cần chờ lượt Night Audit kế tiếp) — tránh việc khách đã rời khách sạn với hóa đơn cuối cùng mà hóa đơn đó vẫn có thể bị "Tính lại" âm thầm sau lưng.

**⚠️ Có migration ĐỔI SCHEMA bảng đang có dữ liệu thật (khác các đợt trước — trước giờ chỉ tạo bảng mới):**

- **Xóa UNIQUE constraint trên cột `folio_entries.posting_key`**, thay bằng index thường (không unique). Đây là điều kiện bắt buộc để hủy-rồi-ghi-lại một dòng dùng đúng key cũ — trước đây DB sẽ từ chối ngay cả khi dòng cũ đã bị hủy (`voided_at` khác null). Tính duy nhất *giữa các dòng CHƯA bị hủy* vẫn được đảm bảo ở tầng ứng dụng (mỗi posting job đã tự khóa `lockForUpdate()` + kiểm tra trùng key trước khi ghi — cơ chế chống trùng THẬT SỰ vẫn còn nguyên, ràng buộc DB chỉ là lớp phòng thủ thứ hai, giờ bỏ đi).
- Thêm cột `folio_entries.night_audit_run_id` (khóa ngoại, được phép NULL) và `folio_entries.finalized_at` (được phép NULL).
- Thêm cột `night_audit_runs.confirmed_at` (được phép NULL).
- Không xóa dữ liệu, không đổi kiểu cột nào khác. Migration có `down()` đầy đủ để lùi lại nếu cần.

**⚠️ Hành vi với dữ liệu Night Audit CŨ (trước ngày cập nhật này) — đọc kỹ để tránh hiểu lầm khi kiểm thử:**

- Toàn bộ dòng `folio_entries` đã ghi TRƯỚC migration này có `night_audit_run_id = NULL` (không tự gán lại) → chúng **giữ nguyên hành vi cũ, bất khả xâm phạm vĩnh viễn** — cửa sổ 24h chỉ áp dụng cho các lượt Night Audit chạy **SAU** khi cập nhật.
- Tuy nhiên, các lượt Night Audit CŨ đã hoàn tất (COMPLETED) trước đây sẽ có `confirmed_at = NULL` (cột mới, không tự gán) — nghĩa là màn hình `/admin/night-audit` **sẽ hiện nút "Tính lại" ngay cả trên các lượt cũ**, dù bấm vào đó sẽ không hủy được gì (vì các dòng của lượt đó không gắn `night_audit_run_id`) — hệ thống posting có kiểm tra trùng theo `posting_key` nên sẽ tự báo "đã ghi rồi, bỏ qua", **không tạo dòng phí trùng, không mất tiền/thừa tiền**. Rủi ro duy nhất là làm rối nhật ký (thời gian chạy của lượt cũ bị ghi đè thành "vừa chạy lại") — **khuyến nghị: không bấm "Tính lại" cho bất kỳ lượt Night Audit nào có ngày TRƯỚC ngày triển khai đợt này**, chỉ dùng cho lượt mới phát sinh sau khi cập nhật. Không phải lỗi cần sửa code — chỉ là điều cần biết trước khi thao tác.

- Endpoint mới `POST admin/night-audit/{id}/recalculate`, dùng lại đúng quyền `night_audit.run` đã có sẵn — **không cần cấp quyền mới nào cho đợt này.**

### 2. `fc5d59e` — Sơ đồ thao tác: thiết kế lại bản in A4

Bối cảnh: bản in A4 cũ (`c6d673e`, khổ ngang, 11 cột/phòng) theo yêu cầu Product Owner được thiết kế lại 2 lần trong cùng 1 buổi làm việc — bản MỚI thay thế hoàn toàn bản cũ:

- **Khổ dọc (portrait)** thay vì khổ ngang, giới hạn thực tế trong 1 trang (đã đo trực tiếp trên trình duyệt với dữ liệu thật của khách sạn — vừa gọn).
- Mỗi phòng còn đúng 3 cột: **Số phòng | Thông tin (tên khách + 1 trong 5 trạng thái: Đã nhận phòng/Dự kiến hôm nay nhận phòng/Phòng lưu/Đã trả phòng/Dự kiến trả phòng + ghi chú nhanh + yêu cầu đặc biệt viết rõ chữ) | Trạng thái phòng (Sạch/Bẩn)**.
- Trang chia thành 2 khung có viền rõ ràng đặt cạnh nhau (không phải 1 bảng liền) để tận dụng khổ giấy dọc.
- Thuần frontend + 1 hàm đọc dữ liệu mới (`specialRequestsByStayId()` trong `RoomOperationsBoardService`, chỉ ĐỌC, không ghi) — **không có migration, không có quyền mới.**

**4 migration mới trong đợt này:** chỉ 1 — `2026_08_23_000001_add_night_audit_pending_confirmation` (mô tả chi tiết ở trên). Không có bảng nào bị xóa.

---

## LỆNH CHO CLAUDE TRÊN MÁY CHỦ (dán nguyên văn)

```
Cập nhật Lastella PMS (pms.lastella.com.vn) lên ngang bằng origin/phase-3
(commit mới nhất hiện tại: fc5d59e). Đợt này chỉ có 2 commit kể từ 194b7a4,
nhưng CÓ 1 migration đổi schema bảng đang có dữ liệu thật (xóa UNIQUE
constraint trên folio_entries.posting_key) — làm cẩn thận theo đúng thứ tự,
DỪNG LẠI hỏi tôi ngay khi có bất kỳ điều gì bất thường.

═══════════════════════════════════════════════════════════════
BƯỚC 0 — PREFLIGHT (bắt buộc, không bỏ qua bước nào)
═══════════════════════════════════════════════════════════════

1. Xác nhận commit hiện tại bằng `git log -1 --format="%H %s"`. Phải là
   194b7a4 hoặc một commit sau đó trên phase-3. Nếu không phải — DỪNG LẠI,
   báo tôi trước khi làm gì tiếp (file này không mô tả đúng khoảng cách nếu
   production đang ở một điểm khác).

2. Backup database đầy đủ (mysqldump toàn bộ DB production) TRƯỚC khi đổi
   bất cứ gì — bắt buộc, vì đợt này có migration đổi constraint trên bảng
   folio_entries đang có dữ liệu thật. Ghi rõ đường dẫn file backup.

3. Chạy `git status`. Nếu có bất kỳ thay đổi cục bộ nào chưa commit — DỪNG
   LẠI, liệt kê chính xác file bị thay đổi, báo tôi TRƯỚC khi làm tiếp.

4. Chạy `git fetch origin` rồi `git log HEAD..origin/phase-3 --oneline` —
   phải thấy đúng 2 dòng: "feat(night-audit): add 24h pending-confirmation
   window for postings" và "feat(room-operations): redesign A4 print view
   to portrait, 1 page, 3 columns". Nếu thấy commit nào khác — DỪNG LẠI,
   báo tôi.

5. Chạy `php artisan migrate:status` — ghi lại kết quả TRƯỚC khi cập nhật.

6. **Kiểm tra trước khi xóa UNIQUE constraint** — chạy SQL sau để xác nhận
   không có gì bất thường đang chờ migration xử lý (không nên có, migration
   không phụ thuộc dữ liệu, nhưng kiểm tra cho chắc):

   SELECT posting_key, COUNT(*) FROM folio_entries
   WHERE posting_key IS NOT NULL AND voided_at IS NULL
   GROUP BY posting_key HAVING COUNT(*) > 1;

   Kết quả PHẢI rỗng (0 dòng) — vì UNIQUE constraint hiện tại đã đảm bảo
   điều này từ trước tới nay. Nếu có bất kỳ dòng nào — DỪNG LẠI, báo tôi
   ngay, đây là dấu hiệu dữ liệu đã bất thường từ trước, không liên quan gì
   đến migration này.

═══════════════════════════════════════════════════════════════
BƯỚC 1 — CẬP NHẬT CODE
═══════════════════════════════════════════════════════════════

git checkout phase-3
git pull origin phase-3
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

Nếu `git pull` báo conflict — DỪNG LẠI, báo tôi, không tự ý chọn
"ours"/"theirs" hay force-merge.

═══════════════════════════════════════════════════════════════
BƯỚC 2 — MIGRATION (1 migration, ĐỔI schema bảng đang có dữ liệu — đọc kỹ)
═══════════════════════════════════════════════════════════════

1. Chạy `php artisan migrate:status`, xác nhận CHỈ đúng 1 migration mới
   xuất hiện: `2026_08_23_000001_add_night_audit_pending_confirmation`.
   Nếu thấy migration nào khác lạ — DỪNG LẠI, báo tôi trước khi chạy.
2. `php artisan migrate`.
3. Sau khi chạy xong, xác nhận lại:
   - `folio_entries` có thêm 2 cột `night_audit_run_id` (nullable) và
     `finalized_at` (nullable); KHÔNG còn UNIQUE trên `posting_key` (có thể
     kiểm bằng `SHOW INDEX FROM folio_entries;` — không còn dòng nào
     `Non_unique = 0` cho cột `posting_key`).
   - `night_audit_runs` có thêm cột `confirmed_at` (nullable).
   - Không có dữ liệu nào bị mất — `SELECT COUNT(*) FROM folio_entries;`
     phải cho đúng số lượng như trước migrate (đối chiếu với Bước 0.5 nếu
     cần, hoặc so `SELECT COUNT(*)` trước/sau nếu bạn tự lưu lại).

**Không có bước seed nào trong đợt này** (không có danh mục dịch vụ/yêu cầu
mới) — bỏ qua hoàn toàn Bước "SEED" của các đợt trước.

**Không có bước cấp quyền nào trong đợt này** — endpoint "Tính lại" dùng lại
đúng quyền `night_audit.run` đã cấp sẵn từ trước, không cần chạy tinker nào.

═══════════════════════════════════════════════════════════════
BƯỚC 3 — RESTART SERVICE (nếu môi trường yêu cầu)
═══════════════════════════════════════════════════════════════

Restart theo đúng cách máy chủ này đang chạy (không phải `php artisan
serve`). Nếu dùng queue worker riêng, restart cả worker đó.

═══════════════════════════════════════════════════════════════
BƯỚC 4 — VERIFICATION (xác nhận bằng mắt, không tự động hoá)
═══════════════════════════════════════════════════════════════

- [ ] Mở `/admin/night-audit` — trang tải bình thường, không lỗi.
- [ ] Mở 1 lượt Night Audit **ĐÃ CHẠY TRƯỚC ngày cập nhật này** — thấy nút
      "Tính lại" xuất hiện (đúng như mô tả ở Bối cảnh — hành vi này ĐÚNG,
      không phải lỗi). **KHÔNG bấm nút đó** trên lượt cũ (xem cảnh báo Bối
      cảnh mục 1) — chỉ xác nhận nút có hiện, không thao tác thêm.
- [ ] Nếu đúng nửa đêm 00:00 đã có Night Audit tự động chạy sau khi cập
      nhật (theo lịch có sẵn từ đợt trước) — mở lượt đó, xác nhận thấy
      trạng thái "Đang chờ xác nhận" (chưa bị khóa), có nút "Tính lại".
      **KHÔNG tự bấm để test** trừ khi Product Owner yêu cầu cụ thể — nếu
      muốn test thật, cần một lượt Night Audit KHÔNG PHẢI của khách thật
      (môi trường/booking thử nghiệm), báo tôi trước khi thực hiện.
- [ ] Trên Sơ đồ thao tác, bấm "🖨 In sơ đồ (A4)" → bản xem trước là BẢNG
      khổ **A4 DỌC** (không còn khổ ngang như trước), chia 2 khung có viền
      rõ ràng cạnh nhau, mỗi phòng đúng 3 cột (Phòng / Thông tin / Trạng
      thái), toàn bộ vừa trong **1 trang**.
- [ ] Mở 1 phòng đang có khách trên Sơ đồ thao tác rồi in thử — cột "Thông
      tin" phải hiện đúng 1 trong 5 dòng trạng thái (Đã nhận phòng/Dự kiến
      hôm nay nhận phòng/Phòng lưu/Đã trả phòng/Dự kiến trả phòng) thay vì
      giờ nhận/trả như bản in cũ; nếu phòng có Ghép giường/Giường phụ/yêu
      cầu đặc biệt khác — phải hiện rõ chữ (không phải icon).
- [ ] Console trình duyệt không có lỗi mới sau khi load lại các trang trên.
- [ ] Mở 1 booking test đã có Room Charge từ trước — số tiền không đổi so
      với trước khi cập nhật (đối chiếu qua Đối soát nếu cần) — xác nhận
      migration không ảnh hưởng dữ liệu hiện có.

═══════════════════════════════════════════════════════════════
ROLLBACK (nếu Bước 4 phát hiện lỗi nghiêm trọng)
═══════════════════════════════════════════════════════════════

- **Code:** `git checkout 194b7a4`, `composer install`, `npm run build`
  lại, cache lại.
- **Migration:** `php artisan migrate:rollback --step=1` để lùi đúng 1
  migration này — `down()` khôi phục lại UNIQUE constraint trên
  `posting_key` VÀ xóa 3 cột mới. **Chỉ rollback migration nếu chắc chắn
  chưa có lượt Night Audit MỚI nào chạy dùng cửa sổ 24h này** (nếu đã có,
  rollback migration sẽ xóa mất `night_audit_run_id`/`finalized_at` của các
  dòng đó — dữ liệu tiền vẫn còn nguyên trong `folio_entries`, chỉ mất
  thông tin "dòng nào thuộc lượt nào đang chờ xác nhận"). Nếu không chắc —
  hỏi tôi trước khi rollback migration, rollback code là đủ để tính năng
  biến mất khỏi giao diện trong lúc chờ.
- **Dữ liệu:** nếu có nghi ngờ sai lệch, dùng file backup từ Bước 0.2 —
  không tự ý sửa trực tiếp trên DB production.

Báo cáo lại cho tôi kết quả từng bước, đặc biệt nội dung chính xác nếu Bước
0.3/0.4/Bước 1 (git pull) hoặc Bước 2 (migration) phát hiện bất thường.
```
