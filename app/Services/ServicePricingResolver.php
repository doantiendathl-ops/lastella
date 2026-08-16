<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Service;
use App\Models\ServicePrice;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 5) — the ONE
 * canonical pricing resolver. Every runtime that needs "what does this
 * Service cost today" (enrollment UI, enrollment validation, and the
 * PER_NIGHT/ONE_TIME posting jobs) must call this, never query
 * service_prices directly and never fall back to a second source.
 */
class ServicePricingResolver
{
    public function resolve(Service $service, string $businessDate): ?ServicePrice
    {
        return $service->currentPrice($businessDate);
    }

    public function resolveAmount(Service $service, string $businessDate): ?string
    {
        $price = $this->resolve($service, $businessDate);

        return $price !== null ? (string) $price->unit_price : null;
    }
}
