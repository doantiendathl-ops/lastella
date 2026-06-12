<?php

namespace Database\Factories;

use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Resource>
 */
class ResourceFactory extends Factory
{
    public function definition(): array
    {
        $code = strtoupper(fake()->unique()->bothify('RES-####'));

        return [
            'type' => ResourceType::Other,
            'code' => $code,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'is_active' => true,
            'metadata' => [],
        ];
    }

    public function room(string $roomNumber = null): static
    {
        return $this->state(function () use ($roomNumber): array {
            $number = $roomNumber ?? fake()->unique()->numberBetween(100, 999);

            return [
                'type' => ResourceType::Room,
                'code' => "RM-{$number}",
                'name' => "Room {$number}",
            ];
        });
    }
}
