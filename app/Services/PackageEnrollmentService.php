<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Exceptions\BreakfastAlreadyPostedException;
use App\Models\Booking;
use App\Models\BookingPackageFlag;
use App\Models\FolioEntry;
use App\Models\User;

class PackageEnrollmentService
{
    public const BREAKFAST_PER_NIGHT = 'BREAKFAST_PER_NIGHT';

    public function __construct(
        private readonly BusinessDateService $businessDateService,
    ) {}

    public function enroll(Booking $booking, string $packageKey, ?User $enrolledBy = null): BookingPackageFlag
    {
        return BookingPackageFlag::firstOrCreate(
            [
                'booking_id'  => $booking->id,
                'package_key' => $packageKey,
            ],
            [
                'value'      => '1',
                'created_by' => $enrolledBy?->id,
            ]
        );
    }

    public function unenroll(Booking $booking, string $packageKey): void
    {
        if ($packageKey === self::BREAKFAST_PER_NIGHT) {
            $this->guardBreakfastAlreadyPosted($booking);
        }

        BookingPackageFlag::where('booking_id', $booking->id)
            ->where('package_key', $packageKey)
            ->delete();
    }

    public function isEnrolled(Booking $booking, string $packageKey): bool
    {
        return BookingPackageFlag::where('booking_id', $booking->id)
            ->where('package_key', $packageKey)
            ->exists();
    }

    private function guardBreakfastAlreadyPosted(Booking $booking): void
    {
        $folio = $booking->folio;
        if ($folio === null) {
            return;
        }

        $businessDate = $this->businessDateService->currentBusinessDate()->toDateString();

        $hasPosted = FolioEntry::where('folio_id', $folio->id)
            ->where('charge_type', ChargeType::FoodBeverage->value)
            ->where('posting_source', 'NIGHT_AUDIT')
            ->whereDate('entry_date', $businessDate)
            ->whereNull('voided_at')
            ->exists();

        if ($hasPosted) {
            throw new BreakfastAlreadyPostedException(
                'Không thể bỏ gói sáng vì đã được ghi phí cho đêm hiện tại.'
            );
        }
    }
}
