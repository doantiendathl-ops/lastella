# Booking Service Rates Array Shape Hotfix Report

**ChatGPT review: APPROVED FOR COMMIT.**
**Readiness: READY FOR COMMIT.**
**Trạng thái code: hoàn thành, chưa deploy production.** Đang thực hiện commit + push từ development — chưa commit tại thời điểm viết các mục điều tra/fix, không ghi commit SHA cố định trong tài liệu này (SHA thực tế xác nhận riêng ngoài tài liệu sau khi commit).
**Ngày:** 2026-08-01
**Không sửa database thật, không chạy seeder, không đổi `.env`.**

---

## 1. Production Incident

`GET /admin/bookings/8` trả HTTP 500 sau khi có ít nhất một biểu giá active trong `service_rates`:

```
ErrorException: Attempt to read property "id" on array
File: app/Http/Controllers/Admin/Booking/BookingController.php (~dòng 159-165)
```

Trước khi có dữ liệu trong `service_rates`, trang mở bình thường — đây là latent bug bị che giấu khi bảng rỗng, không phải do commit `6d69409` (commit đó chỉ sửa Vue navigation/label, không đụng backend).

## 2. Error and Reproduction

Đoạn code gốc gây lỗi:

```php
'serviceRates' => array_values(array_map(fn ($rate): array => [
    'id'          => $rate->id,
    'name'        => $rate->name,
    'charge_type' => $rate->charge_type,
    'unit_price'  => (float) $rate->unit_price,
    'unit_label'  => $rate->unit_label,
], $activeRates)),
```

Đã tái hiện lại bằng test tích hợp (không phải suy đoán): tạo 1 `ServiceRate` active, gọi `GET /admin/bookings/{id}`, response 500 với đúng thông báo `Attempt to read property "id" on array`. Khi không có `ServiceRate` nào active, `$activeRates` là `[]`, `array_map` không gọi callback nên không lỗi — khớp đúng với quan sát production.

## 3. Root Cause

`$activeRates = $serviceRates->activeRatesGrouped($currentBusinessDate);` (`BookingController.php:142`), trong đó `$serviceRates` là `App\Services\ServiceRateService`.

Signature thực tế: `show(Request $request, Booking $booking, RoomAssignmentService $assignments, ServiceRateService $serviceRates, BusinessDateService $businessDate): Response`. `$serviceRates` được Laravel service container cung cấp qua **method dependency injection** (route `bookings/{booking}` là `Route::resource('bookings', BookingController::class)` — không có route param nào tên `serviceRates` nên container tự resolve tham số này). `Booking $booking` mới là tham số được **route-model-bound** (implicit model binding từ segment `{booking}` trong route resource).

`ServiceRateService::activeRatesGrouped(Carbon $businessDate): array` (`app/Services/ServiceRateService.php:29-55`):

```php
public function activeRatesGrouped(Carbon $businessDate): array
{
    $rates = ServiceRate::where('is_active', true)
        ->whereDate('effective_from', '<=', $businessDate->toDateString())
        ->orderBy('display_order')
        ->orderByDesc('effective_from')
        ->orderByDesc('id')
        ->get();

    $seen   = [];
    $result = [];

    foreach ($rates as $rate) {
        if (! in_array($rate->charge_type, $seen, true)) {
            $seen[]                       = $rate->charge_type;
            $result[$rate->charge_type][] = [
                'id'         => $rate->id,
                'name'       => $rate->name,
                'unit_price' => (float) $rate->unit_price,
                'unit_label' => $rate->unit_label,
            ];
        }
    }

    return $result;
}
```

Trả về thực tế: `array<string $chargeType, list<array{id:int, name:string, unit_price:float, unit_label:string}>>` — một **mảng gom nhóm theo `charge_type`** (charge_type là **key ngoài**, không phải field bên trong mỗi phần tử), mỗi giá trị là **1 danh sách các mảng con** (không phải 1 rate đơn lẻ, không phải model).

**Trả lời 5 câu hỏi bắt buộc:**

1. **Vì sao `$activeRates` là array:** Vì `activeRatesGrouped()` khai báo `: array` và trả về cấu trúc PHP array gom nhóm thuần (không phải Eloquent Collection, không phải model) — đây là kết quả trực tiếp, không phải do controller tự ép kiểu.
2. **Đây có phải contract có chủ đích không:** Có — bản thân method này có chủ đích trả về **dạng gom nhóm** (dedup theo `charge_type`, comment gốc ghi "Used by the AddChargeForm picker"). Nhưng đây là **sai chủ đích cho consumer thực tế**: `BookingController::show()` (và qua đó `AddChargeForm.vue`/`FolioPanel.vue`) cần một **danh sách phẳng** `{id, name, charge_type, unit_price, unit_label}[]` — khớp đúng type đã khai báo ở frontend (`FolioPanel.vue:88`, `AddChargeForm.vue` interface `ServiceRate`). Method gom nhóm và danh sách phẳng là hai shape khác nhau; controller đã dùng sai giả định (tưởng là danh sách phẳng các model).
3. **Controller sai hay service sai:** **Controller sai** khi dùng object syntax (`$rate->id`) trên dữ liệu mà service đã trả về dưới dạng mảng gom nhóm — không phải service sai khi "chuyển model thành array" (việc chuyển thành array trong `activeRatesGrouped()` là chủ đích, chỉ là *cấu trúc* gom nhóm không khớp với cách controller tiêu thụ).
4. **Call site nào phụ thuộc kiểu dữ liệu hiện tại:** Đã `rg -n "activeRatesGrouped"` toàn repo (`app`, `tests`, `resources`) — **chỉ có duy nhất 1 call site**: `BookingController.php:142` (chính là nơi đang lỗi). Không có test nào gọi `activeRatesGrouped()` trực tiếp, không có nơi nào khác tiêu thụ shape gom nhóm này. Do đó sửa tại đây **không có rủi ro phá vỡ consumer khác**.
5. **Giải pháp ít regression nhất:** Sửa đúng tại điểm tiêu thụ (controller) để flatten cấu trúc gom nhóm và gắn lại `charge_type` từ key ngoài — **không đổi `ServiceRateService::activeRatesGrouped()`**, không đổi logic chọn biểu giá (`where is_active`, `whereDate('effective_from', ...)`, dedup theo `charge_type`) vẫn giữ nguyên 100%.

## 4. Actual Data Contract

| | Trước khi sửa (giả định sai trong code) | Thực tế (`activeRatesGrouped()`) | Frontend cần (`FolioPanel.vue`/`AddChargeForm.vue`) |
|---|---|---|---|
| Kiểu | `ServiceRate[]` model hoặc mảng phẳng | `array<charge_type, list<array>>` gom nhóm | `{id, name, charge_type, unit_price, unit_label}[]` phẳng |
| `charge_type` | field trên mỗi phần tử | **key ngoài**, không có trong phần tử con | field trên mỗi phần tử |
| Truy cập | `$rate->id` (object) | `$rate['id']` (array), nhưng `$rate` ở tầng ngoài là 1 **list**, không phải 1 rate | — |

Không có mismatch model-vs-array đơn thuần — đây là mismatch **cấu trúc lồng (grouped vs flat)**, nên chỉ đổi `->` thành `['...']` (không xử lý tầng lồng) vẫn sẽ sai theo cách khác (đọc nhầm cả nhóm làm 1 rate, thiếu `charge_type`).

## 5. Chosen Fix

**Phương án đã chọn: tương đương Phương án A (service giữ nguyên chủ đích trả array gom nhóm), sửa hoàn toàn ở tầng controller — không đổi return type/service.**

Lý do không chọn Phương án B (đổi service trả `ServiceRate` models): frontend/contract thực tế cần **plain array**, không cần model; đổi service sang trả Collection<ServiceRate> sẽ buộc phải map lại ở controller y hệt, không giảm thay đổi, lại phải tự cài dedup-theo-charge-type logic ở nơi khác nếu muốn giữ tên method — không giảm rủi ro, chỉ tăng phạm vi sửa. Vì chỉ có 1 call site và nó đang lỗi, sửa tại chỗ tiêu thụ là minimal nhất.

```php
// activeRatesGrouped() groups rates by charge_type (charge_type is the array key,
// not a field on each entry), so flatten per group and reattach charge_type.
'serviceRates'        => collect($activeRates)
    ->flatMap(fn (array $rates, string $chargeType): array => array_map(
        fn (array $rate): array => [
            'id'          => $rate['id'],
            'name'        => $rate['name'],
            'charge_type' => $chargeType,
            'unit_price'  => (float) $rate['unit_price'],
            'unit_label'  => $rate['unit_label'],
        ],
        $rates
    ))
    ->values()
    ->all(),
```

- Dùng `array` type-hint rõ ràng cho closure (`fn (array $rates, string $chargeType)` / `fn (array $rate)`), không dùng `mixed`/không khai báo kiểu.
- `charge_type` trong `ServiceRate` model **không cast thành enum** (xác nhận qua `app/Models/ServiceRate.php` — chỉ có cast cho `unit_price`, `effective_from`, `tax_rate`, `is_active`), luôn là plain string — không cần xử lý `->value` của enum.
- Không đổi `ServiceRateService::activeRatesGrouped()`, không đổi `ServiceRateService::resolveFor()`, không đổi bất kỳ Posting Job, không đổi `ProductService`.
- Inertia prop `serviceRates` giữ nguyên đúng shape cũ mà frontend đã khai báo (`{id, name, charge_type, unit_price, unit_label}[]`) — không đổi contract ngoài ý muốn.

## 6. Files Changed

| File | Loại | Nội dung |
|---|---|---|
| `app/Http/Controllers/Admin/Booking/BookingController.php` | Sửa | Thay `array_map` object-syntax bằng `collect(...)->flatMap(...)` flatten đúng cấu trúc gom nhóm, giữ nguyên shape output |
| `tests/Feature/BookingServiceRatesPropTest.php` | Mới | 4 test quy định ở Bước 5 |

Không sửa: `ServiceRateService.php`, `ServiceRateController.php`, `ServiceRatePolicy.php`, model `ServiceRate`/`ProductService`/`BookingPackageFlag`, migration, seeder, route.

## 7. Regression Test

**Đã xác minh tên file thật trên đĩa** (`dir tests/Feature/BookingServiceRates*Test.php` + `rg -n "class BookingServiceRates" tests/Feature`): file thực tế là `tests/Feature/BookingServiceRatesPropTest.php`, class `BookingServiceRatesPropTest` (dạng "Prop", không phải "Props"). Tên này đã được dùng thống nhất trong toàn bộ tài liệu này — không phát hiện sai khác giữa tên file trên đĩa và tên nêu trong báo cáo.

File: `tests/Feature/BookingServiceRatesPropTest.php` (mới, 4 test):

1. `test_booking_detail_returns_empty_service_rates_when_none_active` — không có `ServiceRate` nào → 200, `serviceRates === []`.
2. `test_booking_detail_exposes_active_service_rate_fields` — 2 rate active (`EXTRA_BED`, `EXTRA_PERSON`) → 200, prop `serviceRates` chứa đúng `id/name/charge_type/unit_price/unit_label` cho từng charge_type (xác nhận `charge_type` được gắn đúng, không lẫn giữa 2 nhóm).
3. `test_booking_detail_excludes_inactive_service_rate` — rate `is_active=false` → không xuất hiện trong `serviceRates`.
4. `test_booking_detail_excludes_service_rate_not_yet_effective` — rate `effective_from` +5 ngày → không xuất hiện trong `serviceRates`.

**Xác nhận test tái hiện lỗi cũ trước khi sửa:** đã `git stash push -- app/Http/Controllers/Admin/Booking/BookingController.php` để đưa controller về đúng bản gốc (có bug), chạy `php artisan test tests/Feature/BookingServiceRatesPropTest.php`:

```
✓ booking detail returns empty service rates when none active
✗ booking detail exposes active service rate fields
    Attempt to read property "id" on array
    at tests/Feature/BookingServiceRatesPropTest.php:79
✓ booking detail excludes inactive service rate
✓ booking detail excludes service rate not yet effective

Tests: 1 failed, 3 passed (16 assertions)
```

Đúng khớp lỗi production, đúng khớp việc 3 kịch bản "không có rate active" không lỗi. Sau đó `git stash pop` khôi phục fix, chạy lại:

```
✓ booking detail returns empty service rates when none active
✓ booking detail exposes active service rate fields
✓ booking detail excludes inactive service rate
✓ booking detail excludes service rate not yet effective

Tests: 4 passed (27 assertions)
```

## 8. Targeted Test Results

```
php artisan test --filter="BookingManagementUiTest|BookingController|ServiceRate|PackageEnrollment|BookingProductServiceChargeTest|BookingServiceRatesPropTest"
```

Log đầy đủ: `storage/logs/targeted_test_run.txt` (không commit — thuộc `storage/`).

**Kết quả: 198 passed, 11 failed (1441 assertions).**

Tất cả 7 test class liên quan trực tiếp tới task đều **PASS toàn bộ**:

| Test class | Kết quả |
|---|---|
| `BookingServiceRatesPropTest` (mới) | PASS (4/4) |
| `BookingProductServiceChargeTest` | PASS |
| `BookingPackageEnrollmentTest` | PASS |
| `PackageEnrollmentControllerTest` | PASS |
| `PackageEnrollmentServiceTest` | PASS |
| `ServiceRateCrudTest` | PASS |
| `ServiceRateVersioningTest` | PASS |

11 test fail đều thuộc **`BookingManagementUiTest`** (nhóm `roomBoard.floors`/`roomBoard.all_room_type_summary`), **cùng tên chính xác** với 11 test đã ghi nhận là pre-existing/không liên quan trong `docs/reports/booking-package-pricing-navigation-fix-report.md` (mục 10) từ lần hotfix menu trước — không có test fail mới nào phát sinh do thay đổi lần này:

```
conflicted room is disabled on room board
same room after previous checkout is available on room board
booking detail includes payment summary and refund subtracts from paid total
current booking room payload includes current assignment data
all room type summary current booking count
room type summary counts current booking room
boundary touching assignment does not conflict on room board
released current booking assignment not in active board
non overlapping checked in room is available on room board
overlapping checked in room is conflict on room board
non overlapping checked in room not counted as occupied in summary
```

Đây là bằng chứng đủ để phân biệt lỗi cũ và lỗi mới: danh sách 11 tên test fail **giống nguyên văn** giữa lần chạy trước (trước hotfix này) và lần chạy này (sau hotfix này) — hotfix không thêm, không xóa, không đổi kết quả của bất kỳ test nào trong nhóm đó.

## 9. Build Result

```
npm run build
```

**PASS** — build thành công trong 15.42s, output `app-C7fPOWgo.css` / `app-BHcYkM-M.js` không đổi so với lần build trước (task này không sửa file Vue/JS nào). Cảnh báo chunk-size >500kB là cảnh báo có sẵn, không phát sinh mới.

## 10. Regression Risks

- **Rủi ro thấp**: chỉ 1 method trong 1 controller bị sửa, chỉ 1 call site tồn tại cho `activeRatesGrouped()`, đã xác nhận bằng `rg` toàn repo.
- Không N+1: `activeRatesGrouped()` chỉ chạy 1 query (`ServiceRate::where(...)->get()`); việc flatten trong controller là xử lý PHP thuần trên kết quả đã load, không truy vấn thêm.
- `charge_type` trả về string (không phải enum) — khớp với type khai báo ở frontend (`string`).
- `unit_price` vẫn ép `(float)` đúng như code gốc.
- Không đổi cấu trúc Inertia prop `serviceRates` mà frontend đang dùng (`FolioPanel.vue`, `AddChargeForm.vue`) — vẫn là mảng phẳng cùng 5 field, cùng thứ tự nghĩa.
- Không ảnh hưởng `ProductService`/`productServices` prop (không đụng đoạn code đó).
- Không ảnh hưởng trang `/admin/service-rates` (`ServiceRateController` không đổi).
- Không ảnh hưởng package enrollment (`PackageEnrollmentController`/`PackageEnrollmentService` không đổi, test liên quan PASS).

## 11. Deployment Plan

1. **ChatGPT đã phê duyệt: APPROVED FOR COMMIT.** Stage đúng 3 file thuộc hotfix (`BookingController.php`, `tests/Feature/BookingServiceRatesPropTest.php`, báo cáo này), commit riêng — không gộp các thay đổi có sẵn ngoài phạm vi khác trong working tree.
2. Commit và **push từ development** lên `origin/phase-3`.
3. Trên production: `git pull --ff-only origin phase-3` để lấy đúng commit hotfix — **không merge, không rebase**.
4. **Không cần `npm run build`** trên production nếu commit hotfix thực tế không đổi bất kỳ file `package.json`, `package-lock.json`, `.vue`, hay `.js` nào (commit này chỉ gồm 1 file PHP controller + 1 file test PHP + 1 file markdown — xác nhận bằng `git diff --name-status HEAD..origin/phase-3` sau khi pull trước khi bỏ qua bước build).
5. **Phải chạy `php artisan optimize:clear`** trên production sau khi pull để xoá cache config/route/view/compiled cũ (PHP OPcache có thể còn giữ bytecode cũ của `BookingController.php`).
6. Không migration, không seed, không đổi cấu hình `.env`.
7. Sau đó: **manual smoke test** — mở booking có ít nhất 1 `ServiceRate` active (đặc biệt các booking id trước đây gây lỗi 500, ví dụ `/admin/bookings/8` nếu vẫn còn tồn tại trên production), xác nhận trang tải 200, không còn lỗi 500.

## 12. Rollback Plan

Vì đây là hotfix 1 file backend + 1 file test mới, rollback đơn giản:

```bash
git revert <commit-sha-của-hotfix-này>
```

Hoặc nếu cần rollback ngay không qua git (khẩn cấp trên production trước khi có revert commit): khôi phục nguyên đoạn `array_map` gốc trong `BookingController.php` **sẽ đưa lỗi 500 quay lại** nếu `service_rates` có dữ liệu — do đó rollback thực tế không nên dùng khi mục tiêu là giữ trang hoạt động; nếu cần tắt khẩn cấp, ưu tiên vô hiệu hoá tạm phần hiển thị "Thêm phí nhanh" ở frontend hơn là revert fix này.

## 13. Readiness Decision

**READY FOR COMMIT** (đã được ChatGPT review và phê duyệt; chưa deploy production).
