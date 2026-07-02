<?php

namespace App\Services;

use App\Enums\ChargeType;
use App\Models\ServiceRate;
use Carbon\Carbon;

class ServiceRateService
{
    /**
     * ADR-66: Rate resolution uses business date and selects the most-recently
     * effective active rate for the given charge type.
     */
    public function resolveFor(ChargeType $chargeType, Carbon $businessDate): ?ServiceRate
    {
        return ServiceRate::where('charge_type', $chargeType->value)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $businessDate->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Returns all active rates effective on the given date, grouped by charge_type.
     * Used by the AddChargeForm picker.
     */
    public function activeRatesGrouped(Carbon $businessDate): array
    {
        $rates = ServiceRate::where('is_active', true)
            ->whereDate('effective_from', '<=', $businessDate->toDateString())
            ->orderBy('display_order')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        // Deduplicate: keep only the highest-priority (first seen) row per charge_type
        $seen   = [];
        $result = [];

        foreach ($rates as $rate) {
            if (! in_array($rate->charge_type, $seen, true)) {
                $seen[]                   = $rate->charge_type;
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
}
