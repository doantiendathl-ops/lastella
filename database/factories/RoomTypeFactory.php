<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\RoomType>
 */
class RoomTypeFactory extends Factory
{
    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->lexify('TYPE_????'));

        return [
            'code' => $code,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'standard_adults' => 2,
            'max_adults' => fake()->numberBetween(2, 4),
            'free_children' => fake()->numberBetween(0, 2),
        ];
    }
}
