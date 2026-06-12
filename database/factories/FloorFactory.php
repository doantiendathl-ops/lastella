<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Floor>
 */
class FloorFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->unique()->bothify('F##');

        return [
            'code' => $code,
            'name' => "Floor {$code}",
            'sort_order' => fake()->numberBetween(1, 20),
        ];
    }
}
