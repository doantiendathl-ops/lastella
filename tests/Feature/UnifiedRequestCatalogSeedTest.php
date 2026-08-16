<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use Database\Seeders\UnifiedRequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — Slice 3 catalog
 * integrity: exactly 23 non-chargeable request-type Services seeded
 * (24 legacy request types minus the deliberately-excluded `extra_bed`
 * — see UnifiedRequestCatalogSeeder's class docblock), idempotent, and
 * TWIN_TO_DOUBLE specifically is ROOM-scoped (not BOTH like its siblings)
 * as required by RoomOperationsBoardService's bed-join board merge.
 */
class UnifiedRequestCatalogSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UnifiedRequestCatalogSeeder::class);
    }

    public function test_seeds_exactly_twenty_three_non_chargeable_services(): void
    {
        $this->assertDatabaseCount('services', 23);
        $this->assertSame(0, Service::where('is_chargeable', true)->count());
    }

    public function test_extra_bed_is_deliberately_not_seeded_as_a_free_request(): void
    {
        $this->assertDatabaseMissing('services', ['code' => 'EXTRA_BED']);
    }

    public function test_twin_to_double_is_room_scoped_unlike_its_siblings(): void
    {
        $twinToDouble = Service::where('code', 'TWIN_TO_DOUBLE')->firstOrFail();
        $twinKeep = Service::where('code', 'TWIN_KEEP')->firstOrFail();
        $babyCot = Service::where('code', 'BABY_COT')->firstOrFail();

        $this->assertSame('ROOM', $twinToDouble->scope->value);
        $this->assertSame('BOTH', $twinKeep->scope->value);
        $this->assertSame('BOTH', $babyCot->scope->value);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(UnifiedRequestCatalogSeeder::class);
        $this->seed(UnifiedRequestCatalogSeeder::class);

        $this->assertDatabaseCount('services', 23);
        $this->assertDatabaseCount('service_categories', 5);
    }

    public function test_all_five_legacy_categories_are_represented(): void
    {
        foreach (['BED_CONFIG', 'EXTRA_ITEM', 'DECORATION', 'ACCESSIBILITY', 'GENERAL_REQUEST'] as $code) {
            $this->assertDatabaseHas('service_categories', ['code' => $code]);
        }
    }
}
