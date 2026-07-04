<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Exceptions\BreakfastAlreadyPostedException;
use App\Exceptions\PackageAlreadyPostedException;
use App\Models\Booking;
use App\Models\BookingPackageFlag;
use App\Models\FolioEntry;
use App\Models\User;

class PackageEnrollmentService
{
    public const BREAKFAST_PER_NIGHT    = 'BREAKFAST_PER_NIGHT';
    public const EXTRA_PERSON_PER_NIGHT = 'EXTRA_PERSON_PER_NIGHT';
    public const EXTRA_BED_PER_NIGHT    = 'EXTRA_BED_PER_NIGHT';

    public const ALLOWED_PACKAGES = [
        self::BREAKFAST_PER_NIGHT,
        self::EXTRA_PERSON_PER_NIGHT,
        self::EXTRA_BED_PER_NIGHT,
    ];

    public function __construct(
        private readonly BusinessDateService $businessDateService,
    ) {}

    public function enroll(Booking $booking, string $packageKey, ?User $enrolledBy = null, int $quantity = 1): BookingPackageFlag
    {
        $quantity = max(1, $quantity);

        return BookingPackageFlag::updateOrCreate(
            [
                'booking_id'  => $booking->id,
                'package_key' => $packageKey,
            ],
            [
                'value'      => (string) $quantity,
                'created_by' => $enrolledBy?->id,
            ]
        );
    }

    public function unenroll(Booking $booking, string $packageKey): void
    {
        match ($packageKey) {
            self::BREAKFAST_PER_NIGHT    => $this->guardBreakfastAlreadyPosted($booking),
            self::EXTRA_PERSON_PER_NIGHT => $this->guardAlreadyPostedByChargeType($booking, ChargeType::ExtraPerson),
            self::EXTRA_BED_PER_NIGHT    => $this->guardAlreadyPostedByChargeType($booking, ChargeType::ExtraBed),
            default                       => null,
        };

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

    public function getEnrollmentSummary(Booking $booking): array
    {
        $flags = BookingPackageFlag::where('booking_id', $booking->id)
            ->whereIn('package_key', self::ALLOWED_PACKAGES)
            ->with('createdBy')
            ->get()
            ->keyBy('package_key');

        $result = [];
        foreach (self::ALLOWED_PACKAGES as $packageKey) {
            $flag            = $flags->get($packageKey);
            $result[$packageKey] = [
                'enrolled'    => $flag !== null,
                'quantity'    => $flag !== null ? max(1, intval($flag->value)) : 1,
                'enrolled_at' => $flag?->created_at?->format('d/m/Y H:i'),
                'enrolled_by' => $flag?->createdBy?->name,
            ];
        }

        return $result;
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

    private function guardAlreadyPostedByChargeType(Booking $booking, ChargeType $chargeType): void
    {
        $folio = $booking->folio;
        if ($folio === null) {
            return;
        }

        $businessDate = $this->businessDateService->currentBusinessDate()->toDateString();

        $hasPosted = FolioEntry::where('folio_id', $folio->id)
            ->where('charge_type', $chargeType->value)
            ->where('posting_source', 'NIGHT_AUDIT')
            ->whereDate('entry_date', $businessDate)
            ->whereNull('voided_at')
            ->exists();

        if ($hasPosted) {
            throw new PackageAlreadyPostedException(
                'Không thể bỏ gói vì đã được ghi phí cho đêm hiện tại.'
            );
        }
    }
}
