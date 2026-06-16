<?php

namespace Tests\Unit\Policies;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\User;
use App\Policies\FolioEntryPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FolioEntryPolicyTest extends TestCase
{
    use RefreshDatabase;

    private FolioEntryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->policy = new FolioEntryPolicy();
    }

    public function test_admin_can_void_any_entry_including_old_ones(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');

        $entry = FolioEntry::factory()->create();
        DB::table('folio_entries')->where('id', $entry->id)->update(['created_at' => now()->subDays(30)]);
        $entry->refresh();

        $this->assertTrue($this->policy->void($admin, $entry));
    }

    public function test_manager_can_void_todays_entry(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $entry = FolioEntry::factory()->create(['created_at' => now()]);

        $this->assertTrue($this->policy->void($manager, $entry));
    }

    public function test_manager_cannot_void_old_entry(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $entry = FolioEntry::factory()->create();
        DB::table('folio_entries')->where('id', $entry->id)->update(['created_at' => now()->subDay()]);
        $entry->refresh();

        $this->assertFalse($this->policy->void($manager, $entry));
    }

    public function test_reception_cannot_void_entry(): void
    {
        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');

        $entry = FolioEntry::factory()->create(['created_at' => now()]);

        $this->assertFalse($this->policy->void($reception, $entry));
    }

    public function test_cannot_create_charge_on_closed_folio(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $folio = Folio::factory()->closed()->create();

        $this->assertFalse($this->policy->create($manager, $folio));
    }

    public function test_manager_can_create_charge_on_open_folio(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $folio = Folio::factory()->create(['status' => FolioStatus::Open]);

        $this->assertTrue($this->policy->create($manager, $folio));
    }

    public function test_accountant_cannot_create_charge(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');

        $folio = Folio::factory()->create(['status' => FolioStatus::Open]);

        $this->assertFalse($this->policy->create($accountant, $folio));
    }
}
