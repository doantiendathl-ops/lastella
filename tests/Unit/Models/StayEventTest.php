<?php

namespace Tests\Unit\Models;

use App\Enums\StayEventType;
use App\Models\Stay;
use App\Models\StayEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StayEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_stay_event(): void
    {
        $event = StayEvent::factory()->create();

        $this->assertDatabaseHas('stay_events', [
            'id' => $event->id,
            'stay_id' => $event->stay_id,
            'event_type' => StayEventType::CheckIn->value,
        ]);
    }

    public function test_casts_decode_event_type_occurred_at_and_metadata(): void
    {
        $event = StayEvent::factory()->create([
            'event_type' => StayEventType::CheckIn,
            'occurred_at' => '2026-07-10 08:00:00',
            'metadata' => ['note' => 'test'],
        ]);

        $fresh = $event->refresh();

        $this->assertInstanceOf(StayEventType::class, $fresh->event_type);
        $this->assertSame(StayEventType::CheckIn, $fresh->event_type);
        $this->assertInstanceOf(Carbon::class, $fresh->occurred_at);
        $this->assertIsArray($fresh->metadata);
        $this->assertSame(['note' => 'test'], $fresh->metadata);
    }

    public function test_metadata_defaults_to_empty_array_never_null(): void
    {
        $event = new StayEvent();

        $this->assertSame([], $event->metadata);
    }

    public function test_belongs_to_stay(): void
    {
        $stay = Stay::factory()->create();
        $event = StayEvent::factory()->create(['stay_id' => $stay->id]);

        $this->assertTrue($event->stay->is($stay));
    }

    public function test_belongs_to_actor(): void
    {
        $actor = User::factory()->create();
        $event = StayEvent::factory()->create(['actor_id' => $actor->id]);

        $this->assertTrue($event->actor->is($actor));
    }

    public function test_actor_is_nullable(): void
    {
        $event = StayEvent::factory()->create(['actor_id' => null]);

        $this->assertNull($event->actor_id);
        $this->assertNull($event->actor);
    }
}
