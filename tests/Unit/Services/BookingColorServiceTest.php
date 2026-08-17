<?php

namespace Tests\Unit\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingColorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * docs/Prompt_1.txt mục V-VI — Excel-style palette + overlap-based color
 * allocation. Covers acceptance tests #11, #12, #14 and the "Color
 * Comparison" / "Historical Stability" rules.
 */
class BookingColorServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingColorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookingColorService();
    }

    public function test_normalize_uppercases_hex(): void
    {
        $this->assertSame('#FF0000', BookingColorService::normalize('#ff0000'));
        $this->assertNull(BookingColorService::normalize(null));
    }

    public function test_same_color_compares_case_insensitively_not_by_label(): void
    {
        $this->assertTrue(BookingColorService::sameColor('#FF0000', '#ff0000'));
        $this->assertFalse(BookingColorService::sameColor('#FF0000', '#00FF00'));
        $this->assertFalse(BookingColorService::sameColor(null, null));
    }

    public function test_theme_groups_are_stable_and_include_ten_columns(): void
    {
        $groups = $this->service->themeGroups();

        $this->assertCount(10, $groups);
        foreach ($groups as $group) {
            $this->assertArrayHasKey('base', $group);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $group['base']);
            $this->assertCount(5, $group['shades']);
            foreach ($group['shades'] as $shade) {
                $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $shade);
            }
        }
    }

    public function test_flat_palette_is_deduplicated_and_normalized(): void
    {
        $palette = $this->service->flatPalette();

        $this->assertSame($palette, array_unique($palette));
        foreach ($palette as $color) {
            $this->assertSame(strtoupper($color), $color);
        }
    }

    public function test_overlapping_colors_only_considers_bookings_with_intersecting_dates(): void
    {
        Booking::factory()->create([
            'booking_color' => '#FF0000',
            'checkin_at' => Carbon::parse('2026-07-01 14:00:00'),
            'checkout_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);

        $overlapping = $this->service->overlappingColors(
            Carbon::parse('2026-07-01 18:00:00'),
            Carbon::parse('2026-07-03 12:00:00'),
        );
        $this->assertTrue($overlapping->contains('#FF0000'));

        // Acceptance test #12 — non-overlapping window frees the color up again.
        $nonOverlapping = $this->service->overlappingColors(
            Carbon::parse('2026-08-01 14:00:00'),
            Carbon::parse('2026-08-02 12:00:00'),
        );
        $this->assertFalse($nonOverlapping->contains('#FF0000'));
    }

    public function test_overlapping_colors_ignores_cancelled_and_no_show_bookings(): void
    {
        Booking::factory()->create([
            'booking_color' => '#FF0000',
            'status' => BookingStatus::Cancelled,
            'checkin_at' => Carbon::parse('2026-07-01 14:00:00'),
            'checkout_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);
        Booking::factory()->create([
            'booking_color' => '#00B050',
            'status' => BookingStatus::NoShow,
            'checkin_at' => Carbon::parse('2026-07-01 14:00:00'),
            'checkout_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);

        $used = $this->service->overlappingColors(
            Carbon::parse('2026-07-01 14:00:00'),
            Carbon::parse('2026-07-02 12:00:00'),
        );

        $this->assertFalse($used->contains('#FF0000'));
        $this->assertFalse($used->contains('#00B050'));
    }

    /**
     * mục VI — "Không tồn tại global lock: màu đã dùng thì cấm cho tới khi
     * checkout". A CheckedOut booking is still counted while its own
     * interval genuinely overlaps — there is no separate status-based
     * cutoff, only the date-overlap test itself.
     */
    public function test_checked_out_booking_still_counts_while_its_interval_overlaps(): void
    {
        Booking::factory()->create([
            'booking_color' => '#FF0000',
            'status' => BookingStatus::CheckedOut,
            'checkin_at' => Carbon::parse('2026-07-01 14:00:00'),
            'checkout_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);

        $used = $this->service->overlappingColors(
            Carbon::parse('2026-07-01 18:00:00'),
            Carbon::parse('2026-07-03 12:00:00'),
        );

        $this->assertTrue($used->contains('#FF0000'));
    }

    public function test_overlapping_colors_excludes_the_given_booking_itself(): void
    {
        $booking = Booking::factory()->create([
            'booking_color' => '#FF0000',
            'checkin_at' => Carbon::parse('2026-07-01 14:00:00'),
            'checkout_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);

        $used = $this->service->overlappingColors(
            $booking->checkin_at,
            $booking->checkout_at,
            $booking->id,
        );

        $this->assertFalse($used->contains('#FF0000'));
    }

    public function test_suggest_color_skips_colors_used_by_overlapping_bookings(): void
    {
        $first = $this->service->flatPalette()[0];
        Booking::factory()->create([
            'booking_color' => $first,
            'checkin_at' => Carbon::parse('2026-07-01 14:00:00'),
            'checkout_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);

        $suggested = $this->service->suggestColor(
            Carbon::parse('2026-07-01 14:00:00'),
            Carbon::parse('2026-07-02 12:00:00'),
        );

        $this->assertNotNull($suggested);
        $this->assertNotSame($first, $suggested);
    }

    public function test_has_conflict_is_case_insensitive(): void
    {
        Booking::factory()->create([
            'booking_color' => '#FF0000',
            'checkin_at' => Carbon::parse('2026-07-01 14:00:00'),
            'checkout_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);

        $this->assertTrue($this->service->hasConflict(
            '#ff0000',
            Carbon::parse('2026-07-01 14:00:00'),
            Carbon::parse('2026-07-02 12:00:00'),
        ));
    }
}
