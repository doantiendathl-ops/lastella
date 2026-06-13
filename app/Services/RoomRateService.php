<?php

namespace App\Services;

use App\Enums\BookingType;
use App\Enums\RateStatus;
use App\Models\RoomRate;
use App\Repositories\Eloquent\RoomRateRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class RoomRateService extends CrudService
{
    public function __construct(RoomRateRepository $repository)
    {
        parent::__construct($repository);
    }

    public function suggestedPrice(int $roomTypeId, BookingType|string|null $bookingType, CarbonInterface|string|null $checkinAt): ?float
    {
        if ($bookingType === null || $checkinAt === null) {
            return null;
        }

        $bookingType = $bookingType instanceof BookingType ? $bookingType : BookingType::tryFrom($bookingType);

        if ($bookingType === null) {
            return null;
        }

        $checkinDate = $checkinAt instanceof CarbonInterface
            ? $checkinAt->toDateString()
            : Carbon::parse($checkinAt)->toDateString();

        $rate = RoomRate::query()
            ->where('room_type_id', $roomTypeId)
            ->where('status', RateStatus::Active->value)
            ->whereDate('valid_from', '<=', $checkinDate)
            ->where(function ($query) use ($checkinDate): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $checkinDate);
            })
            ->orderByDesc('valid_from')
            ->first();

        if ($rate === null) {
            return null;
        }

        $column = match ($bookingType) {
            BookingType::Hourly, BookingType::DayUse => 'hourly_price',
            BookingType::EarlyCheckin => 'early_checkin_price',
            BookingType::LateCheckout => 'late_checkout_price',
            BookingType::Overnight => 'overnight_price',
        };

        return (float) $rate->{$column};
    }
}
