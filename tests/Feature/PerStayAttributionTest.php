<?php

namespace Tests\Feature;

use App\Enums\ChargeType;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Stay;
use App\Models\User;
use App\Services\FolioService;
use App\Enums\FolioStatus;
use Database\Seeders\FloorSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\RoomTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerStayAttributionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Folio $folio;
    private Stay $stay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            FloorSeeder::class,
            RoomTypeSeeder::class,
            RoomSeeder::class,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('ADMIN');

        $stay = Stay::factory()->create();
        $this->stay  = $stay;

        $booking = Booking::find($stay->booking_id);
        $this->folio = Folio::factory()->for($booking)->create(['status' => FolioStatus::Open]);
    }

    public function test_add_charge_stores_stay_id_when_provided(): void
    {
        $service = app(FolioService::class);

        $entry = $service->addCharge($this->folio, [
            'charge_type' => ChargeType::Minibar,
            'description' => 'Bia Heineken',
            'quantity'    => 2,
            'unit_price'  => 45000,
            'entry_date'  => '2026-07-01',
            'stay_id'     => $this->stay->id,
        ]);

        $this->assertEquals($this->stay->id, $entry->stay_id);
        $this->assertEquals('MANUAL', $entry->posting_source);
    }

    public function test_add_charge_defaults_posting_source_to_manual(): void
    {
        $service = app(FolioService::class);

        $entry = $service->addCharge($this->folio, [
            'charge_type' => ChargeType::Minibar,
            'description' => 'Nước suối',
            'quantity'    => 1,
            'unit_price'  => 15000,
            'entry_date'  => '2026-07-01',
        ]);

        $this->assertEquals('MANUAL', $entry->posting_source);
        $this->assertNull($entry->stay_id);
    }

    public function test_add_charge_accepts_system_auto_posting_source(): void
    {
        $service = app(FolioService::class);

        $entry = $service->addCharge($this->folio, [
            'charge_type'    => ChargeType::Room,
            'posting_key'    => 'ROOM_NIGHT_' . $this->stay->id . '_2026-07-01',
            'posting_source' => 'NIGHT_AUDIT',
            'description'    => 'Tiền phòng',
            'quantity'       => 1,
            'unit_price'     => 500000,
            'entry_date'     => '2026-07-01',
            'stay_id'        => $this->stay->id,
        ]);

        $this->assertEquals('NIGHT_AUDIT', $entry->posting_source);
        $this->assertEquals($this->stay->id, $entry->stay_id);
    }

    public function test_http_store_with_valid_stay_id_stores_attribution(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.folio.entries.store', $this->folio->booking_id), [
                'charge_type' => 'MINIBAR',
                'description' => 'Bia lon',
                'quantity'    => 3,
                'unit_price'  => 20000,
                'entry_date'  => '2026-07-01',
                'stay_id'     => $this->stay->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('folio_entries', [
            'stay_id'        => $this->stay->id,
            'posting_source' => 'MANUAL',
            'charge_type'    => 'MINIBAR',
        ]);
    }

    public function test_http_store_with_nonexistent_stay_id_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.folio.entries.store', $this->folio->booking_id), [
                'charge_type' => 'MINIBAR',
                'description' => 'Bia lon',
                'quantity'    => 1,
                'unit_price'  => 20000,
                'entry_date'  => '2026-07-01',
                'stay_id'     => 999999,
            ])
            ->assertSessionHasErrors('stay_id');
    }

    public function test_charge_type_room_rejected_from_http(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.bookings.folio.entries.store', $this->folio->booking_id), [
                'charge_type' => 'ROOM',
                'description' => 'Manual room charge',
                'quantity'    => 1,
                'unit_price'  => 500000,
                'entry_date'  => '2026-07-01',
            ])
            ->assertSessionHasErrors('charge_type');
    }
}
