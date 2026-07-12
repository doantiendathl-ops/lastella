<?php

namespace App\Services;

use App\Enums\StayEventType;
use App\Models\Stay;
use App\Models\StayEvent;
use App\Models\User;

class StayEventService
{
    public function record(Stay $stay, StayEventType $type, ?User $actor, array $metadata = []): StayEvent
    {
        return StayEvent::create([
            'stay_id' => $stay->id,
            'event_type' => $type,
            'actor_id' => $actor?->id,
            'occurred_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}
