<?php

namespace Tests\Unit\Policies;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\User;
use App\Policies\FolioPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FolioPolicyTest extends TestCase
{
    use RefreshDatabase;

    private FolioPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->policy = new FolioPolicy();
    }

    public function test_admin_can_view_any_folio(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');

        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_manager_can_view_any_folio(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $this->assertTrue($this->policy->viewAny($manager));
    }

    public function test_accountant_can_view_any_folio(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('ACCOUNTANT');

        $this->assertTrue($this->policy->viewAny($accountant));
    }

    public function test_housekeeping_cannot_view_folio(): void
    {
        $housekeeping = User::factory()->create();
        $housekeeping->assignRole('HOUSEKEEPING');

        $this->assertFalse($this->policy->viewAny($housekeeping));
    }

    public function test_manager_can_close_open_folio(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $folio = Folio::factory()->create(['status' => FolioStatus::Open]);

        $this->assertTrue($this->policy->close($manager, $folio));
    }

    public function test_manager_cannot_close_already_closed_folio(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $folio = Folio::factory()->closed()->create();

        $this->assertFalse($this->policy->close($manager, $folio));
    }

    public function test_only_admin_can_reopen_closed_folio(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('ADMIN');

        $folio = Folio::factory()->closed()->create();

        $this->assertTrue($this->policy->reopen($admin, $folio));
    }

    public function test_manager_cannot_reopen_closed_folio(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('MANAGER');

        $folio = Folio::factory()->closed()->create();

        $this->assertFalse($this->policy->reopen($manager, $folio));
    }

    public function test_reception_cannot_close_folio(): void
    {
        $reception = User::factory()->create();
        $reception->assignRole('RECEPTION');

        $folio = Folio::factory()->create(['status' => FolioStatus::Open]);

        $this->assertFalse($this->policy->close($reception, $folio));
    }
}
