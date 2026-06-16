<?php

namespace Database\Factories;

use App\Enums\ChargeType;
use App\Models\Folio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\FolioEntry>
 */
class FolioEntryFactory extends Factory
{
    public function definition(): array
    {
        $quantity  = $this->faker->numberBetween(1, 3);
        $unitPrice = $this->faker->numberBetween(50000, 500000);

        return [
            'folio_id'    => Folio::factory(),
            'charge_type' => ChargeType::Other,
            'description' => $this->faker->sentence(3),
            'quantity'    => $quantity,
            'unit_price'  => $unitPrice,
            'amount'      => $quantity * $unitPrice,
            'entry_date'  => now()->toDateString(),
            'posted_by'   => null,
            'voided_at'   => null,
            'voided_by'   => null,
            'void_reason' => null,
        ];
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes): array => [
            'voided_at'   => now(),
            'void_reason' => 'Nhập sai thông tin',
        ]);
    }
}
