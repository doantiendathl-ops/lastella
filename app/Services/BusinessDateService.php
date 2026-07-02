<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class BusinessDateService
{
    public function __construct(private readonly HotelSettingsService $settings)
    {
    }

    public function currentBusinessDate(): Carbon
    {
        return $this->businessDateFor(now());
    }

    public function businessDateFor(CarbonInterface $realTime): Carbon
    {
        $offset = $this->settings->getInt('business_date_offset_hours', 6);

        return Carbon::instance($realTime)->subHours($offset)->startOfDay();
    }
}
