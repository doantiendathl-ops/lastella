<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingPackageFlag;
use App\Services\PackageEnrollmentService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingPackageFlag>
 */
class BookingPackageFlagFactory extends Factory
{
    protected $model = BookingPackageFlag::class;

    public function definition(): array
    {
        return [
            'booking_id'  => Booking::factory(),
            'package_key' => PackageEnrollmentService::BREAKFAST_PER_NIGHT,
            'value'       => '1',
            'created_by'  => null,
        ];
    }
}
