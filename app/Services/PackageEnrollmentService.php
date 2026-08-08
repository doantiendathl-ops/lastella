<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Exceptions\BreakfastAlreadyPostedException;
use App\Exceptions\PackageAlreadyPostedException;
use App\Exceptions\PackageNotEnrollableException;
use App\Models\Booking;
use App\Models\BookingPackageFlag;
use App\Models\FolioEntry;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Support\Collection;

class PackageEnrollmentService
{
    public const BREAKFAST_PER_NIGHT    = 'BREAKFAST_PER_NIGHT';
    public const EXTRA_PERSON_PER_NIGHT = 'EXTRA_PERSON_PER_NIGHT';
    public const EXTRA_BED_PER_NIGHT    = 'EXTRA_BED_PER_NIGHT';

    /**
     * Kept for the 3 legacy posting jobs (BreakfastPostingJob,
     * ExtraPersonPostingJob, ExtraBedPostingJob), which still key
     * FolioEntry posting off these exact literal strings via isEnrolled().
     * No longer used to gate which packages can be enrolled — that is now
     * driven entirely by service_packages (see catalogForBooking()).
     */
    public const ALLOWED_PACKAGES = [
        self::BREAKFAST_PER_NIGHT,
        self::EXTRA_PERSON_PER_NIGHT,
        self::EXTRA_BED_PER_NIGHT,
    ];

    public function __construct(
        private readonly BusinessDateService $businessDateService,
    ) {}

    /**
     * The set of packages relevant to this booking's enrollment screen:
     * everything currently open for new enrollment (active + bookable),
     * union'd with any package this booking has already enrolled in —
     * even if that package has since been deactivated. This is what keeps
     * a historical enrollment visible after the catalog changes, per the
     * "existing enrollment must never disappear" requirement.
     */
    public function catalogForBooking(Booking $booking): Collection
    {
        $enrolledCodes = BookingPackageFlag::where('booking_id', $booking->id)
            ->pluck('package_key')
            ->all();

        return ServicePackage::query()
            ->where(function ($query) use ($enrolledCodes): void {
                $query->where('is_active', true)->where('is_bookable', true);

                if ($enrolledCodes !== []) {
                    $query->orWhereIn('code', $enrolledCodes);
                }
            })
            ->orderBy('display_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Server-side re-validation before writing an enrollment — never trusts
     * that the frontend's catalog view is still accurate. Throws when the
     * package does not exist, is not open for new enrollment, or has no
     * effective rate for the current business date.
     */
    public function enroll(Booking $booking, string $packageKey, ?User $enrolledBy = null, int $quantity = 1): BookingPackageFlag
    {
        $package = ServicePackage::where('code', $packageKey)->first();

        if ($package === null) {
            throw new PackageNotEnrollableException('Gói dịch vụ không tồn tại.');
        }

        if (! $package->is_active || ! $package->is_bookable) {
            throw new PackageNotEnrollableException('Gói dịch vụ này hiện không cho phép đăng ký mới.');
        }

        $businessDate = $this->businessDateService->currentBusinessDate()->toDateString();

        if ($package->currentRate($businessDate) === null) {
            throw new PackageNotEnrollableException('Gói dịch vụ chưa có biểu giá — không thể đăng ký.');
        }

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

    /**
     * Single source of truth for "does this package_key already have a
     * dedicated legacy PostingJob". ServicePackagePostingJob (the generic
     * job) must exclude these — they stay on their existing service_rates
     * pricing path, unchanged, to guarantee zero double-posting.
     */
    public static function isLegacyDedicatedPostingPackage(string $packageKey): bool
    {
        return in_array($packageKey, self::ALLOWED_PACKAGES, true);
    }

    public function getEnrollmentSummary(Booking $booking, ?Collection $catalog = null): array
    {
        $flags = BookingPackageFlag::where('booking_id', $booking->id)
            ->with('createdBy')
            ->get()
            ->keyBy('package_key');

        $packages = $catalog ?? $this->catalogForBooking($booking);

        $result = [];
        foreach ($packages as $package) {
            $flag                     = $flags->get($package->code);
            $result[$package->code] = [
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
