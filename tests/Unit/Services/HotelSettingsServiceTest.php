<?php

namespace Tests\Unit\Services;

use App\Models\HotelSetting;
use App\Models\User;
use App\Services\HotelSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HotelSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private HotelSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HotelSettingsService();
        Cache::flush();
    }

    public function test_get_returns_default_when_key_not_in_database(): void
    {
        $value = $this->service->get('late_checkout_grace_minutes');

        $this->assertEquals('30', $value);
    }

    public function test_get_returns_database_value_when_present(): void
    {
        HotelSetting::create([
            'key'        => 'late_checkout_grace_minutes',
            'value'      => '45',
            'value_type' => 'int',
            'updated_at' => now(),
        ]);

        $value = $this->service->getInt('late_checkout_grace_minutes');

        $this->assertSame(45, $value);
    }

    public function test_set_updates_database_and_invalidates_cache(): void
    {
        $user = User::factory()->create();

        // Prime the cache
        $this->service->get('late_checkout_grace_minutes');

        $this->service->set('late_checkout_grace_minutes', '60', $user->id);

        // Cache should be invalidated — next get reads from DB
        $this->assertSame(60, $this->service->getInt('late_checkout_grace_minutes'));
        $this->assertDatabaseHas('hotel_settings', [
            'key'        => 'late_checkout_grace_minutes',
            'value'      => '60',
            'updated_by' => $user->id,
        ]);
    }

    public function test_get_int_casts_value_to_integer(): void
    {
        HotelSetting::create([
            'key'        => 'business_date_offset_hours',
            'value'      => '8',
            'value_type' => 'int',
            'updated_at' => now(),
        ]);

        $this->assertSame(8, $this->service->getInt('business_date_offset_hours'));
    }

    public function test_get_bool_casts_string_true_correctly(): void
    {
        HotelSetting::create([
            'key'        => 'night_audit_require_sequential',
            'value'      => 'true',
            'value_type' => 'bool',
            'updated_at' => now(),
        ]);

        $this->assertTrue($this->service->getBool('night_audit_require_sequential'));
    }

    public function test_get_bool_casts_string_false_correctly(): void
    {
        HotelSetting::create([
            'key'        => 'night_audit_require_sequential',
            'value'      => 'false',
            'value_type' => 'bool',
            'updated_at' => now(),
        ]);

        $this->assertFalse($this->service->getBool('night_audit_require_sequential'));
    }

    public function test_second_get_call_uses_cache_not_database(): void
    {
        // First call seeds the cache
        $this->service->get('currency_code');

        // Insert a DB row that would change the value — cache should hide it
        HotelSetting::create([
            'key'        => 'currency_code',
            'value'      => 'USD',
            'value_type' => 'string',
            'updated_at' => now(),
        ]);

        // Should still return cached default, not 'USD'
        $this->assertEquals('VND', $this->service->get('currency_code'));
    }

    public function test_set_records_updated_by(): void
    {
        $user = User::factory()->create();

        $this->service->set('currency_precision', '2', $user->id);

        $this->assertDatabaseHas('hotel_settings', [
            'key'        => 'currency_precision',
            'updated_by' => $user->id,
        ]);
    }
}
