# Booking Time Format and Default Time Report

**Date:** 2026-06-16
**Tests:** 139 passed (778 assertions)
**Status:** Ready

---

## 1. Requirements Implemented

### Requirement 1 — 24-Hour Format for Check-in/Check-out Display

All date/time values sent from the backend use `Y-m-d H:i` (24-hour) format in list and detail views, and `Y-m-d\TH:i` for form inputs (required by `datetime-local` HTML spec). No AM/PM is present in any backend-formatted string. No backend changes were needed — the format was already correct.

### Requirement 2 — Default Check-in Time: 14:00

For new bookings (`props.booking === null`), `checkin_at` defaults to today at 14:00 in the user's local timezone, computed by `defaultCheckinAt()` in `Form.vue`.

### Requirement 3 — Default Check-out Time: 12:00

For new bookings, `checkout_at` defaults to tomorrow at 12:00 in the user's local timezone, computed by `defaultCheckoutAt()` in `Form.vue`.

### Requirement 4 — Editing Keeps Existing Stored Times

When editing (`props.booking !== null`), `checkin_at` and `checkout_at` use `props.booking.checkin_at` and `props.booking.checkout_at` respectively (no override). The backend `formPayload()` returns these formatted as `Y-m-d\TH:i` which is exactly what `datetime-local` inputs expect.

### Requirement 5 — Backend `checkout_at > checkin_at` Validation Remains

`StoreBookingRequest` and `UpdateBookingRequest` both retain `'checkout_at' => ['required', 'date', 'after:checkin_at']`. Three new tests verify this enforcement:
- checkout before checkin → `checkout_at` validation error
- checkout equal to checkin → `checkout_at` validation error (strictly after, not after_or_equal)
- update with checkout before checkin → `checkout_at` validation error

---

## 2. Technical Implementation

### `Form.vue` — Default Time Helpers

Three helper functions added between `defineProps` and `useForm` in `<script setup>`:

```javascript
const localDateTimeStr = (date) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const defaultCheckinAt = () => {
    const d = new Date();
    d.setHours(14, 0, 0, 0);
    return localDateTimeStr(d);
};

const defaultCheckoutAt = () => {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    d.setHours(12, 0, 0, 0);
    return localDateTimeStr(d);
};
```

`localDateTimeStr` builds the `YYYY-MM-DDTHH:mm` string from local time (not UTC), ensuring the default times match the hotel's local timezone rather than the server's UTC offset.

Updated form defaults:

```javascript
checkin_at: props.booking?.checkin_at ?? defaultCheckinAt(),
checkout_at: props.booking?.checkout_at ?? defaultCheckoutAt(),
```

---

## 3. Files Changed (2)

| File | Change |
|------|--------|
| `resources/js/Pages/Admin/Bookings/Form.vue` | Added `localDateTimeStr`, `defaultCheckinAt`, `defaultCheckoutAt` helpers; updated `checkin_at` and `checkout_at` form defaults |
| `tests/Feature/BookingManagementUiTest.php` | Added 7 new test methods |

---

## 4. Tests

**7 new test methods added:**

| # | Test | Result |
|---|------|--------|
| 1 | `test_create_form_page_passes_null_booking_prop` | ✓ |
| 2 | `test_edit_form_checkin_at_formatted_for_datetime_local_input` | ✓ |
| 3 | `test_edit_form_checkout_at_formatted_for_datetime_local_input` | ✓ |
| 4 | `test_edit_form_preserves_stored_times_including_non_default_hours` | ✓ |
| 5 | `test_store_booking_rejects_checkout_before_checkin` | ✓ |
| 6 | `test_store_booking_rejects_checkout_equal_to_checkin` | ✓ |
| 7 | `test_update_booking_rejects_checkout_at_before_checkin_at` | ✓ |

---

## 5. Test Results

```
Tests: 139 passed (778 assertions)
Duration: ~75s
```

All 139 tests pass, 0 failures.

---

## 6. Notes

- Default times are purely frontend — no backend changes were needed for the defaults.
- The backend already formats all datetime values in 24-hour format (`Y-m-d H:i` for display, `Y-m-d\TH:i` for form inputs).
- `localDateTimeStr` uses JavaScript's `Date` local methods (`getFullYear`, `getMonth`, etc.) rather than `toISOString()` to avoid UTC offset issues on the client.
- The `datetime-local` input type stores and submits its value in `YYYY-MM-DDTHH:mm` format (always 24-hour); AM/PM display depends on browser locale but does not affect the submitted value.
