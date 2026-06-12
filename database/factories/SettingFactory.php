<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Setting>
 */
class SettingFactory extends Factory
{
    public function definition(): array
    {
        $key = fake()->unique()->slug(2);

        return [
            'key' => $key,
            'value' => ['value' => fake()->word()],
            'type' => 'string',
            'group' => 'system',
            'is_public' => false,
        ];
    }
}
