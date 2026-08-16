<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceScope;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\ServicePricingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 5/26) — the ONE
 * canonical pricing resolver. Mirrors ServicePackage::currentRate() test
 * coverage (effective-date resolution, future price not used early, old
 * price retained forever).
 */
class ServicePricingResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): Service
    {
        $category = ServiceCategory::create(['code' => 'TEST_CAT', 'name' => 'Test Category', 'is_active' => true]);

        return Service::create([
            'category_id' => $category->id,
            'code' => 'TEST_SERVICE',
            'name' => 'Test Service',
            'is_chargeable' => true,
            'scope' => ServiceScope::Booking->value,
            'billing_mode' => ServiceBillingMode::OneTime->value,
            'quantity_enabled' => false,
            'default_quantity' => 1,
            'unit_label' => 'lần',
            'fulfillment_required' => false,
            'is_active' => true,
            'is_bookable' => true,
        ]);
    }

    public function test_resolves_the_currently_effective_price(): void
    {
        $service = $this->makeService();
        $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-08-01', 'is_active' => true]);

        $resolver = new ServicePricingResolver();
        $price = $resolver->resolve($service, '2026-08-16');

        $this->assertNotNull($price);
        $this->assertSame('100000.00', (string) $price->unit_price);
    }

    public function test_future_price_is_not_used_before_its_effective_date(): void
    {
        $service = $this->makeService();
        $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-08-01', 'is_active' => true]);
        $service->prices()->create(['unit_price' => 999999, 'effective_from' => '2099-01-01', 'is_active' => true]);

        $resolver = new ServicePricingResolver();
        $price = $resolver->resolve($service, '2026-08-16');

        $this->assertSame('100000.00', (string) $price->unit_price);
    }

    public function test_old_price_row_stays_untouched_when_a_new_price_is_added(): void
    {
        $service = $this->makeService();
        $old = $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-08-01', 'is_active' => true]);
        $service->prices()->create(['unit_price' => 180000, 'effective_from' => '2026-08-20', 'is_active' => true]);

        $old->refresh();
        $this->assertSame('100000.00', (string) $old->unit_price);
        $this->assertSame('2026-08-01', $old->effective_from->toDateString());
    }

    public function test_inactive_price_row_is_never_resolved(): void
    {
        $service = $this->makeService();
        $service->prices()->create(['unit_price' => 100000, 'effective_from' => '2026-08-01', 'is_active' => false]);

        $resolver = new ServicePricingResolver();
        $price = $resolver->resolve($service, '2026-08-16');

        $this->assertNull($price);
    }

    public function test_resolveAmount_returns_null_when_no_price_configured(): void
    {
        $service = $this->makeService();
        $resolver = new ServicePricingResolver();

        $this->assertNull($resolver->resolveAmount($service, '2026-08-16'));
    }
}
