<?php

namespace Database\Factories;

use App\Enums\StayEventType;
use App\Models\Stay;
use App\Models\StayEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StayEvent>
 */
class StayEventFactory extends Factory
{
    protected $model = StayEvent::class;

    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory(),
            'event_type' => StayEventType::CheckIn,
            'actor_id' => User::factory(),
            'occurred_at' => now(),
            'metadata' => [],
        ];
    }
}
