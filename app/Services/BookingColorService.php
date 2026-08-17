<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Single canonical source for the Booking Color Palette (Excel-style
 * Theme/Standard colors, docs/Prompt_1.txt mục V) and for the "avoid the
 * same color on overlapping bookings" allocation rule (mục VI).
 *
 * Storage keeps the literal hex value a user chose — never a palette
 * index/ordinal/array position — so re-ordering or extending the palette
 * later can never change an already-saved Booking's color (mục V.6).
 * Comparisons are always done on the normalized (uppercase) value, never
 * on label/name (mục VI — Color Comparison).
 */
class BookingColorService
{
    /**
     * Theme Color base hues (Excel reference: Trắng, Đen, Xám, Navy, Xanh
     * dương, Đỏ, Xanh lá, Tím, Cyan/Teal, Cam). Each renders as a column
     * with SHADE_STEPS lighter/darker variants underneath it, same layout
     * idea as Excel's tint/shade rows (not a pixel-perfect copy).
     */
    private const THEME_BASE_COLORS = [
        'Trắng' => '#FFFFFF',
        'Đen' => '#000000',
        'Xám' => '#808080',
        'Navy' => '#1F3864',
        'Xanh dương' => '#2E75B6',
        'Đỏ' => '#C00000',
        'Xanh lá' => '#548235',
        'Tím' => '#7030A0',
        'Cyan' => '#0E7C86',
        'Cam' => '#C55A11',
    ];

    /**
     * Tint (positive, lighten toward white) / shade (negative, darken
     * toward black) factors applied under each theme base, top-to-bottom.
     */
    private const SHADE_STEPS = [0.8, 0.6, 0.4, -0.25, -0.5];

    /**
     * The tint/shade formula pivots off the base color itself: tinting
     * (lighten) has nowhere left to go on a base that's already white, and
     * shading (darken) has nowhere left to go on a base that's already
     * black — applying SHADE_STEPS to either produces duplicate swatches
     * (found by hands-on visual testing, not just reading the math: pure
     * white's first 3 "tinted" shades all render as #FFFFFF again). Excel's
     * own reference palette shows a genuine neutral grayscale ladder under
     * both the White and Black columns instead, so this is used verbatim
     * for those two rather than running them through shade().
     */
    private const NEUTRAL_SHADE_LADDER = ['#D9D9D9', '#BFBFBF', '#A6A6A6', '#808080', '#404040'];

    /** Standard Colors row — strong, easily distinguishable, one-click hues. */
    private const STANDARD_COLORS = [
        '#C00000', '#FF0000', '#FFC000', '#FFFF00', '#92D050',
        '#00B050', '#00B0F0', '#0070C0', '#002060', '#7030A0',
    ];

    /**
     * Cancelled/No-show bookings never actually occupied a room, so their
     * color never blocks anything. Every other status (including
     * CheckedOut) is left in the pool — a CheckedOut booking's interval is
     * already in the past for any new booking, so it is naturally excluded
     * by the date-overlap test itself. There is no separate "used until
     * checkout" cutoff — that would be the global lock mục VI explicitly
     * forbids.
     */
    private const NON_OCCUPYING_STATUSES = [
        BookingStatus::Cancelled,
        BookingStatus::NoShow,
    ];

    public static function normalize(?string $hex): ?string
    {
        return $hex === null ? null : strtoupper($hex);
    }

    public static function sameColor(?string $a, ?string $b): bool
    {
        return $a !== null && self::normalize($a) === self::normalize($b);
    }

    /** Structured groups for the Excel-style picker UI (Theme Colors block). */
    public function themeGroups(): array
    {
        return collect(self::THEME_BASE_COLORS)
            ->map(fn (string $base, string $label): array => [
                'label' => $label,
                'base' => $base,
                'shades' => in_array($base, ['#FFFFFF', '#000000'], true)
                    ? self::NEUTRAL_SHADE_LADDER
                    : array_map(fn (float $step): string => $this->shade($base, $step), self::SHADE_STEPS),
            ])
            ->values()
            ->all();
    }

    public function standardColors(): array
    {
        return self::STANDARD_COLORS;
    }

    /** Flat, de-duplicated, normalized pool used for auto-allocation suggestions. */
    public function flatPalette(): array
    {
        $themeColors = collect($this->themeGroups())
            ->flatMap(fn (array $group): array => [$group['base'], ...$group['shades']]);

        return $themeColors->concat(self::STANDARD_COLORS)
            ->map(fn (string $color): string => self::normalize($color))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Colors used by bookings whose occupancy interval [checkin, checkout)
     * overlaps the given range. Only overlap matters — a color already
     * used by a booking in a completely different time window is free to
     * reuse (mục VI — Auto Allocation, point 4).
     */
    public function overlappingColors(CarbonInterface $checkinAt, CarbonInterface $checkoutAt, ?int $excludeBookingId = null): Collection
    {
        $query = Booking::query()
            ->whereNotNull('booking_color')
            ->whereNotIn('status', self::NON_OCCUPYING_STATUSES)
            ->where('checkin_at', '<', $checkoutAt)
            ->where('checkout_at', '>', $checkinAt);

        if ($excludeBookingId !== null) {
            $query->whereKeyNot($excludeBookingId);
        }

        return $query->pluck('booking_color')
            ->map(fn (string $color): string => self::normalize($color))
            ->unique()
            ->values();
    }

    /** First palette color not used by any overlapping booking; null if the whole palette is taken. */
    public function suggestColor(CarbonInterface $checkinAt, CarbonInterface $checkoutAt, ?int $excludeBookingId = null): ?string
    {
        $used = $this->overlappingColors($checkinAt, $checkoutAt, $excludeBookingId);

        foreach ($this->flatPalette() as $candidate) {
            if (! $used->contains($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function hasConflict(string $color, CarbonInterface $checkinAt, CarbonInterface $checkoutAt, ?int $excludeBookingId = null): bool
    {
        return $this->overlappingColors($checkinAt, $checkoutAt, $excludeBookingId)->contains(self::normalize($color));
    }

    /** Lighten (factor >= 0, toward white) or darken (factor < 0, toward black) a #RRGGBB color. */
    private function shade(string $hex, float $factor): string
    {
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        if ($factor >= 0) {
            $r += $factor * (255 - $r);
            $g += $factor * (255 - $g);
            $b += $factor * (255 - $b);
        } else {
            $r *= 1 + $factor;
            $g *= 1 + $factor;
            $b *= 1 + $factor;
        }

        $clamp = fn (float $channel): int => max(0, min(255, (int) round($channel)));

        return sprintf('#%02X%02X%02X', $clamp($r), $clamp($g), $clamp($b));
    }
}
