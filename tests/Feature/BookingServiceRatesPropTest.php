<?php

namespace Tests\Feature;

use App\Enums\BookingType;
use App\Enums\CustomerType;
use App\Models\Booking;
use App\Models\RoomType;
use App\Models\ServiceRate;
use App\Models\User;
use App\Services\BookingService;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingServiceRatesPropTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reception;
    private RoomType $twinType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
        ]);

        $this->admin = tap(User::factory()->create())->assignRole('ADMIN');
        $this->reception = tap(User::factory()->create())->assignRole('RECEPTION');

        $this->twinType = RoomType::where('code', 'TWIN')->firstOrFail();
    }

    public function test_booking_detail_returns_empty_service_rates_when_none_active(): void
    {
        $booking = $this->createBooking();

        $response = $this->actingAs($this->reception)
            ->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Bookings/Show'));

        $this->assertSame([], $response->viewData('page')['props']['serviceRates']);
    }

    public function test_booking_detail_exposes_active_service_rate_fields(): void
    {
        $bed = ServiceRate::create([
            'name' => 'Giường phụ / đêm',
            'charge_type' => 'EXTRA_BED',
            'unit_price' => '150000.00',
            'effective_from' => now()->subDay()->toDateString(),
            'unit_label' => 'giường',
            'is_active' => true,
            'display_order' => 1,
        ]);
        $person = ServiceRate::create([
            'name' => 'Người thêm / đêm',
            'charge_type' => 'EXTRA_PERSON',
            'unit_price' => '200000.00',
            'effective_from' => now()->subDay()->toDateString(),
            'unit_label' => 'người',
            'is_active' => true,
            'display_order' => 2,
        ]);

        $booking = $this->createBooking();

        $response = $this->actingAs($this->reception)
            ->get(route('admin.bookings.show', $booking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Bookings/Show'));

        $serviceRates = collect($response->viewData('page')['props']['serviceRates'])->keyBy('charge_type');

        $this->assertSame(
            [
                'id' => $bed->id,
                'name' => 'Giường phụ / đêm',
                'charge_type' => 'EXTRA_BED',
                'unit_price' => 150000.0,
                'unit_label' => 'giường',
            ],
            $serviceRates->get('EXTRA_BED')
        );

        $this->assertSame(
            [
                'id' => $person->id,
                'name' => 'Người thêm / đêm',
                'charge_type' => 'EXTRA_PERSON',
                'unit_price' => 200000.0,
                'unit_label' => 'người',
            ],
            $serviceRates->get('EXTRA_PERSON')
        );
    }

    public function test_booking_detail_excludes_inactive_service_rate(): void
    {
        ServiceRate::create([
            'name' => 'Giường phụ / đêm',
            'charge_type' => 'EXTRA_BED',
            'unit_price' => '150000.00',
            'effective_from' => now()->subDay()->toDateString(),
            'unit_label' => 'giường',
            'is_active' => false,
            'display_order' => 1,
        ]);

        $booking = $this->createBooking();

        $response = $this->actingAs($this->reception)
            ->get(route('admin.bookings.show', $booking))
            ->assertOk();

        $this->assertSame([], $response->viewData('page')['props']['serviceRates']);
    }

    public function test_booking_detail_excludes_service_rate_not_yet_effective(): void
    {
        ServiceRate::create([
            'name' => 'Giường phụ / đêm',
            'charge_type' => 'EXTRA_BED',
            'unit_price' => '150000.00',
            'effective_from' => now()->addDays(5)->toDateString(),
            'unit_label' => 'giường',
            'is_active' => true,
            'display_order' => 1,
        ]);

        $booking = $this->createBooking();

        $response = $this->actingAs($this->reception)
            ->get(route('admin.bookings.show', $booking))
            ->assertOk();

        $this->assertSame([], $response->viewData('page')['props']['serviceRates']);
    }

    private function createBooking(): Booking
    {
        return app(BookingService::class)->createBooking([
            'booking_color' => '#196251',
            'customer_name' => 'Service Rate Prop Guest',
            'customer_phone' => '0900000009',
            'customer_email' => 'service-rate-prop@example.test',
            'customer_type' => CustomerType::Individual->value,
            'booking_type' => BookingType::Overnight->value,
            'checkin_at' => now()->toDateTimeString(),
            'checkout_at' => now()->addDay()->toDateTimeString(),
            'adults' => 2,
            'children_under_6' => 0,
            'children_over_6' => 0,
            'sales_user_id' => $this->admin->id,
            'requirements' => [
                [
                    'room_type_id' => $this->twinType->id,
                    'quantity' => 1,
                    'adults' => 2,
                    'children_under_6' => 0,
                    'children_over_6' => 0,
                    'room_price' => 800000,
                    'price_source' => 'MANUAL',
                ],
            ],
        ]);
    }
}
