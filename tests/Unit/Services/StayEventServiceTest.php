<?php

namespace Tests\Unit\Services;

use App\Enums\StayEventType;
use App\Models\Stay;
use App\Models\User;
use App\Services\StayEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StayEventServiceTest extends TestCase
{
    use RefreshDatabase;

    private StayEventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StayEventService();
    }

    public function test_record_persists_a_stay_event_with_actor_and_metadata(): void
    {
        $stay = Stay::factory()->create();
        $actor = User::factory()->create();

        $event = $this->service->record($stay, StayEventType::CheckIn, $actor, ['reported_by' => 'Housekeeping']);

        $this->assertDatabaseHas('stay_events', [
            'id' => $event->id,
            'stay_id' => $stay->id,
            'event_type' => StayEventType::CheckIn->value,
            'actor_id' => $actor->id,
        ]);
        $this->assertSame(['reported_by' => 'Housekeeping'], $event->refresh()->metadata);
    }

    public function test_record_defaults_metadata_to_empty_array_when_omitted(): void
    {
        $stay = Stay::factory()->create();
        $actor = User::factory()->create();

        $event = $this->service->record($stay, StayEventType::CheckIn, $actor);

        $this->assertSame([], $event->refresh()->metadata);
        $this->assertNotNull($event->metadata);
    }

    public function test_record_allows_null_actor_for_future_system_triggered_events(): void
    {
        $stay = Stay::factory()->create();

        $event = $this->service->record($stay, StayEventType::CheckIn, null);

        $this->assertNull($event->refresh()->actor_id);
    }

    public function test_record_sets_occurred_at_to_now(): void
    {
        $this->travelTo('2026-07-10 09:30:00');

        $stay = Stay::factory()->create();
        $event = $this->service->record($stay, StayEventType::CheckIn, null);

        $this->assertSame('2026-07-10 09:30:00', $event->refresh()->occurred_at->toDateTimeString());
    }
}
