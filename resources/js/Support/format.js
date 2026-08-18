// User request (2026-08-18 chat): the whole website showed dates as
// YYYY-MM-DD (backend Carbon::format('Y-m-d [H:i]') convention) and money
// amounts with no thousand separator, neither on display nor while typing
// into an amount field. This module is the ONE shared source for both —
// every screen that displays a date/money value or accepts a money value
// should go through here instead of a local ad-hoc formatter, so the whole
// site changes together and never drifts back out of sync.
//
// Native date/datetime pickers (<input type="date">/"datetime-local">) are
// deliberately NOT touched anywhere in this pass — the user's request was
// about "hiển thị" (display), and a picker's on-screen format is controlled
// by the browser/OS locale, not something CSS/JS can override without
// replacing it with a fully custom picker (a much larger, riskier change).

/**
 * "2026-08-06" | "2026-08-06 14:00" | "2026-08-06 14:00:00" | "2026-08-06T14:00:00.000000Z"
 * → "06-08-2026" | "06-08-2026 14:00"
 *
 * Accepts already-formatted backend strings (the common case throughout this
 * app: Carbon::format('Y-m-d') / ('Y-m-d H:i') / toDateString() /
 * toDateTimeString()) as well as raw ISO timestamps. Never throws — an
 * unparseable value is returned unchanged so a display bug is visible
 * instead of silently showing nothing.
 */
export function formatDate(value) {
    if (!value) return value ?? '';

    const str = String(value).trim();
    const match = str.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!match) return str;

    const [, year, month, day, hour, minute] = match;
    const datePart = `${day}-${month}-${year}`;

    return hour !== undefined ? `${datePart} ${hour}:${minute}` : datePart;
}

/**
 * Same as formatDate(), but drops the year for the compact "MM-DD HH:mm"-
 * style hints used in tight spaces (Room Map tile footers) — those never
 * showed a year even before this change, this only fixes the day/month
 * ordering: "08-06 14:00" → "06-08 14:00".
 */
export function formatDateShort(value) {
    if (!value) return value ?? '';

    const str = String(value).trim();
    const match = str.match(/^\d{4}-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!match) return str;

    const [, month, day, hour, minute] = match;

    return hour !== undefined ? `${day}-${month} ${hour}:${minute}` : `${day}-${month}`;
}

/**
 * Money DISPLAY — thousand-separated, no decimals (VND has none in this
 * app), optionally suffixed with "đ". Matches the Intl.NumberFormat('vi-VN')
 * convention already used ad-hoc across the codebase (dot as thousand
 * separator) — this just makes it one shared function instead of N
 * copy-pasted ones.
 */
export function formatMoney(value, { suffix = true } = {}) {
    const amount = Number(value) || 0;
    const formatted = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(amount);

    return suffix ? `${formatted} đ` : formatted;
}

/** "1.234.567" | "1,234,567" | "1234567abc" → 1234567. Empty/non-numeric input → null (not 0 — a cleared field must not silently become a real zero amount). */
export function parseMoneyInput(value) {
    if (value === null || value === undefined || value === '') return null;

    const digitsOnly = String(value).replace(/[^\d]/g, '');

    return digitsOnly === '' ? null : Number(digitsOnly);
}
