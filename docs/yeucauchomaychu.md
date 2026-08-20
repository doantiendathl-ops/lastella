# Hướng dẫn cập nhật pms.lastella.com.vn — đưa lên ngang bằng `phase-3`

**Ngày soạn:** 2026-08-19 (cập nhật lần 10) · **Nhánh nguồn:** `origin/phase-3` @ `12474d2` · **Người soạn:** Claude (máy dev), theo yêu cầu Product Owner sau khi phát hiện `pms.lastella.com.vn` đang chạy code cũ (thiếu menu "Dịch vụ & Yêu cầu", vẫn còn danh sách "Yêu cầu đặc biệt" hardcode trong PHP).

**Cách dùng file này:** dán nguyên văn phần "LỆNH CHO CLAUDE TRÊN MÁY CHỦ" bên dưới cho Claude đang chạy trực tiếp trên máy chủ production. Claude ở máy dev (soạn file này) **không có quyền truy cập trực tiếp vào máy chủ đó** — mọi thao tác thực tế do Claude trên máy chủ tự thực hiện.

---

## Bối cảnh (để Claude trên máy chủ hiểu VÌ SAO, không chỉ làm theo lệnh mù)

`pms.lastella.com.vn` hiện thiếu ~34 commit gần nhất trên `phase-3`, trong đó quan trọng nhất:

- **Unified Services & Requests** (4 commit `ca188f0`…`9e984b6`): thêm danh mục Dịch vụ/Yêu cầu quản lý qua DB (4 bảng mới), thay cho danh sách 24 loại "Yêu cầu đặc biệt" đang hardcode trong `app/Http/Requests/Booking/StoreBookingSpecialRequestRequest.php::ALLOWED_REQUEST_TYPES` (và bản tương ứng ở frontend) — đây chính là lý do màn "Dịch vụ & Yêu cầu" ở menu bị thiếu và các "yêu cầu" hiện tại vẫn nằm cứng trong code.
- **Room Map hợp nhất** (~7 commit): Sơ đồ thao tác/Sơ đồ chọn phòng/Sơ đồ Check phòng/Sơ đồ Kiểm đồ dùng chung 1 kiểu hiển thị, xem lại được phòng đã trả trên Sơ đồ kiểm tra phòng.
- **Excel Color Picker + Unpaid Checkout** (~4 commit).
- **ADMIN sửa giờ nhận/trả phòng + ghi chú nhanh đồng bộ 2 chiều** (1 commit).
- **Định dạng ngày DD-MM-YYYY + tiền có dấu phân cách hàng nghìn** toàn site (1 commit).
- **Phóng to/thu nhỏ trong khung sơ đồ** (nút +/−/100% + chụm 2 ngón tay trên mobile) trên cả 4 sơ đồ phòng (1 commit, `8c83757`) — thuần frontend, không có migration/seeder/quyền mới.
- **Quyền thật cho 6 chức năng trước đây khóa cứng `hasRole('ADMIN')`** (1 commit `fe67b3a`): sửa giờ nhận/trả phòng thực tế, khôi phục booking đã hủy, mở lại hóa đơn đã đóng, xóa thanh toán ở mọi ngày, hủy phí phát sinh ở mọi ngày, sửa booking đã đóng/hủy/không đến — 6 chức năng này giờ dùng quyền Spatie thật (`stay.actual_time.manage`, `booking.restore`, `folio.reopen`, `payment.delete_any_date`, `charge.void_any_date`, `booking.edit_closed`) thay vì kiểm tra vai trò cứng, đồng thời tên hiển thị trên màn Quyền/Vai trò được dịch sang tiếng Việt.
  **⚠️ QUAN TRỌNG — khác với các thay đổi trước:** các quyền này **KHÔNG tự có sẵn** trong DB production cho tới khi cấp thủ công (xem Bước 4). Nếu bỏ qua bước cấp quyền, ngay sau khi pull code, ADMIN trên production sẽ **MẤT khả năng** dùng cả 6 chức năng trên (dù trước đó vẫn dùng bình thường) — vì code không còn kiểm tra `hasRole('ADMIN')` nữa mà kiểm tra `$user->can('<slug>')`, và quyền đó chưa tồn tại. Đây không phải rủi ro dữ liệu, nhưng LÀ một hồi quy chức năng tạm thời nếu làm sai thứ tự — bắt buộc chạy Bước 4 ngay sau Bước 1, không được để cách quãng.
- **Sửa hiển thị icon "Giường phụ" trên Sơ đồ thao tác** (1 commit `69961bb`) — icon này trước đây chỉ đọc cột cũ `room_assignments.extra_bed_quantity` (không có nơi nào trên giao diện ghi vào cột đó), nên khi staff thêm "Giường phụ" qua màn Dịch vụ & Yêu cầu (ghi vào bảng `booking_services`), icon không bao giờ hiện. Giờ icon cộng cả 2 nguồn. Thuần đọc dữ liệu (read-model), không đụng migration/quyền/tính phí Night Audit.
- **Thiết kế lại nội dung ô phòng trên Sơ đồ thao tác** (1 commit `a461ae7`) — số phòng/checkbox/nút chọn cả booking chuyển lên cùng 1 hàng; ngày nhận/trả in đậm rõ hơn, tự thay bằng giờ thực tế + gạch chân khi đã nhận/trả, bấm trực tiếp vào ngày để sửa (thay cho icon bút chì cũ); "Ghép giường"/"Giường phụ x{n}"/"Đã kiểm out" hiện bằng chữ thay vì icon; icon dọn phòng đổi thành chữ "Sạch"/"Bẩn"; bỏ hẳn icon cửa nhận/trả phòng riêng (đã gộp vào cách hiển thị ngày). Thuần frontend (`.vue`), không đụng backend/API/migration/quyền.
- **Zoom 30%, tên khách đậm, hủy dịch vụ kể cả đã hoàn thành, popup "yêu cầu & dịch vụ khác"** (1 commit `739fd70`) — 4 phần: (1) zoom sơ đồ xuống được tới 30% (trước chỉ 50%); (2) tên khách trên ô phòng in đậm hơn; (3) nút "Hủy" trên màn Dịch vụ & Yêu cầu giờ hoạt động cả khi dịch vụ đã ở trạng thái "Đã hoàn thành" (trước đây bị khóa); (4) ô phòng có thêm 1 dòng tóm tắt "yêu cầu & dịch vụ khác" (mọi Dịch vụ & Yêu cầu ngoài Ghép giường/Giường phụ đã hiện riêng) phía trên ô ghi chú, bấm vào mở popup xem danh sách + link sang trang Dịch vụ & Yêu cầu của booking đó. Thuần frontend + 1 thay đổi logic PHP nhỏ (nới điều kiện chuyển trạng thái, không có migration).
- **Ô ghi chú nhanh: chữ đậm + màu đen** (2 commit `d0662a2`, `67391ed`) — nội dung ghi chú, số ký tự N/100, và 2 nút Hủy/Lưu đều in đậm; số ký tự + 2 nút đổi từ xám/xanh mờ sang đen cho dễ đọc trên nền màu của ô phòng (màu đỏ báo lỗi giữ nguyên). Thuần CSS.
- **Chưa kiểm đồ thì không thể trả phòng — khóa thật, không còn chỉ là cảnh báo** (1 commit `6a523a0`) — trước đây hộp thoại "Chưa kiểm đồ" có nút "Vẫn tiếp tục"/"Vẫn trả phòng" mà AI CŨNG bấm được, không cần quyền, không cần lý do — nên thực chất chưa kiểm đồ vẫn trả phòng bình thường. Đã bỏ nút đó ở CẢ 2 nơi có luồng trả phòng (Sơ đồ thao tác và trang chi tiết booking). Giờ chỉ còn 2 cách qua được cảnh báo: kiểm đồ xong, HOẶC bấm "Bỏ qua và tiếp tục/trả phòng" (yêu cầu quyền `checkout_inspection.override` + bắt buộc nhập lý do — được ghi lại đầy đủ ai/khi nào/lý do).
  **⚠️ Ảnh hưởng vận hành:** RECEPTION **không có** quyền `checkout_inspection.override` theo phân quyền mặc định (chỉ ADMIN/MANAGER có) — nghĩa là sau khi cập nhật, lễ tân **sẽ bị chặn hoàn toàn** nếu cố trả phòng khi chưa kiểm đồ, phải tự kiểm đồ trước hoặc nhờ MANAGER/ADMIN. Đây là thay đổi có chủ đích theo yêu cầu Product Owner, không phải lỗi — nhưng cần báo trước cho lễ tân biết để tránh bất ngờ khi thao tác thực tế. Không có migration/quyền mới (đã có sẵn từ trước), thuần đổi hành vi frontend.
- **Kiểm đồ hàng loạt "Không phát sinh"** (1 commit `6e7d676`) — chọn nhiều phòng trên Sơ đồ thao tác, bấm 1 nút "Không phát sinh (N)" là ghi "Xác nhận không phát sinh" cho tất cả, không cần mở từng phiếu kiểm đồ. Dùng lại đúng API/nghiệp vụ kiểm đồ đơn lẻ có sẵn — thuần frontend, không route/quyền/migration mới.
- **Sửa lỗi JS chặn hoàn toàn Nhận phòng/Trả phòng trên Sơ đồ thao tác** (2 commit `2209748`, `12474d2`) — bấm "Trả phòng"/"Nhận phòng" không phản ứng gì, lỗi Console `ReferenceError: can is not defined`. Nguyên nhân: 3 chỗ trong `Index.vue` (từ commit `8ec0340`, tính năng ADMIN sửa giờ nhận/trả phòng) gọi biến `can` thiếu tiền tố `props.` — throw lỗi ngay khi bấm nút, không có thông báo gì cho người dùng biết. Đã kiểm tra toàn bộ các file liên quan khác, không còn lỗi tương tự ở đâu khác. **Đây là lỗi nghiêm trọng — chặn hẳn chức năng nhận/trả phòng trên Sơ đồ thao tác** kể từ khi tính năng ADMIN sửa giờ ra đời; bắt buộc phải có trong đợt deploy này. Thuần frontend, không migration/quyền.
- 1 commit hạ tầng (`bootstrap/app.php` trust Cloudflare Tunnel proxy — **có thể máy chủ đã tự vá tay phần này rồi, kiểm tra kỹ để tránh conflict khi pull**).

**4 migration mới** (additive — tạo bảng mới, KHÔNG đụng bảng cũ): `service_categories`, `services`, `service_prices`, `booking_services`. Commit `fe67b3a` (quyền mới), `69961bb` (sửa icon giường phụ), `a461ae7` (thiết kế lại ô phòng), `739fd70` (zoom/tên đậm/hủy dịch vụ/popup) và `d0662a2`/`67391ed` (đậm/đen ô ghi chú) **không có migration nào** — `fe67b3a` chỉ thêm dòng dữ liệu vào bảng `permissions`/`role_has_permissions` có sẵn của Spatie (làm bằng tinker ở Bước 4); các commit còn lại chỉ đổi cách đọc/hiển thị/logic-chuyển-trạng-thái trên dữ liệu hiện có, không ghi schema mới.

**Không có migration nào XÓA/SỬA cột hoặc bảng cũ** trong toàn bộ khoảng này — rủi ro dữ liệu ở mức thấp, nhưng vẫn backup đầy đủ theo đúng quy trình bên dưới trước khi làm bất cứ gì.

---

## LỆNH CHO CLAUDE TRÊN MÁY CHỦ (dán nguyên văn)

```
Cập nhật Lastella PMS (pms.lastella.com.vn) lên ngang bằng origin/phase-3
(commit mới nhất hiện tại: 12474d2 — "fix(room-operations): ReferenceError
'can is not defined' blocked check-in/out"). Đây là một đợt cập nhật LỚN
(~34 commit), làm tuần tự từng bước, DỪNG LẠI hỏi tôi ngay khi có bất kỳ
điều gì bất thường — không tự suy đoán hoặc tự sửa nếu không chắc chắn.

═══════════════════════════════════════════════════════════════
BƯỚC 0 — PREFLIGHT (bắt buộc, không bỏ qua bước nào)
═══════════════════════════════════════════════════════════════

1. Backup database đầy đủ (mysqldump toàn bộ DB production) VÀ backup thư
   mục storage/ (ảnh, file upload) TRƯỚC khi đổi bất cứ gì. Ghi rõ đường dẫn
   file backup ra để tôi biết vị trí nếu cần rollback dữ liệu.

2. Ghi lại commit đang chạy hiện tại: `git log -1 --format="%H %s"`. Đây là
   điểm rollback code.

3. Chạy `git status`. Nếu có bất kỳ thay đổi cục bộ nào chưa commit trên máy
   chủ (đặc biệt kiểm tra `bootstrap/app.php` — nhiều khả năng đã bị sửa tay
   để trust Cloudflare Tunnel proxy, vì bản trên git mới thêm phần này ở
   commit sau) — DỪNG LẠI, liệt kê chính xác các file bị thay đổi và nội
   dung khác biệt, báo tôi biết TRƯỚC khi làm gì tiếp theo. Tuyệt đối không
   tự ý `git checkout .` hay `git stash` xoá mất phần vá tay đó nếu chưa xác
   nhận rõ nó đã được đưa vào commit mới hay chưa.

4. Chạy `git fetch origin` rồi `git log HEAD..origin/phase-3 --oneline` để
   xem trước toàn bộ danh sách commit sắp kéo về — dán ra cho tôi xem.

5. Chạy `php artisan migrate:status` — ghi lại kết quả TRƯỚC khi cập nhật
   code, để đối chiếu sau này.

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

Nếu `git pull` báo conflict do thay đổi cục bộ ở Bước 0.3 — DỪNG LẠI, báo
tôi, không tự ý chọn "ours"/"theirs" hay force-merge.

═══════════════════════════════════════════════════════════════
BƯỚC 2 — MIGRATION (4 bảng mới, additive, không đụng bảng cũ)
═══════════════════════════════════════════════════════════════

1. Chạy lại `php artisan migrate:status`, xác nhận đúng 4 migration mới xuất
   hiện, đúng tên: create_service_categories_table,
   create_services_table, create_service_prices_table,
   create_booking_services_table. Nếu thấy migration NÀO KHÁC lạ ngoài 4
   cái này — DỪNG LẠI, báo tôi trước khi chạy migrate (đợt chuẩn bị tài
   liệu này chỉ soát 4 migration này; nếu có thêm migration lạ nghĩa là
   phân tích ban đầu chưa đủ, cần xem lại).
2. `php artisan migrate` (không dùng `--force` để bỏ qua xác nhận trừ khi
   Laravel yêu cầu bắt buộc trên production — nếu nó yêu cầu, đó là hành vi
   chuẩn của Laravel khi APP_ENV=production, cứ xác nhận chạy tiếp).

═══════════════════════════════════════════════════════════════
BƯỚC 3 — SEED DANH MỤC DỊCH VỤ & YÊU CẦU (chỉ 2 seeder, tuyệt đối không
chạy full db:seed)
═══════════════════════════════════════════════════════════════

⚠️ CẢNH BÁO QUAN TRỌNG: KHÔNG BAO GIỜ chạy `php artisan db:seed` (không có
--class) trên production. DatabaseSeeder gọi CẢ `AdminUserSeeder`, seeder
này dùng `User::updateOrCreate(['email' => 'admin@lastella.local'], ['password'
=> Hash::make('password'), ...])` — nếu chạy full seed, mật khẩu tài khoản
đó (nếu tồn tại trên production) sẽ bị RESET VỀ "password" — một lỗ hổng bảo
mật nghiêm trọng. CHỈ chạy đúng 2 lệnh named-seeder dưới đây, không hơn:

php artisan db:seed --class=UnifiedServiceSeeder
php artisan db:seed --class=UnifiedRequestCatalogSeeder

Cả 2 đều idempotent (dùng firstOrCreate, an toàn khi chạy lại nhiều lần).
UnifiedServiceSeeder tạo "Giường phụ" (150.000đ/đêm) và "Ăn sáng"
(120.000đ/đêm) — giá lấy đúng bằng giá thật đang áp dụng trên production
(carry-forward, không phải số bịa). UnifiedRequestCatalogSeeder tạo 24 loại
"Yêu cầu đặc biệt" (miễn phí) — đúng danh sách đang hardcode hiện tại,
chuyển vào DB để admin tự quản lý được từ nay.

═══════════════════════════════════════════════════════════════
BƯỚC 4 — CẤP QUYỀN MỚI (an toàn, KHÔNG dùng syncPermissions)
═══════════════════════════════════════════════════════════════

--- 4a. services.manage (không bắt buộc ngay, nhưng cần để menu "Dịch vụ &
Yêu cầu" hiện ra) ---

Menu "Dịch vụ & Yêu cầu" chỉ hiện với quyền `services.manage`. Quyền này có
thể CHƯA tồn tại trong DB production. TUYỆT ĐỐI KHÔNG chạy lại
`php artisan db:seed --class=RolePermissionSeeder` để "tiện thể cập nhật
quyền" — seeder đó dùng `Role::syncPermissions()` (THAY THẾ TOÀN BỘ danh
sách quyền của từng role bằng đúng những gì có trong code), sẽ XÓA MẤT bất
kỳ tinh chỉnh quyền nào Product Owner đã tự làm qua màn Vai trò/Quyền trên
production từ trước tới nay, kể cả những quyền không liên quan gì đến đợt
cập nhật này.

Thay vào đó, cấp quyền theo cách CHỈ THÊM (additive), không đụng gì khác:

php artisan tinker --execute="
\$p = Spatie\Permission\Models\Permission::findOrCreate('services.manage', 'web');
Spatie\Permission\Models\Role::findByName('ADMIN')->givePermissionTo(\$p);
Spatie\Permission\Models\Role::findByName('MANAGER')->givePermissionTo(\$p);
echo 'Đã cấp quyền services.manage cho ADMIN + MANAGER.';
"

Nếu Product Owner muốn quyền này cho role khác nữa (SALES/RECEPTION/...) —
hỏi tôi trước, đừng tự quyết định cấp thêm.

--- 4b. 6 quyền mới của commit fe67b3a — BẮT BUỘC, làm ngay sau Bước 1, chờ
xong Bước 4a nếu chạy chung, đừng bỏ qua ---

Khác với 4a (services.manage — chỉ ảnh hưởng 1 menu mới toanh chưa ai dùng),
6 quyền dưới đây thay thế các kiểm tra `hasRole('ADMIN')` mà production
ĐANG DÙNG cho các thao tác ADMIN thực hiện hằng ngày (sửa giờ nhận/trả phòng
thực tế, khôi phục booking đã hủy, mở lại hóa đơn, xóa thanh toán/hủy phí cũ
ngày, sửa booking đã đóng). Nếu không cấp quyền này ngay, ADMIN sẽ bị chặn
(403) khi dùng các chức năng đó — dù trước khi cập nhật vẫn dùng bình
thường. Cấp cho đúng ADMIN — giữ nguyên hành vi hiện tại, không mở rộng cho
role nào khác trừ khi Product Owner yêu cầu:

php artisan tinker --execute="
\$slugs = [
    'stay.actual_time.manage',
    'booking.restore',
    'folio.reopen',
    'payment.delete_any_date',
    'charge.void_any_date',
    'booking.edit_closed',
];
\$admin = Spatie\Permission\Models\Role::findByName('ADMIN');
foreach (\$slugs as \$slug) {
    \$p = Spatie\Permission\Models\Permission::findOrCreate(\$slug, 'web');
    \$admin->givePermissionTo(\$p);
}
echo 'Đã cấp '.count(\$slugs).' quyền mới cho ADMIN.';
"

Sau khi chạy xong, xác nhận lại bằng:

php artisan tinker --execute="
echo Spatie\Permission\Models\Role::findByName('ADMIN')
    ->permissions->pluck('name')->intersect([
        'stay.actual_time.manage','booking.restore','folio.reopen',
        'payment.delete_any_date','charge.void_any_date','booking.edit_closed',
    ])->count();
"

Kết quả phải in ra đúng số 6. Nếu khác 6 — DỪNG LẠI, báo tôi, đừng tự chạy
lại nhiều lần hay đoán nguyên nhân.

═══════════════════════════════════════════════════════════════
BƯỚC 5 — RESTART SERVICE (nếu môi trường yêu cầu)
═══════════════════════════════════════════════════════════════

Restart theo đúng cách máy chủ này đang chạy (Apache/service riêng — không
phải `php artisan serve`, theo đúng khuyến nghị runbook ban đầu). Nếu dùng
queue worker riêng cho Night Audit/posting job, restart cả worker đó.

═══════════════════════════════════════════════════════════════
BƯỚC 6 — VERIFICATION (xác nhận bằng mắt, không tự động hoá, không thao
tác lên dữ liệu thật ngoài những gì liệt kê)
═══════════════════════════════════════════════════════════════

- [ ] Menu bên trái giờ có "Dịch vụ & Yêu cầu" (dưới "Sơ đồ thao tác").
- [ ] Mở `/admin/services` — thấy danh mục: Giường phụ (150.000đ), Ăn sáng
      (120.000đ), Người thêm (chưa có giá — đúng thiết kế, admin phải tự
      đặt giá trước khi dùng được), và 24 loại yêu cầu miễn phí (Ghép
      giường, Nôi em bé, Xe lăn, v.v.) — đều sửa/thêm/tắt được qua giao
      diện, không cần sửa code nữa.
- [ ] Mở 1 booking bất kỳ → nút "Dịch vụ & Yêu cầu" → danh sách hiện đúng
      như trên, chọn thử thêm 1 dịch vụ miễn phí vào booking test (không
      phải booking thật của khách) — xác nhận hoạt động.
- [ ] Mở 4 sơ đồ phòng (Sơ đồ thao tác, Đặt phòng → Sơ đồ phòng, Kiểm tra
      phòng, Kiểm đồ trả phòng) — hiển thị cùng kiểu (màu nền theo booking),
      không vỡ layout.
- [ ] Trên Sơ đồ thao tác, ADMIN bấm "Nhận phòng"/"Trả phòng" — hiện hộp
      thoại nhập giờ thực tế (trước đây không có).
- [ ] Toàn bộ ngày tháng trên các trang hiển thị dạng DD-MM-YYYY (không còn
      YYYY-MM-DD); các ô nhập số tiền hiện dấu chấm phân cách hàng nghìn khi
      gõ.
- [ ] Mở 1 booking đã có Room Charge từ trước khi cập nhật — số tiền không
      đổi so với trước (đối chiếu qua Đối soát nếu cần).
- [ ] Trên 1 trong 4 sơ đồ phòng, thấy nút −/100%/+ ở góc trên bên trái
      khung sơ đồ; bấm + / − đổi cỡ ô phòng đúng, kéo cuộn vẫn xem được hết
      phần đã phóng to. Trên điện thoại thật, chụm 2 ngón tay trong khung sơ
      đồ phóng to/thu nhỏ được (không phải zoom cả trang).
- [ ] (Bắt buộc kiểm tra sau Bước 4b) ADMIN sửa giờ nhận/trả phòng thực tế
      trên Sơ đồ thao tác VÀ trên trang chi tiết booking — cả hai chỗ đều
      lưu được, không bị 403.
- [ ] (Bắt buộc) ADMIN mở 1 booking đã hủy → nút "Khôi phục" hoạt động bình
      thường; ADMIN mở 1 hóa đơn đã đóng → "Mở lại hóa đơn" hoạt động bình
      thường; ADMIN xóa được 1 khoản thanh toán/hủy được 1 phí phát sinh của
      ngày TRƯỚC hôm nay; ADMIN sửa được 1 booking đã CheckedOut/Cancelled.
      Nếu bất kỳ thao tác nào trong nhóm này báo lỗi 403 — quay lại Bước 4b,
      kiểm tra lại đã cấp đủ 6 quyền cho ADMIN chưa.
- [ ] Mở màn "Quyền" (`/permissions`) — thấy cột "Chức năng" hiện tên tiếng
      Việt (không còn slug tiếng Anh làm tên chính); mở màn "Vai trò" → sửa
      1 vai trò bất kỳ — danh sách checkbox quyền cũng hiện tên tiếng Việt.
- [ ] Mở 1 booking test → Dịch vụ & Yêu cầu → thêm "Giường phụ" cho 1 phòng
      cụ thể → quay lại Sơ đồ thao tác, ô phòng đó phải hiện chữ "Giường
      phụ x{số lượng}" (không còn icon). Trước bản cập nhật này, không hiện
      gì dù đã thêm dịch vụ — đây là hành vi ĐÚNG mới.
- [ ] Trên Sơ đồ thao tác, ô phòng: số phòng → checkbox chọn phòng → nút
      chọn cả booking nằm cùng 1 hàng trên cùng (không còn tách rời như
      trước); ngày nhận/trả in đậm, rõ ràng; sau khi Nhận phòng, phần "ngày
      in" tự đổi sang giờ thực tế có gạch chân đậm, bấm trực tiếp vào đó mở
      được hộp thoại sửa giờ (ADMIN); ô phòng không còn icon cửa/icon dọn
      phòng riêng — thay bằng chữ "Sạch"/"Bẩn".
- [ ] Trên 1 sơ đồ phòng, bấm nút − liên tục — zoom được xuống tới 30%
      (trước đây chỉ tới 50%). Tên khách trên ô phòng in đậm rõ hơn trước.
- [ ] Vào 1 booking test → Dịch vụ & Yêu cầu → thêm 1 dịch vụ bất kỳ (không
      phải Giường phụ/Ghép giường) → Xác nhận → Hoàn thành → nút "Hủy" vẫn
      bấm được và hủy thành công (trước đây bị ẩn/khóa sau khi Hoàn thành).
- [ ] Trên Sơ đồ thao tác, ô phòng của booking vừa thêm dịch vụ ở trên phải
      hiện dòng "1 yêu cầu & dịch vụ khác: ..." phía trên ô ghi chú — bấm
      vào mở popup liệt kê đúng dịch vụ đó, có nút "Xem" (mở đúng trang
      Dịch vụ & Yêu cầu của booking) và nút "Thoát" (đóng popup).
- [ ] Trên Sơ đồ thao tác, bấm vào ô ghi chú để sửa — nội dung, số ký tự
      N/100, và 2 nút Hủy/Lưu đều in đậm, chữ màu đen rõ ràng (không còn
      xám/xanh mờ khó đọc trên nền màu của ô phòng).
- [ ] Đăng nhập bằng tài khoản RECEPTION, thử trả phòng 1 stay CHƯA kiểm đồ
      (cả trên Sơ đồ thao tác lẫn trang chi tiết booking) — hộp thoại "Chưa
      kiểm đồ" chỉ còn nút "Đóng" và "Kiểm đồ ngay" (KHÔNG còn nút "Vẫn tiếp
      tục"/"Vẫn trả phòng") — xác nhận KHÔNG trả phòng được. Đăng nhập lại
      bằng ADMIN/MANAGER, thử tương tự — thấy thêm ô nhập lý do + nút "Bỏ
      qua và tiếp tục/trả phòng", nhập lý do rồi trả phòng thành công được.
- [ ] Trên Sơ đồ thao tác, chọn 2-3 phòng đang có khách (chưa kiểm đồ) →
      thấy nút "Không phát sinh (N)" trên thanh công cụ dưới → bấm → cả N
      phòng chuyển sang trạng thái "Đã kiểm out" (đã kiểm đồ, không phát
      sinh phí) mà không cần mở từng phiếu.
- [ ] **Quan trọng nhất đợt này:** mở Console (F12), trên Sơ đồ thao tác
      chọn 1 phòng đang có khách đã kiểm đồ xong → bấm "Trả phòng" → PHẢI
      hiện hộp thoại xác nhận (không được im lặng không phản ứng); Console
      KHÔNG được có dòng `ReferenceError: can is not defined`. Thử tương tự
      với "Nhận phòng" cho 1 phòng đang chờ nhận.
- [ ] Console trình duyệt không có lỗi mới sau khi load lại các trang trên.
- [ ] KHÔNG tự chạy Night Audit để test — nếu cần xác nhận Night Audit vẫn
      hoạt động, chỉ mở trang xem trạng thái, không tự bấm chạy.

═══════════════════════════════════════════════════════════════
ROLLBACK (nếu Bước 6 phát hiện lỗi nghiêm trọng)
═══════════════════════════════════════════════════════════════

- **Code:** `git checkout <commit đã ghi ở Bước 0.2>`, `composer install`,
  `npm run build` lại, cache lại.
- **Migration:** 4 bảng mới hoàn toàn ĐỘC LẬP (không có cột nào thêm vào
  bảng cũ) — an toàn nhất là ĐỂ NGUYÊN 4 bảng đó (rỗng dữ liệu nếu cần lùi),
  không cần `migrate:rollback` trừ khi thực sự muốn dọn sạch — rollback code
  là đủ để tính năng biến mất khỏi giao diện.
- **Quyền vừa cấp ở Bước 4a/4b:** an toàn, chỉ là THÊM quyền — không cần gỡ
  lại trừ khi có lý do cụ thể. Nếu rollback code về trước `fe67b3a`, 6 quyền
  ở Bước 4b trở thành thừa (code cũ không đọc tới) nhưng vô hại nếu để
  nguyên trong DB.
- **Dữ liệu:** nếu có nghi ngờ sai lệch dữ liệu, dùng file backup từ Bước
  0.1 — không tự ý sửa trực tiếp trên DB production.

Báo cáo lại cho tôi kết quả từng bước, đặc biệt nội dung chính xác nếu Bước
0.3/0.4/Bước 1 (git pull) phát hiện bất thường.
```
