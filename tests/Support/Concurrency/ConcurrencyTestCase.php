<?php

namespace Tests\Support\Concurrency;

use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Room Demand/Room Board Unification M5 — base class for real-MySQL,
 * real-two-process concurrency tests.
 *
 * Deliberately does NOT use RefreshDatabase: the disposable database
 * `lastella_pms_concurrency_m5` (never `lastella_pms`, never production) is
 * migrated once outside the test run, and every fixture this class creates
 * is prefixed `QA-M5-` so results stay identifiable and are left in place
 * for inspection rather than rolled back — matching the retention approach
 * confirmed with the Product Owner before this harness was built.
 *
 * The main PHPUnit process still boots against SQLite per phpunit.xml (its
 * own DB_CONNECTION/DB_DATABASE env are untouched) — only THIS class's
 * fixture setup repoints Eloquent's `mysql` connection at the disposable
 * database, for creating the shared rows both worker processes will race
 * over. The worker processes themselves connect independently (see
 * ConcurrencyWorkerBootstrap), so this is never "one process, one
 * connection" — the orchestrating PHPUnit process and the two workers are
 * three separate MySQL connections to the same disposable database.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    public const CONCURRENCY_DATABASE = 'lastella_pms_concurrency_m5';

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'mysql']);
        config(['database.connections.mysql.database' => self::CONCURRENCY_DATABASE]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        // RoomAssignmentFactory::definition() eagerly calls
        // `Room::factory()->create()` as a plain PHP statement (not a lazy
        // Factory-instance default) — it creates a real, wasted Room (and a
        // Floor + Resource via the raw Faker-bounded code space) EVERY time
        // `RoomAssignment::factory()->create([...])` runs, even when
        // 'room_id' is immediately overridden. Across many test methods in
        // one un-rolled-back run, this exhausts Floor's ~100-value and
        // Resource's ~900-value Faker-unique() code spaces and causes
        // spurious collisions unrelated to any real concurrency behavior.
        // Truncating these structural tables at the start of every test
        // method keeps each test's own fixtures/evidence fully retained for
        // inspection immediately after IT runs, while guaranteeing the next
        // test method never collides with a previous method's leftovers.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['stays', 'room_assignments', 'booking_requirements', 'folio_entries', 'folios', 'bookings', 'release_batches', 'rooms', 'resources', 'floors', 'room_types'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->seed(RolePermissionSeeder::class);

        $this->actor = User::factory()->create(['name' => 'QA-M5 Concurrency Actor']);
        $this->actor->assignRole('ADMIN');
        $this->actingAs($this->actor);
    }

    protected function makeBooking(array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'booking_code' => 'QA-M5-' . strtoupper(bin2hex(random_bytes(4))),
            'status' => \App\Enums\BookingStatus::PendingAssignment,
        ], $overrides));
    }

    protected function makeRequirement(Booking $booking, RoomType $roomType, int $quantity = 3, array $overrides = []): BookingRequirement
    {
        return BookingRequirement::factory()->create(array_merge([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'quantity' => $quantity,
        ], $overrides));
    }

    /**
     * This class deliberately never uses RefreshDatabase (see class
     * docblock — rows are retained for Product Owner inspection), which
     * means repeated runs against the same disposable database accumulate
     * rows across every previous run. `Faker::unique()` only tracks
     * uniqueness in-memory for the CURRENT process and has no idea what
     * already exists in the database from earlier runs — RoomFactory's
     * `fake()->unique()->numberBetween(100, 999)` (900-value space) and
     * FloorFactory's `fake()->unique()->bothify('F##')` (100-value space)
     * both collide once enough rows accumulate across repeated runs
     * (the exact same class of flakiness already documented for
     * `resources.code` since Milestone 2). A monotonically-incrementing,
     * high-entropy counter sidesteps this entirely for concurrency
     * fixtures, without touching either factory (which stay correct for
     * every other, RefreshDatabase-backed test suite).
     */
    private static int $fixtureCounter = 0;

    private static function nextFixtureSeed(): string
    {
        self::$fixtureCounter++;

        return date('His') . '-' . self::$fixtureCounter . '-' . bin2hex(random_bytes(2));
    }

    protected function makeRoom(RoomType $roomType, array $overrides = []): Room
    {
        $seed = self::nextFixtureSeed();

        // Both the Floor.code and the Resource.code (via Room's resource_id,
        // set by RoomFactory calling Resource::factory()->room($roomNumber)
        // with ITS OWN internal random number — a top-level room_number
        // override alone does NOT reach that nested factory call) must be
        // overridden explicitly, or the underlying resources.code collision
        // persists regardless of what room_number is passed here. Created
        // as standalone rows first (not via ->for()'s nested-factory
        // chaining) so there is no ambiguity about which definition() wins.
        $floor = \App\Models\Floor::factory()->create(['code' => 'QM5F' . $seed, 'name' => 'QM5 Floor ' . $seed]);
        $resource = \App\Models\Resource::factory()->room('QM5-' . $seed)->create();

        return Room::factory()
            ->for($roomType)
            ->create(array_merge([
                'floor_id' => $floor->id,
                'resource_id' => $resource->id,
                'room_number' => 'QM5-' . $seed,
                'status' => \App\Enums\RoomStatus::VacantClean,
            ], $overrides));
    }

    protected function runWorkers(string $caseName, array $workers): array
    {
        $runner = new WorkerProcessRunner($caseName, self::CONCURRENCY_DATABASE);

        try {
            return $runner->run($workers);
        } finally {
            $runner->cleanup();
        }
    }
}
