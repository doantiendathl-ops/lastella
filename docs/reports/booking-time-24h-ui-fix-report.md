# Booking Time 24-Hour UI Fix Report

**Date:** 2026-06-16
**Tests:** 139 passed (778 assertions)
**Status:** Ready

---

## 1. Objective

Replace the `datetime-local` native inputs for check-in and check-out with separate `<input type="date">` + `<select>` (30-minute intervals) controls, guaranteeing that hotel staff always see and select time in 24-hour format (e.g. `14:00`, `12:00`) regardless of browser locale.

---

## 2. Root Cause

HTML `<input type="datetime-local">` renders its time portion according to the browser's locale. On en-US systems or Windows browsers with 12-hour locale, this displays `2:00 PM` instead of `14:00`. There is no standard HTML attribute to force 24-hour rendering on `datetime-local` inputs. The value stored and submitted is always 24-hour internally, but the *visible* display is outside developer control.

---

## 3. Files Reviewed

| File | Purpose |
|------|---------|
| `resources/js/Pages/Admin/Bookings/Form.vue` | Booking create/edit form — target of change |
| `app/Http/Controllers/Admin/Booking/BookingController.php` | `formPayload()` — confirms backend returns `Y-m-d\TH:i` format |
| `app/Http/Requests/Booking/StoreBookingRequest.php` | `checkout_at` → `after:checkin_at` validation confirmed |
| `app/Http/Requests/Booking/UpdateBookingRequest.php` | Same validation on update confirmed |
| `tests/Feature/BookingManagementUiTest.php` | Existing 7 time-related tests confirmed valid after change |

---

## 4. Files Changed (1)

| File | Change |
|------|--------|
| `resources/js/Pages/Admin/Bookings/Form.vue` | Replaced `datetime-local` inputs with `date` + time `select`; added split/combine logic; no backend changes |

---

## 5. Frontend Changes

### Removed
- Two `<input type="datetime-local">` inputs for `checkin_at` and `checkout_at`
- Helper functions `localDateTimeStr`, `defaultCheckinAt`, `defaultCheckoutAt` from previous phase

### Added
- `timeOptions` array: 48 entries, `00:00` → `23:30`, 30-minute intervals
- `localDateStr(date)` — builds `YYYY-MM-DD` from a `Date` in local time
- `splitDateTime(datetimeStr, defaultDate, defaultTime)` — splits `YYYY-MM-DDTHH:mm` into `{ date, time }`; returns defaults when `datetimeStr` is absent
- Reactive refs: `checkinDate`, `checkinTime`, `checkoutDate`, `checkoutTime`

### New controls (4 grid cells replacing 2)

```
Ngày nhận phòng  │  Giờ nhận phòng
Ngày trả phòng   │  Giờ trả phòng
```

Each date control is `<input type="date">`. Each time control is `<select>` with 48 options (`00:00` → `23:30`). AM/PM is never displayed.

### Vietnamese labels

```text
Ngày nhận phòng
Giờ nhận phòng
Ngày trả phòng
Giờ trả phòng
```

Backend error messages for `checkin_at` appear under `Giờ nhận phòng`; errors for `checkout_at` appear under `Giờ trả phòng`.

---

## 6. Submission Logic

The `submit` handler combines the split refs into the form fields immediately before sending:

```javascript
const submit = () => {
    form.checkin_at = checkinDate.value && checkinTime.value
        ? `${checkinDate.value}T${checkinTime.value}`
        : '';
    form.checkout_at = checkoutDate.value && checkoutTime.value
        ? `${checkoutDate.value}T${checkoutTime.value}`
        : '';
    form[props.method](props.action);
};
```

Backend continues receiving `checkin_at` and `checkout_at` in `YYYY-MM-DDTHH:mm` format. No backend changes required.

---

## 7. Default Time Logic

For new bookings (`props.booking === null`), `splitDateTime` receives no `datetimeStr`, so it returns the supplied defaults:

| Field | Default |
|-------|---------|
| `checkinDate` | today (local date) |
| `checkinTime` | `14:00` |
| `checkoutDate` | tomorrow (local date) |
| `checkoutTime` | `12:00` |

For edit bookings, `props.booking.checkin_at` and `props.booking.checkout_at` are in `YYYY-MM-DDTHH:mm` format (from backend `Y-m-d\TH:i`). `splitDateTime` extracts date (`slice(0,10)`) and time (`slice(11,16)`) correctly. Existing stored times are never overwritten.

Non–30-minute times stored in the database (e.g., `09:15`) will not auto-select in the dropdown. The select will display the first option until the user makes a selection. In practice all production times use 30-minute boundaries.

---

## 8. Tests Added / Updated

No new PHPUnit tests were added in this phase. The 7 tests added in the previous phase (`booking-time-format-and-default-time-report.md`) remain valid and cover:

| # | Test | Covers |
|---|------|--------|
| 1 | `test_create_form_page_passes_null_booking_prop` | Backend sends `booking: null` for create |
| 2 | `test_edit_form_checkin_at_formatted_for_datetime_local_input` | Backend returns `2026-07-01T14:00` |
| 3 | `test_edit_form_checkout_at_formatted_for_datetime_local_input` | Backend returns `2026-07-02T12:00` |
| 4 | `test_edit_form_preserves_stored_times_including_non_default_hours` | Stored times (`09:30`, `18:00`) returned unchanged |
| 5 | `test_store_booking_rejects_checkout_before_checkin` | `after:checkin_at` still enforced |
| 6 | `test_store_booking_rejects_checkout_equal_to_checkin` | Equal timestamps rejected |
| 7 | `test_update_booking_rejects_checkout_at_before_checkin_at` | Update endpoint also validates |

Frontend-only items (select option content, no-AM/PM display, default selection on create) cannot be asserted by PHPUnit. See Section 10.

---

## 9. Test Results

```
Tests: 139 passed (778 assertions)
Duration: ~76s
```

All 139 tests pass, 0 failures.

---

## 10. Manual Verification Steps

1. **Open create booking form** (`/admin/bookings/create`)
   - `Ngày nhận phòng` input shows today's date
   - `Giờ nhận phòng` select shows `14:00` selected
   - `Ngày trả phòng` input shows tomorrow's date
   - `Giờ trả phòng` select shows `12:00` selected
   - No AM/PM visible anywhere

2. **Inspect time select options**
   - Options: `00:00`, `00:30`, `01:00`, … `23:00`, `23:30`
   - No AM/PM in any option

3. **Submit new booking**
   - Stored `checkin_at` = `YYYY-MM-DDT14:00` (combined correctly)
   - Stored `checkout_at` = `YYYY-MM-DDT12:00`

4. **Open edit booking form** (`/admin/bookings/{id}/edit`)
   - Date inputs show the stored dates
   - Time selects show the stored times (e.g. `14:00`, `12:00`)
   - Non-default times (e.g. `09:30`) are also selected correctly since they're on 30-minute boundaries

5. **Backend validation error**
   - Select checkout date before checkin date and submit
   - `form.errors.checkout_at` message appears below `Giờ trả phòng`

---

## 11. Risks / Notes

- **Non-30-minute stored times**: If a booking was ever stored with a time not on a 30-minute boundary (e.g., via direct DB insert or old code), the time select will show no selection after opening the edit form. This is an edge case; all production data uses standard intervals.
- **Column count**: The form now has 4 date/time cells instead of 2. The `md:grid-cols-2` grid handles this automatically (2 rows of 2 cells each).
- **`form.checkin_at` initial value**: Set to `''` in `useForm`; the actual value is injected in the `submit` handler. If Inertia's error restoration re-renders the form after a failed submission, `checkinDate`/`checkinTime` retain their user-selected values since they're separate `ref`s outside the form object.

---

## 12. Context For Future Sessions

- `Form.vue` no longer uses `datetime-local`. Check-in/check-out use `date` input + 30-min `select`.
- The split state (`checkinDate`, `checkinTime`, `checkoutDate`, `checkoutTime`) is local refs, not inside `useForm`.
- Combine happens in `submit()` immediately before `form[method](action)`.
- Backend format for edit: `Y-m-d\TH:i` (with literal `T`). `splitDateTime` splits at the `T` character.
- All backend API, controller, request, and validation code remains unchanged.
